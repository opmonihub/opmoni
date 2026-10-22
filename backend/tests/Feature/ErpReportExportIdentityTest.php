<?php

namespace Tests\Feature;

use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpOperationReceipt;
use App\Models\Erp\Finance\ErpFinancialAccount;
use App\Models\Erp\Finance\ErpPayment;
use App\Models\Erp\Reports\ErpReportExport;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;

class ErpReportExportIdentityTest extends ErpReportsFilterContractTest
{
    public function test_same_key_replays_original_acknowledgement_without_republishing(): void
    {
        $this->withHeader('Idempotency-Key', 'report-lost-response');
        $first = $this->postJson('/api/v1/erp/reports/purchases/exports', [])->assertCreated();
        $disk = Mockery::mock(Storage::disk('local'))->makePartial();
        $disk->shouldNotReceive('put');
        Storage::set('local', $disk);
        $this->travel(2)->days();
        $this->postJson('/api/v1/erp/reports/purchases/exports', [])->assertCreated()->assertExactJson($first->json());
        $this->getJson('/api/v1/erp/operations/lookup?operation=erp.reports.exports.store')
            ->assertOk()->assertJsonPath('data.state', 'completed')->assertJsonPath('data.result', $first->json('data'));
        $this->assertDatabaseCount('erp_report_exports', 1);
        $this->assertDatabaseCount('erp_operation_receipts', 1);
        $this->getJson('/api/v1/erp/reports/exports/'.$first->json('data.id'))->assertStatus(410);
    }

    public function test_known_failure_replays_503_and_lookup_completed_is_not_artifact_completion(): void
    {
        $disk = Mockery::mock(Storage::disk('local'))->makePartial();
        $disk->shouldReceive('put')->once()->andReturn(false);
        Storage::set('local', $disk);
        $this->withHeader('Idempotency-Key', 'report-failed-response');
        $first = $this->postJson('/api/v1/erp/reports/purchases/exports', [])->assertStatus(503)->assertJsonPath('data.status', 'failed');
        $this->postJson('/api/v1/erp/reports/purchases/exports', [])->assertStatus(503)->assertExactJson($first->json());
        $this->getJson('/api/v1/erp/operations/lookup?operation=erp.reports.exports.store')
            ->assertOk()->assertJsonPath('data.state', 'completed')->assertJsonPath('data.result.status', 'failed');
        $this->assertDatabaseCount('erp_report_exports', 1);
        Storage::fake('local');
        $this->withHeader('Idempotency-Key', 'deliberate-new-generation')
            ->postJson('/api/v1/erp/reports/purchases/exports', [])->assertCreated();
        $this->assertSame('failed', ErpReportExport::query()->findOrFail($first->json('data.id'))->status);
        $this->assertDatabaseCount('erp_report_exports', 2);
    }

    public function test_changed_filters_or_category_conflict_and_unknown_lookup_does_not_publish(): void
    {
        $this->withHeader('Idempotency-Key', 'report-frozen-intent')
            ->postJson('/api/v1/erp/reports/purchases/exports', ['currency' => 'USD'])->assertCreated();
        $this->postJson('/api/v1/erp/reports/purchases/exports', ['currency' => 'BRL'])->assertConflict();
        $this->postJson('/api/v1/erp/reports/sales/exports', ['currency' => 'USD'])->assertConflict();
        $this->withHeader('Idempotency-Key', 'unknown-report-intent')
            ->getJson('/api/v1/erp/operations/lookup?operation=erp.reports.exports.store')->assertNotFound();
        $this->assertDatabaseCount('erp_report_exports', 1);
    }

