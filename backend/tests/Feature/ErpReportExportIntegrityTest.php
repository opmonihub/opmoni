<?php

namespace Tests\Feature;

use App\Erp\ErpCommercialCatalog;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\Reports\ErpReportExport;
use App\Models\Erp\Stock\ErpPurchase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ErpReportExportIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ErpCompany $company;

    private ErpEstablishment $establishment;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config([
            'access.ingress_key' => 'test-key',
            'erp.operating_writes_enabled' => true,
            'erp.release_enabled' => true,
            'cache.default' => 'array',
            'session.driver' => 'array',
            'session.store' => null,
        ]);
        $this->withHeaders([
            'X-Opmoni-Ingress-Key' => 'test-key',
            'X-Opmoni-Client-IP' => '127.0.0.1',
            'X-Opmoni-Client-Proto' => 'http',
            'Origin' => 'http://localhost:3000',
        ]);
        $plan = app(ErpCommercialCatalog::class)->ensureBasicPlan();
        $this->user = User::factory()->create();
        $this->company = ErpCompany::factory()->create(['cnpj' => '30288513000100']);
        ErpCompanyMembership::factory()->create([
            'erp_company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'role' => 'owner',
        ]);
        ErpSubscription::factory()->create([
            'erp_company_id' => $this->company->id,
            'erp_plan_id' => $plan->id,
            'status' => 'active',
        ]);
        $this->establishment = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $this->withHeader('X-CSRF-Token', $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'))
            ->postJson('/api/v1/login', ['email' => $this->user->email, 'password' => 'password'])->assertOk();
        $this->withHeader('X-Company-Id', (string) $this->company->id);
    }

    /** @return array<string, array{string, string}> */
    public static function publicationFailures(): array
    {
        return [
            'false write' => ['false_write', 'export_storage_unavailable'],
            'thrown write' => ['thrown_write', 'export_storage_unavailable'],
            'thrown readback' => ['thrown_read', 'export_storage_unavailable'],
            'missing readback' => ['missing', 'export_artifact_invalid'],
            'truncated readback' => ['truncated', 'export_artifact_invalid'],
            'replaced readback' => ['replaced', 'export_artifact_invalid'],
        ];
    }

    #[DataProvider('publicationFailures')]
    public function test_failed_publication_retains_inspectable_intent_without_downloadable_path(string $failure, string $code): void
    {
        $disk = Storage::disk('local');
        $boundary = Mockery::mock($disk)->makePartial();
        if ($failure === 'false_write') {
            $boundary->shouldReceive('put')->andReturnUsing(function (string $path) use ($disk): bool {
                $pending = ErpReportExport::query()->sole();
                $this->assertSame('pending', $pending->status);
                $this->assertNull($pending->storage_path);
                $disk->put($path, 'partial');

                return false;
            });
        } elseif ($failure === 'thrown_write') {
            $boundary->shouldReceive('put')->andThrow(UnableToWriteFile::atLocation('private-secret-path', 'provider-secret'));
        } elseif ($failure === 'thrown_read') {
            $boundary->shouldReceive('get')->andThrow(UnableToReadFile::fromLocation('private-secret-path', 'provider-secret'));
        } elseif ($failure === 'missing') {
            $boundary->shouldReceive('get')->andReturn(null);
        } elseif ($failure === 'truncated') {
            $boundary->shouldReceive('get')->andReturn('{');
        } else {
            $boundary->shouldReceive('get')->andReturn('{"category":"other"}');
        }
        Storage::set('local', $boundary);

        $response = $this->createExport()->assertStatus(503)->assertJsonPath('code', $code)
            ->assertJsonPath('data.status', 'failed')->assertJsonPath('data.failure_code', $code);
        $export = ErpReportExport::query()->sole();
        $this->assertSame($export->id, $response->json('data.id'));
        $this->assertSame($this->company->id, $export->erp_company_id);
        $this->assertSame($this->user->id, $export->user_id);
        $this->assertSame('2026-10-01T00:00:00+00:00', $export->filters['date_from']);
        $this->assertNotNull($export->expires_at);
        $this->assertNull($export->storage_path);
        $this->assertNull($export->content_sha256);
        $this->assertNull($export->content_bytes);
        $this->assertSame([], $disk->allFiles());
        $this->assertStringNotContainsString('private-secret', $response->getContent());
        $this->assertStringNotContainsString('provider-secret', $response->getContent());
        $response->assertJsonMissingPath('data.storage_path')->assertJsonMissingPath('data.user_id');
        $this->getJson('/api/v1/erp/reports/exports/'.$export->id)->assertStatus(409)
            ->assertJsonPath('data.status', 'failed')->assertJsonPath('data.failure_code', $code);
    }

    /** @return array<string, array{string}> */
    public static function invalidDownloads(): array
    {
        return ['missing' => ['missing'], 'truncated' => ['{'], 'replaced valid JSON' => ['{"category":"other"}'], 'same length replacement' => ['same-length']];
    }

    #[DataProvider('invalidDownloads')]
    public function test_download_rejects_missing_or_changed_snapshot_and_persists_failure(string $contents): void
    {
        $id = $this->createExport()->assertCreated()->json('data.id');
        $export = ErpReportExport::query()->findOrFail($id);
        if ($contents === 'missing') {
            Storage::disk('local')->delete($export->storage_path);
        } else {
            if ($contents === 'same-length') {
                $contents = str_replace('purchases', 'xxxxxxxxx', Storage::disk('local')->get($export->storage_path));
            }
            Storage::disk('local')->put($export->storage_path, $contents);
        }
        $this->getJson('/api/v1/erp/reports/exports/'.$id)->assertStatus(503)
            ->assertJsonPath('code', 'export_artifact_invalid')->assertJsonPath('data.status', 'failed')
            ->assertJsonMissingPath('data.report')->assertJsonMissingPath('data.storage_path');
        $this->assertSame('failed', $export->fresh()->status);
        $this->assertNull($export->fresh()->storage_path);
    }

    public function test_download_requires_recorded_byte_length_even_when_hash_matches(): void
    {
        $id = $this->createExport()->assertCreated()->json('data.id');
        $export = ErpReportExport::query()->findOrFail($id);
        $export->increment('content_bytes');
        $this->getJson('/api/v1/erp/reports/exports/'.$id)->assertStatus(503)
            ->assertJsonPath('code', 'export_artifact_invalid')->assertJsonPath('data.status', 'failed');
    }

    public function test_download_storage_exception_is_sanitized_and_inspectable(): void
    {
        $id = $this->createExport()->assertCreated()->json('data.id');
        $boundary = Mockery::mock(Storage::disk('local'))->makePartial();
        $boundary->shouldReceive('get')->andThrow(UnableToReadFile::fromLocation('private-secret-path', 'provider-secret'));
        Storage::set('local', $boundary);
        $response = $this->getJson('/api/v1/erp/reports/exports/'.$id)->assertStatus(503)
            ->assertJsonPath('code', 'export_storage_unavailable')->assertJsonPath('data.status', 'failed');
        $this->assertStringNotContainsString('provider-secret', $response->getContent());
        $this->assertNull(ErpReportExport::query()->findOrFail($id)->storage_path);
    }

    public function test_legacy_export_requires_regeneration_without_rewriting_row_or_artifact(): void
    {
        $path = 'erp/report-exports/legacy.json';
        Storage::disk('local')->put($path, '{"legacy":true}');
        $export = ErpReportExport::query()->create([
            'erp_company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'report_type' => 'purchases',
            'filters' => [],
            'status' => 'completed',
            'storage_path' => $path,
            'expires_at' => now()->addDay(),
        ]);
        $before = $export->fresh()->getAttributes();
        $this->getJson('/api/v1/erp/reports/exports/'.$export->id)->assertStatus(409)
            ->assertJsonPath('code', 'export_requires_regeneration')->assertJsonPath('data.status', 'completed')
            ->assertJsonMissingPath('data.report')->assertJsonMissingPath('data.storage_path');
        $this->assertSame($before, $export->fresh()->getAttributes());
        $this->assertSame('{"legacy":true}', Storage::disk('local')->get($path));
    }

    public function test_private_export_verifies_exact_bytes_and_remains_a_snapshot(): void
    {
        $root = storage_path('framework/testing/private-report-'.bin2hex(random_bytes(8)));
        $disk = Storage::build(['driver' => 'local', 'root' => $root, 'visibility' => 'private', 'throw' => true]);
        Storage::set('local', $disk);
        try {
            $purchase = ErpPurchase::query()->create([
                'erp_company_id' => $this->company->id,
                'erp_establishment_id' => $this->establishment->id,
                'document_no' => 'snapshot-purchase',
                'status' => 'posted',
                'total_amount' => '55.5000',
                'currency' => 'BRL',
                'posted_at' => '2026-10-10 12:00:00',
            ]);
            $created = $this->createExport()->assertCreated()->assertJsonPath('data.status', 'completed');
            $export = ErpReportExport::query()->findOrFail($created->json('data.id'));
            $bytes = $disk->get($export->storage_path);
            $this->assertSame(strlen($bytes), $export->content_bytes);
            $this->assertSame(hash('sha256', $bytes), $export->content_sha256);
            $this->assertSame('private', $disk->getVisibility($export->storage_path));
            $this->assertSame('55.5000', json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)['totals']['posted_total']);
            $purchase->update(['total_amount' => '99.0000']);
            $download = $this->getJson('/api/v1/erp/reports/exports/'.$export->id)->assertOk()
                ->assertJsonPath('data.report.totals.posted_total', '55.5000');
            $this->assertSame($created->json('data'), $download->json('data.export'));
            $this->assertSame(json_decode($bytes, true, flags: JSON_THROW_ON_ERROR), $download->json('data.report'));
            $created->assertJsonMissingPath('data.storage_path')->assertJsonMissingPath('data.content_sha256');
        } finally {
            $disk->deleteDirectory('/');
            @rmdir($root);
        }
    }

    public function test_download_rechecks_actor_company_membership_release_and_expiry(): void
    {
        $id = $this->createExport()->assertCreated()->json('data.id');
        $export = ErpReportExport::query()->findOrFail($id);
        $path = $export->storage_path;
        $export->update(['user_id' => User::factory()->create()->id]);
        $this->getJson('/api/v1/erp/reports/exports/'.$id)->assertForbidden();
        $export->update(['user_id' => $this->user->id]);
        $other = ErpCompany::factory()->create(['cnpj' => '11222333000181']);
        $export->update(['erp_company_id' => $other->id]);
        $this->getJson('/api/v1/erp/reports/exports/'.$id)->assertNotFound();
        $export->update(['erp_company_id' => $this->company->id]);
        config(['erp.release_enabled' => false]);
        $this->getJson('/api/v1/erp/reports/exports/'.$id)->assertForbidden()->assertJsonPath('code', 'erp_release_disabled');
        config(['erp.release_enabled' => true]);
        $export->update(['expires_at' => now()->subSecond()]);
        $this->getJson('/api/v1/erp/reports/exports/'.$id)->assertStatus(410);
        $export->update(['expires_at' => now()->addDay()]);
        ErpCompanyMembership::query()->where('user_id', $this->user->id)->update(['action_permissions' => ['erp.stock.write']]);
        $this->getJson('/api/v1/erp/reports/exports/'.$id)->assertForbidden();
        ErpCompanyMembership::query()->where('user_id', $this->user->id)->delete();
        $this->getJson('/api/v1/erp/reports/exports/'.$id)->assertForbidden();
        $this->assertSame('completed', $export->fresh()->status);
        Storage::disk('local')->assertExists($path);
    }

    public function test_cleanup_storage_failure_does_not_hide_failed_export_metadata(): void
    {
        $boundary = Mockery::mock(Storage::disk('local'))->makePartial();
        $boundary->shouldReceive('put')->andReturn(false);
        $boundary->shouldReceive('delete')->andThrow(UnableToDeleteFile::atLocation('private-secret-path', 'provider-secret'));
        Storage::set('local', $boundary);
        $this->createExport()->assertStatus(503)->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.failure_code', 'export_storage_unavailable');
        $export = ErpReportExport::query()->sole();
        $this->assertNull($export->storage_path);
        $this->getJson('/api/v1/erp/reports/exports/'.$export->id)->assertStatus(409)->assertJsonPath('data.status', 'failed');
    }

    public function test_matching_integrity_does_not_allow_invalid_json_to_be_downloaded(): void
    {
        $id = $this->createExport()->assertCreated()->json('data.id');
        $export = ErpReportExport::query()->findOrFail($id);
        Storage::disk('local')->put($export->storage_path, '{');
        $export->update(['content_sha256' => hash('sha256', '{'), 'content_bytes' => 1]);
        $this->getJson('/api/v1/erp/reports/exports/'.$id)->assertStatus(503)
            ->assertJsonPath('code', 'export_artifact_invalid')->assertJsonPath('data.status', 'failed');
    }

    public function test_invalid_or_foreign_filters_do_not_create_export_intents(): void
    {
        $foreign = ErpCompany::factory()->create(['cnpj' => '11222333000181']);
        $establishment = ErpEstablishment::factory()->create(['erp_company_id' => $foreign->id]);
        $this->withHeader('X-CSRF-Token', $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'))
            ->postJson('/api/v1/erp/reports/purchases/exports', ['erp_establishment_id' => $establishment->id])->assertStatus(422);
        $this->postJson('/api/v1/erp/reports/purchases/exports', ['date_from' => '2026-10-30', 'date_to' => '2026-10-01'])->assertStatus(422);
        $this->assertDatabaseCount('erp_report_exports', 0);
        Storage::disk('local')->assertDirectoryEmpty('/');
    }

    private function createExport(): TestResponse
    {
        return $this->withHeader('X-CSRF-Token', $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'))
            ->postJson('/api/v1/erp/reports/purchases/exports', ['date_from' => '2026-10-01', 'date_to' => '2026-10-31']);
    }
}
