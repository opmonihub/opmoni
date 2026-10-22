<?php

namespace Tests\Feature;

use App\Erp\ErpCatalogAttributionWriter;
use App\Erp\ErpCommercialCatalog;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Erp\ErpBrand;
use App\Models\Erp\ErpCatalogAttribution;
use App\Models\Erp\ErpCatalogImport;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpContact;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpOfficeDelegation;
use App\Models\Erp\ErpProductVariant;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\ErpUnit;
use App\Models\Erp\Finance\ErpObligation;
use App\Models\Erp\Sales\ErpSale;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ErpCatalogAttributionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_DATABASE') !== ':memory:') {
            throw new \RuntimeException('Attribution feature tests require isolated SQLite memory.');
        }
        parent::setUp();
        config(['access.ingress_key' => 'test-key', 'erp.operating_writes_enabled' => true, 'cache.default' => 'array', 'session.driver' => 'array', 'session.store' => null]);
        $this->withHeaders(['X-Opmoni-Ingress-Key' => 'test-key', 'X-Opmoni-Client-IP' => '127.0.0.1', 'X-Opmoni-Client-Proto' => 'http', 'Origin' => 'http://localhost:3000']);
    }

    private function login(User $user, ErpCompany $company): void
    {
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $csrf)->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $csrf)->postJson('/api/v1/erp/company/switch', ['company_id' => $company->id])->assertOk();
        $this->withHeaders(['X-Company-Id' => (string) $company->id, 'X-CSRF-Token' => $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token')]);
    }

    /** @return array{ErpCompany, User, ErpCompanyMembership} */
    private function owner(bool $ambientOffice = false): array
    {
        $company = $this->company();
        $user = User::factory()->create();
        $membership = ErpCompanyMembership::factory()->create(['erp_company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);
        if ($ambientOffice) {
            AccountMembership::factory()->create(['account_id' => Account::factory()->create()->id, 'user_id' => $user->id, 'role' => 'admin']);
        }
        $this->login($user, $company);

        return [$company, $user, $membership];
    }

    private function company(): ErpCompany
    {
        $company = ErpCompany::factory()->create();
        ErpSubscription::factory()->create(['erp_company_id' => $company->id, 'erp_plan_id' => app(ErpCommercialCatalog::class)->ensureBasicPlan()->id, 'status' => 'active']);

        return $company;
    }

    public function test_direct_lifecycle_records_real_identity_without_ambient_account_or_private_values(): void
    {
        [$company, $user, $membership] = $this->owner(ambientOffice: true);
        $this->assertNotNull($this->getJson('/api/v1/me')->assertOk()->json('current_account.id'));
        $id = $this->postJson('/api/v1/erp/contacts', [
            'name' => 'Private catalog name', 'tax_id' => '39053344705', 'notes' => 'Private note body',
        ])->assertCreated()->json('data.id');

        $this->assertTrue(Schema::hasTable('erp_catalog_attributions'), 'Successful catalog mutations must persist consumed attribution.');
        $first = DB::table('erp_catalog_attributions')->sole();
        $this->assertEqualsCanonicalizing([
            'id', 'erp_company_id', 'actor_user_id', 'access_kind', 'erp_company_membership_id',
            'account_id', 'erp_office_delegation_id', 'action', 'resource_type', 'resource_id',
            'batch_id', 'recorded_at',
        ], Schema::getColumnListing('erp_catalog_attributions'));
        $this->assertSame($company->id, $first->erp_company_id);
        $this->assertSame($user->id, $first->actor_user_id);
        $this->assertSame($membership->id, $first->erp_company_membership_id);
        $this->assertSame('direct', $first->access_kind);
        $this->assertNull($first->account_id);
        $this->assertNull($first->erp_office_delegation_id);
        $this->assertNull($first->batch_id);
        $this->assertSame('contact', $first->resource_type);
        $this->assertSame($id, $first->resource_id);
        $this->assertSame('created', $first->action);
        $this->assertNotNull($first->recorded_at);
        $this->assertStringNotContainsString('Private', json_encode($first));
        $this->assertStringNotContainsString('39053344705', json_encode($first));

        $this->patchJson('/api/v1/erp/contacts/'.$id, ['name' => 'Changed name'])->assertOk();
        $this->deleteJson('/api/v1/erp/contacts/'.$id)->assertOk();
        $this->deleteJson('/api/v1/erp/contacts/'.$id)->assertOk();
        $this->assertSame(['created', 'updated', 'archived'], DB::table('erp_catalog_attributions')->orderBy('id')->pluck('action')->all());
        $this->assertEquals($first, DB::table('erp_catalog_attributions')->where('id', $first->id)->first());
        $membership->delete();
        ErpContact::findOrFail($id)->delete();
        $this->assertEquals($first, DB::table('erp_catalog_attributions')->where('id', $first->id)->first());
    }

    public static function catalogs(): array
    {
        return [
            'unit' => ['units', 'unit', ['short_name' => 'un', 'actual_name' => 'Unidade'], ['actual_name' => 'Unidade nova']],
            'category' => ['categories', 'category', ['name' => 'Categoria'], ['name' => 'Categoria nova']],
            'brand' => ['brands', 'brand', ['name' => 'Marca'], ['name' => 'Marca nova']],
            'price group' => ['price-groups', 'price_group', ['name' => 'Preço', 'percentage_adjustment' => '0.0000'], ['percentage_adjustment' => '1.2500']],
            'customer group' => ['customer-groups', 'customer_group', ['name' => 'Clientes'], ['name' => 'Clientes novos']],
            'discount' => ['discount-rules', 'discount_rule', ['name' => 'Desconto', 'kind' => 'fixed', 'value' => '1.0000'], ['value' => '2.0000']],
            'tax' => ['tax-settings/tax-rates', 'tax_rate', ['name' => 'Taxa', 'rate' => '1.0000', 'metadata' => ['secret' => 'never-copy']], ['rate' => '2.0000']],
            'nature' => ['tax-settings/operation-natures', 'operation_nature', ['cfop' => '5102', 'description' => 'Venda'], ['description' => 'Venda nova']],
            'carrier' => ['carriers', 'carrier', ['legal_name' => 'Transportadora'], ['legal_name' => 'Transportadora nova']],
            'vehicle' => ['vehicles', 'vehicle', ['plate' => 'ABC1D23'], ['color' => 'Azul']],
            'driver' => ['drivers', 'driver', ['name' => 'Motorista'], ['name' => 'Motorista novo']],
            'contact' => ['contacts', 'contact', ['name' => 'Contato'], ['name' => 'Contato novo']],
        ];
    }

    #[DataProvider('catalogs')]
    public function test_each_catalog_records_only_real_changes(string $path, string $type, array $create, array $update): void
    {
        $this->owner();
        $url = '/api/v1/erp/'.$path;
        $id = $this->postJson($url, $create)->assertCreated()->json('data.id');
        $this->travel(5)->seconds();
        $this->patchJson($url.'/'.$id, [])->assertOk();
        $this->patchJson($url.'/'.$id, $update)->assertOk();
        $this->travel(5)->seconds();
        $this->patchJson($url.'/'.$id, $update)->assertOk();
        $this->deleteJson($url.'/'.$id)->assertOk();
        $this->travel(5)->seconds();
        $this->deleteJson($url.'/'.$id)->assertOk();
        $events = DB::table('erp_catalog_attributions')->orderBy('id')->get();
        $this->assertSame(['created', 'updated', 'archived'], $events->pluck('action')->all());
        $this->assertSame([$type, $type, $type], $events->pluck('resource_type')->all());
        $this->assertSame([$id, $id, $id], $events->pluck('resource_id')->all());
    }

    public function test_variants_have_individual_identity_and_variant_only_changes_do_not_invent_parent_events(): void
    {
        [$company] = $this->owner();
        $unit = ErpUnit::factory()->create(['erp_company_id' => $company->id]);
        $data = $this->postJson('/api/v1/erp/products', [
            'name' => 'Produto', 'erp_unit_id' => $unit->id, 'sale_price' => '10.0000',
            'variants' => [['name' => 'Primeira', 'sale_price' => '1.0000'], ['name' => 'Segunda']],
        ])->assertCreated()->json('data');
        $url = '/api/v1/erp/products/'.$data['id'];
        $first = $data['variants'][0]['id'];
        $second = $data['variants'][1]['id'];
        $this->assertSame(['product', 'product_variant', 'product_variant'], DB::table('erp_catalog_attributions')->orderBy('id')->pluck('resource_type')->all());
        $this->travel(5)->seconds();
        $this->patchJson($url, ['sale_price' => '10.0000', 'variants' => [['id' => $first, 'name' => 'Primeira', 'sale_price' => '1.0']]])->assertOk();
        $this->assertDatabaseCount('erp_catalog_attributions', 3);
        $this->patchJson($url, ['variants' => [
            ['id' => $first, 'name' => 'Alterada'],
            ['id' => $second, 'name' => 'Segunda', 'status' => 'archived'],
            ['name' => 'Terceira'],
        ]])->assertOk();
        $events = DB::table('erp_catalog_attributions')->orderBy('id')->get()->slice(3)->values();
        $this->assertSame(['updated', 'archived', 'created'], $events->pluck('action')->all());
        $this->assertSame(['product_variant', 'product_variant', 'product_variant'], $events->pluck('resource_type')->all());
        $this->assertSame([$first, $second], $events->take(2)->pluck('resource_id')->all());
        $this->patchJson($url, ['name' => 'Produto novo'])->assertOk();
        $this->deleteJson($url)->assertOk();
        $this->deleteJson($url)->assertUnprocessable();
        $this->assertDatabaseCount('erp_catalog_attributions', 8);
        $this->assertSame(['updated', 'archived'], DB::table('erp_catalog_attributions')->where('resource_type', 'product')->orderBy('id')->pluck('action')->slice(1)->values()->all());
    }

    public function test_delegation_records_exact_authority_and_preserves_history_after_revocation_and_detach(): void
    {
        $company = $this->company();
        $user = User::factory()->create();
        $office = Account::factory()->create();
        $member = AccountMembership::factory()->create(['account_id' => $office->id, 'user_id' => $user->id, 'role' => 'admin']);
        $delegation = ErpOfficeDelegation::factory()->create(['erp_company_id' => $company->id, 'account_id' => $office->id, 'permission_allowlist' => ['erp.read', 'erp.brands.store']]);
        $this->login($user, $company);
        $id = $this->postJson('/api/v1/erp/brands', ['name' => 'Delegated'])->assertCreated()->json('data.id');
        $event = DB::table('erp_catalog_attributions')->sole();
        $this->assertSame('delegated', $event->access_kind);
        $this->assertSame($company->id, $event->erp_company_id);
        $this->assertSame($user->id, $event->actor_user_id);
        $this->assertSame($office->id, $event->account_id);
        $this->assertSame($delegation->id, $event->erp_office_delegation_id);
        $this->assertNull($event->erp_company_membership_id);
        $this->assertSame($id, $event->resource_id);
        $this->postJson('/api/v1/erp/categories', ['name' => 'Outside exact grant'])->assertForbidden();
        $member->update(['role' => 'user']);
        $this->postJson('/api/v1/erp/brands', ['name' => 'Read only'])->assertForbidden();
        $member->update(['role' => 'admin']);
        $delegation->update(['status' => 'revoked']);
        ErpOfficeDelegation::factory()->create(['erp_company_id' => $company->id, 'account_id' => $office->id, 'permission_allowlist' => ['erp.brands.store']]);
        $this->postJson('/api/v1/erp/brands', ['name' => 'Replacement not selected'])->assertForbidden();
        $member->delete();
        $delegation->delete();
        $this->assertEquals($event, DB::table('erp_catalog_attributions')->sole());
        $this->assertDatabaseCount('erp_brands', 1);
    }

    public function test_wrong_office_and_platform_only_identity_cannot_write_or_attribute(): void
    {
        $company = $this->company();
        $user = User::factory()->create(['is_super_admin' => true]);
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $csrf)->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $this->withHeader('X-CSRF-Token', $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'));
        $this->postJson('/api/v1/erp/company/switch', ['company_id' => $company->id])->assertForbidden();
        $this->postJson('/api/v1/erp/brands', ['name' => 'Platform bypass'], ['X-Company-Id' => (string) $company->id])->assertForbidden();
        $office = Account::factory()->create();
        AccountMembership::factory()->create(['account_id' => $office->id, 'user_id' => $user->id, 'role' => 'admin']);
        ErpOfficeDelegation::factory()->create(['erp_company_id' => $company->id, 'account_id' => Account::factory()->create()->id, 'permission_allowlist' => ['erp.brands.store']]);
        $this->postJson('/api/v1/erp/company/switch', ['company_id' => $company->id])->assertForbidden();
        $this->assertDatabaseCount('erp_brands', 0);
        $this->assertDatabaseCount('erp_catalog_attributions', 0);
    }

    public static function failures(): array
    {
        return ['insert fails' => ['creating', 'exception'], 'insert veto' => ['creating', 'veto'], 'after insertion' => ['created', 'exception'], 'validation after insertion' => ['created', 'validation'], 'final fence' => ['created', 'fence']];
    }

    #[DataProvider('failures')]
    public function test_failed_creation_and_preexisting_update_roll_back_resource_and_event(string $stage, string $failure): void
    {
        [$company] = $this->owner();
        $brand = ErpBrand::factory()->create(['erp_company_id' => $company->id, 'name' => 'Original']);
        $event = 'eloquent.'.$stage.': '.ErpCatalogAttribution::class;
        $seen = 0;
        Event::listen($event, function () use ($failure, &$seen): ?bool {
            $seen++;
            if ($failure === 'veto') {
                return false;
            }
            if ($failure === 'fence') {
                request()->attributes->set('access.session_lock', new class
                {
                    public function isOwnedByCurrentProcess(): bool
                    {
                        return false;
                    }
                });
            } elseif ($failure === 'validation') {
                throw ValidationException::withMessages(['name' => 'Injected validation failure']);
            } else {
                throw new \RuntimeException('Injected attribution failure');
            }

            return null;
        });
        try {
            $status = $failure === 'validation' ? 422 : 503;
            $this->postJson('/api/v1/erp/brands', ['name' => 'New'])->assertStatus($status);
            $this->patchJson('/api/v1/erp/brands/'.$brand->id, ['name' => 'Changed'])->assertStatus($status);
        } finally {
            Event::forget($event);
        }
        $this->assertSame(2, $seen);
        $this->assertDatabaseCount('erp_brands', 1);
        $this->assertSame('Original', $brand->fresh()->name);
        $this->assertDatabaseCount('erp_catalog_attributions', 0);
        $this->postJson('/api/v1/erp/brands', [])->assertUnprocessable();
        $this->patchJson('/api/v1/erp/brands/'.$brand->id, ['name' => null])->assertUnprocessable();
        $this->assertDatabaseCount('erp_catalog_attributions', 0);
    }

    public function test_events_reject_model_updates_and_deletes(): void
    {
        $this->owner();
        $this->postJson('/api/v1/erp/brands', ['name' => 'Immutable'])->assertCreated();
        $event = ErpCatalogAttribution::query()->sole();
        $original = $event->getAttributes();
        foreach (['update', 'delete', 'saveQuietly', 'deleteQuietly'] as $operation) {
            try {
                match ($operation) {
                    'update' => $event->update(['action' => 'archived']),
                    'saveQuietly' => $event->fill(['action' => 'archived'])->saveQuietly(),
                    'deleteQuietly' => $event->deleteQuietly(),
                    default => $event->delete(),
                };
                $this->fail('Attribution must be append-only through Eloquent.');
            } catch (\LogicException) {
                $this->assertSame($original, $event->fresh()->getAttributes());
            }
        }
    }

    public static function arithmeticMutations(): array
    {
        return [
            'increment' => ['increment', ['resource_id', 1, ['action' => 'archived']]],
            'decrement' => ['decrement', ['resource_id', 1, ['action' => 'archived']]],
            'increment quietly' => ['incrementQuietly', ['resource_id', 1, ['action' => 'archived']]],
            'decrement quietly' => ['decrementQuietly', ['resource_id', 1, ['action' => 'archived']]],
            'increment each' => ['incrementEach', [['resource_id' => 1, 'erp_company_membership_id' => 1], ['action' => 'archived']]],
            'decrement each' => ['decrementEach', [['resource_id' => 1, 'erp_company_membership_id' => 1], ['action' => 'archived']]],
            'increment each quietly' => ['incrementEachQuietly', [['resource_id' => 1, 'erp_company_membership_id' => 1], ['action' => 'archived']]],
            'decrement each quietly' => ['decrementEachQuietly', [['resource_id' => 1, 'erp_company_membership_id' => 1], ['action' => 'archived']]],
        ];
    }

    #[DataProvider('arithmeticMutations')]
    public function test_events_reject_instance_arithmetic_without_changing_any_persisted_attribute(string $operation, array $arguments): void
    {
        $this->owner();
        $this->postJson('/api/v1/erp/brands', ['name' => 'Immutable arithmetic'])->assertCreated();
        $event = ErpCatalogAttribution::query()->sole();
        $original = $event->getAttributes();
        $rejected = false;
        try {
            $event->{$operation}(...$arguments);
        } catch (\LogicException) {
            $rejected = true;
        }

        $this->assertSame($original, $event->fresh()->getAttributes(), 'Arithmetic must not rewrite any historical attribution attribute.');
        $this->assertTrue($rejected, 'Instance arithmetic must throw for append-only attributions.');
        $this->assertSame($original, $event->getAttributes(), 'Arithmetic must be rejected before mutating the model instance.');
    }

    public function test_writer_requires_protected_context_and_rejects_foreign_resource_batch_or_action(): void
    {
        [$company, $user, $membership] = $this->owner();
        $context = new CurrentCompany($company, 'direct', $membership, null, null);
        $request = Request::create('/api/v1/erp/brands', 'POST');
        $request->setUserResolver(fn () => $user);
        $request->attributes->set(CurrentCompany::class, $context);
        $brand = ErpBrand::factory()->create(['erp_company_id' => $company->id]);
        try {
            app(ErpCatalogAttributionWriter::class)->record($request, $context, $brand, 'updated');
            $this->fail('A testing transaction is not the protected catalog transaction.');
        } catch (\LogicException) {
            $this->assertDatabaseCount('erp_catalog_attributions', 0);
        }

        $foreign = ErpBrand::factory()->create();
        $foreignVariant = ErpProductVariant::factory()->create();
        $contact = ErpContact::factory()->create(['erp_company_id' => $company->id]);
        $this->post('/api/v1/erp/catalog/import/contacts/preview', [
            'file' => UploadedFile::fake()->createWithContent('private.csv', "name\nPrivate import\n"),
        ], ['Accept' => 'application/json'])->assertOk();
        $ownedBatch = ErpCatalogImport::query()->sole();
        $foreignBatch = $ownedBatch->replicate();
        $foreignBatch->forceFill(['erp_company_id' => $foreign->erp_company_id, 'token_digest' => hash('sha256', 'foreign')])->save();
        $otherActorBatch = $ownedBatch->replicate();
        $otherActorBatch->forceFill(['actor_user_id' => User::factory()->create()->id, 'token_digest' => hash('sha256', 'other-actor')])->save();
        $completedBatch = $ownedBatch->replicate();
        $completedBatch->forceFill(['completed_at' => now(), 'token_digest' => hash('sha256', 'completed')])->save();
        foreach ([
            [$foreign, 'updated', null], [$foreignVariant, 'updated', null],
            [$brand, 'arbitrary', null], [$contact, 'import_created', $foreignBatch],
            [$contact, 'import_created', $otherActorBatch], [$contact, 'import_created', $completedBatch],
            [$brand, 'import_created', $ownedBatch], [$contact, 'created', $ownedBatch],
            [$contact, 'import_created', null], [new ErpBrand, 'created', null], [$company, 'updated', null],
        ] as [$resource, $action, $batch]) {
            $rejected = false;
            $event = 'eloquent.creating: '.ErpBrand::class;
            Event::listen($event, function () use ($resource, $action, $batch, &$rejected): void {
                try {
                    app(ErpCatalogAttributionWriter::class)->record(request(), CurrentCompany::fromRequest(), $resource, $action, $batch);
                } catch (\LogicException $failure) {
                    $rejected = true;
                    throw $failure;
                }
            });
            try {
                $this->postJson('/api/v1/erp/brands', ['name' => 'Invalid writer binding'])->assertServerError();
            } finally {
                Event::forget($event);
            }
            $this->assertTrue($rejected, 'Unsafe resource, action or import binding must fail closed.');
            $this->assertDatabaseCount('erp_catalog_attributions', 0);
        }
    }

    public function test_attributed_catalog_changes_preserve_posted_commercial_and_financial_snapshots(): void
    {
        [$company] = $this->owner();
        $establishment = ErpEstablishment::factory()->create(['erp_company_id' => $company->id]);
        $unit = ErpUnit::factory()->create(['erp_company_id' => $company->id]);
        $contact = $this->postJson('/api/v1/erp/contacts', ['name' => 'Original customer', 'is_customer' => true])->assertCreated()->json('data.id');
        $product = $this->postJson('/api/v1/erp/products', [
            'name' => 'Original service', 'kind' => 'service', 'erp_unit_id' => $unit->id,
            'sale_price' => '10.0000', 'variants' => [['name' => 'Original variant', 'sale_price' => '10.0000']],
        ])->assertCreated()->json('data');
        $saleId = $this->postJson('/api/v1/erp/sales', [
            'erp_establishment_id' => $establishment->id, 'erp_contact_id' => $contact,
            'lines' => [['erp_product_variant_id' => $product['variants'][0]['id'], 'quantity' => '2']],
        ], ['Idempotency-Key' => 'snapshot-draft'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/erp/sales/'.$saleId.'/post', ['draft_revision' => 1], ['Idempotency-Key' => 'snapshot-post'])->assertOk();
        $sale = ErpSale::query()->findOrFail($saleId);
        $snapshot = $sale->commercial_snapshot;
        $line = $sale->lines()->sole()->getAttributes();
        $obligation = ErpObligation::query()->sole()->getAttributes();
        $this->assertSame('20.0000', $sale->total_amount);
        $this->assertSame('Original customer', $snapshot['party']['name']);
        $this->assertSame('Original service', $snapshot['lines'][0]['product_snapshot']['product']['name']);
        $this->patchJson('/api/v1/erp/contacts/'.$contact, ['name' => 'Changed customer'])->assertOk();
        $this->patchJson('/api/v1/erp/products/'.$product['id'], [
            'name' => 'Changed service', 'sale_price' => '99.0000',
            'variants' => [['id' => $product['variants'][0]['id'], 'name' => 'Changed variant', 'sale_price' => '99.0000']],
        ])->assertOk();
        $this->deleteJson('/api/v1/erp/products/'.$product['id'])->assertOk();
        $this->assertSame($snapshot, $sale->fresh()->commercial_snapshot);
        $this->assertSame($line, $sale->lines()->sole()->getAttributes());
        $this->assertSame($obligation, ErpObligation::query()->sole()->getAttributes());
        $this->assertDatabaseCount('erp_catalog_attributions', 7);
    }
}