    public function test_replay_and_lookup_reauthorize_actor_scope_permissions_and_context(): void
    {
        $this->withHeader('Idempotency-Key', 'report-current-access')
            ->postJson('/api/v1/erp/reports/purchases/exports', [])->assertCreated();
        $receipt = ErpOperationReceipt::query()->sole();
        $membership = ErpCompanyMembership::query()->where('user_id', $receipt->actor_user_id)->sole();
        $membership->update(['role' => 'manager', 'action_permissions' => ['*'], 'access_all_establishments' => false]);
        $this->getJson('/api/v1/erp/operations/lookup?operation=erp.reports.exports.store')->assertConflict();
        $this->postJson('/api/v1/erp/reports/purchases/exports', [])->assertConflict();
        $membership->update(['action_permissions' => ['erp.stock.write']]);
        $this->getJson('/api/v1/erp/operations/lookup?operation=erp.reports.exports.store')->assertForbidden();
        $membership->delete();
        $this->postJson('/api/v1/erp/reports/purchases/exports', [])->assertForbidden();
        $this->assertDatabaseCount('erp_report_exports', 1);
    }

    public function test_recovery_cannot_expose_another_actor_or_cross_session_company(): void
    {
        $this->withHeader('Idempotency-Key', 'report-actor-bound')
            ->postJson('/api/v1/erp/reports/purchases/exports', [])->assertCreated();
        $export = ErpReportExport::query()->sole();
        $export->update(['user_id' => User::factory()->create()->id]);
        $this->getJson('/api/v1/erp/operations/lookup?operation=erp.reports.exports.store')->assertForbidden();
        $this->postJson('/api/v1/erp/reports/purchases/exports', [])->assertForbidden();
        $this->withHeader('X-Company-Id', '999999')
            ->getJson('/api/v1/erp/operations/lookup?operation=erp.reports.exports.store')->assertConflict();
        $this->assertDatabaseCount('erp_report_exports', 1);
    }

    public function test_initial_publication_keeps_store_permission_while_recovery_requires_current_read_permission(): void
    {
        ErpCompanyMembership::query()->update(['action_permissions' => ['erp.reports.exports.store']]);
        $this->withHeader('Idempotency-Key', 'report-store-only')
            ->postJson('/api/v1/erp/reports/purchases/exports', [])->assertCreated();
        $this->getJson('/api/v1/erp/operations/lookup?operation=erp.reports.exports.store')->assertForbidden();
        $this->postJson('/api/v1/erp/reports/purchases/exports', [])->assertForbidden();
        $this->assertDatabaseCount('erp_report_exports', 1);
    }

    #[DataProvider('financialReportCategories')]
    public function test_linked_scope_rechecks_existing_explicit_establishment_export_for_get_replay_and_lookup(string $category): void
    {
        $fixture = $this->crossEstablishmentPayment();
        $this->withHeader('Idempotency-Key', 'linked-scope-'.$category);
        $created = $this->postJson('/api/v1/erp/reports/'.$category.'/exports', $fixture['filters'])->assertCreated();
        $created->assertJsonMissingPath('data.filters.authorized_payment_ids');
        $export = ErpReportExport::query()->findOrFail($created->json('data.id'));
        $prefix = $category === 'overview' ? 'data.report.ledgers.financial' : 'data.report.ledgers';
        $this->getJson('/api/v1/erp/reports/exports/'.$export->id)->assertOk()
            ->assertJsonPath($prefix.'.payments.rows.0.id', $fixture['payment']->id);
        $this->postJson('/api/v1/erp/reports/'.$category.'/exports', $fixture['filters'])->assertCreated()->assertExactJson($created->json());
        $this->getJson('/api/v1/erp/operations/lookup?operation=erp.reports.exports.store')->assertOk();
        $original = $export->getAttributes();
        $receipt = ErpOperationReceipt::query()->sole()->getAttributes();
        $bytes = Storage::disk('local')->get($export->storage_path);
        $disk = Mockery::mock(Storage::disk('local'))->makePartial();
        $disk->shouldNotReceive('put');
        Storage::set('local', $disk);
        $fixture['membership']->establishments()->detach($fixture['hidden_establishment_id']);
        $this->getJson('/api/v1/erp/reports/exports/'.$export->id)->assertConflict()
            ->assertJsonPath('code', 'export_requires_regeneration')->assertJsonMissingPath('data.report')
            ->assertJsonMissingPath('data.filters.authorized_payment_ids');
        $this->postJson('/api/v1/erp/reports/'.$category.'/exports', $fixture['filters'])->assertConflict()->assertJsonMissingPath('data');
        $this->getJson('/api/v1/erp/operations/lookup?operation=erp.reports.exports.store')->assertConflict()->assertJsonMissingPath('data');
        $this->assertSame($original, $export->fresh()->getAttributes());
        $this->assertSame($receipt, ErpOperationReceipt::query()->sole()->getAttributes());
        $this->assertSame($bytes, Storage::disk('local')->get($export->storage_path));
        $this->assertDatabaseCount('erp_report_exports', 1);
    }

    public function test_linked_scope_export_checks_dependencies_beyond_bounded_payment_detail_without_a_journal_key(): void
    {
        $membership = ErpCompanyMembership::query()->sole();
        $establishment = ErpEstablishment::query()->where('erp_company_id', $membership->erp_company_id)->sole();
        $second = ErpEstablishment::factory()->create(['erp_company_id' => $membership->erp_company_id]);
        $base = ['erp_company_id' => $membership->erp_company_id, 'erp_establishment_id' => $establishment->id,
            'currency' => 'BRL', 'posted_at' => now(), 'direction' => 'in', 'method' => 'manual', 'status' => 'posted'];
        for ($i = 0; $i < 200; $i++) {
            ErpPayment::create($base + ['amount' => '1.0000']);
        }
        $hiddenAccount = ErpFinancialAccount::create([
            'erp_company_id' => $membership->erp_company_id, 'erp_establishment_id' => $second->id,
            'name' => 'Linked account', 'account_type' => 'bank', 'currency' => 'BRL',
        ]);
        ErpPayment::create($base + ['erp_financial_account_id' => $hiddenAccount->id, 'amount' => '100.0000']);
        $created = $this->postJson('/api/v1/erp/reports/financial/exports', ['erp_establishment_id' => $establishment->id])->assertCreated();
        $this->getJson('/api/v1/erp/reports/exports/'.$created->json('data.id'))->assertOk()
            ->assertJsonPath('data.report.ledgers.payments.row_count', 201)
            ->assertJsonPath('data.report.ledgers.payments.detail_truncated', true)
            ->assertJsonCount(200, 'data.report.ledgers.payments.rows')
            ->assertJsonPath('data.report.totals.payments_posted_total', '300.0000');
        $membership->update(['role' => 'operator', 'action_permissions' => ['erp.read', 'erp.write'], 'access_all_establishments' => false]);
        $membership->establishments()->attach($establishment->id, ['erp_company_id' => $membership->erp_company_id]);
        $this->getJson('/api/v1/erp/reports/exports/'.$created->json('data.id'))->assertConflict()
            ->assertJsonPath('code', 'export_requires_regeneration')->assertJsonMissingPath('data.report');
        $this->assertDatabaseCount('erp_operation_receipts', 0);
    }

    public function test_linked_scope_legacy_financial_export_without_dependency_proof_requires_regeneration(): void
    {
        $fixture = $this->crossEstablishmentPayment();
        $this->withHeader('Idempotency-Key', 'legacy-linked-scope');
        $created = $this->postJson('/api/v1/erp/reports/financial/exports', $fixture['filters'])->assertCreated();
        $export = ErpReportExport::query()->findOrFail($created->json('data.id'));
        $export->update(['filters' => array_diff_key($export->filters, ['authorized_payment_ids' => true])]);
        $this->getJson('/api/v1/erp/reports/exports/'.$export->id)->assertConflict()->assertJsonPath('code', 'export_requires_regeneration');
        $this->postJson('/api/v1/erp/reports/financial/exports', $fixture['filters'])->assertConflict();
        $this->getJson('/api/v1/erp/operations/lookup?operation=erp.reports.exports.store')->assertConflict();
    }
}
