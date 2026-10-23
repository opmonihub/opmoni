<?php

namespace Tests\Integration;

use App\Erp\ErpCommercialCatalog;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpContact;
use App\Models\Erp\ErpCustomerGroup;
use App\Models\Erp\ErpOfficeDelegation;
use App\Models\Erp\ErpProduct;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\ErpUnit;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ErpTypedCatalogSetupTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('opmoni_test', DB::selectOne('select current_database() as database_name')->database_name);
        config([
            'access.ingress_key' => 'test-key',
            'erp.operating_writes_enabled' => true,
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
    }

    /** @return array{user: User, company: ErpCompany, headers: array{X-Company-Id: string}} */
    private function companyContext(string $role = 'owner'): array
    {
        $plan = app(ErpCommercialCatalog::class)->ensureBasicPlan();
        $user = User::factory()->create();
        $company = $this->newCompany();
        ErpCompanyMembership::factory()->create([
            'erp_company_id' => $company->id,
            'user_id' => $user->id,
            'role' => $role,
        ]);
        ErpSubscription::factory()->active()->create([
            'erp_company_id' => $company->id,
            'erp_plan_id' => $plan->id,
        ]);
        $this->login($user);

        return ['user' => $user, 'company' => $company, 'headers' => ['X-Company-Id' => (string) $company->id]];
    }

    private function newCompany(): ErpCompany
    {
        return ErpCompany::factory()->create([
            'cnpj' => (string) random_int(90000000000000, 99999999999999),
        ]);
    }

    private function csrf(): string
    {
        return $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
    }

    private function login(User $user): void
    {
        $this->withHeader('X-CSRF-Token', $this->csrf())
            ->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();
    }

    public function test_customer_group_defaults_and_contact_reference_keep_archive_history(): void
    {
        $context = $this->companyContext();
        $headers = [...$context['headers'], 'X-CSRF-Token' => $this->csrf()];

        $group = $this->withHeaders($headers)
            ->postJson('/api/v1/erp/customer-groups', ['name' => 'Revenda'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Revenda')
            ->assertJsonPath('data.percentage_adjustment', '0.0000');
        $groupId = $group->json('data.id');
        $foreignCompany = $this->newCompany();
        $foreignGroupId = ErpCustomerGroup::factory()->create([
            'erp_company_id' => $foreignCompany->id,
            'name' => 'Grupo de outra empresa',
        ])->id;

        $this->withHeaders($context['headers'])
            ->getJson('/api/v1/erp/customer-groups/'.$foreignGroupId)
            ->assertNotFound();

        $this->withHeaders($headers)
            ->postJson('/api/v1/erp/customer-groups', ['name' => 'Ajuste inválido', 'percentage_adjustment' => null])
            ->assertUnprocessable();

        $this->withHeaders($headers)
            ->postJson('/api/v1/erp/contacts', [
                'name' => 'Cliente de outra empresa',
                'is_customer' => true,
                'erp_customer_group_id' => $foreignGroupId,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.erp_customer_group_id.0', 'Registro inválido para esta empresa');

        $contact = $this->withHeaders($headers)
            ->postJson('/api/v1/erp/contacts', [
                'name' => 'Cliente de revenda',
                'is_customer' => true,
                'erp_customer_group_id' => $groupId,
            ])
            ->assertCreated()
            ->assertJsonPath('data.erp_customer_group_id', $groupId);
        $contactId = $contact->json('data.id');

        foreach ([$foreignGroupId, PHP_INT_MAX] as $invalidGroupId) {
            $this->withHeaders($headers)
                ->patchJson('/api/v1/erp/contacts/'.$contactId, [
                    'name' => 'Este nome não deve ser salvo',
                    'erp_customer_group_id' => $invalidGroupId,
                ])
                ->assertUnprocessable()
                ->assertJsonPath('errors.erp_customer_group_id.0', 'Registro inválido para esta empresa');
        }
        $this->assertDatabaseHas('erp_contacts', [
            'id' => $contactId,
            'name' => 'Cliente de revenda',
            'erp_customer_group_id' => $groupId,
        ]);

        try {
            DB::transaction(function () use ($context, $foreignGroupId): void {
                DB::table('erp_contacts')->insert([
                    'erp_company_id' => $context['company']->id,
                    'erp_customer_group_id' => $foreignGroupId,
                    'name' => 'Violação direta do vínculo',
                    'is_customer' => true,
                    'is_supplier' => false,
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
            $this->fail('The composite foreign key must reject a group owned by another company.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('erp_contacts_customer_group_company_fk', $exception->getMessage());
        }
        $this->assertSame(1, ErpContact::query()->where('erp_company_id', $context['company']->id)->count());

        $this->withHeaders($headers)
            ->patchJson('/api/v1/erp/contacts/'.$contactId, ['notes' => 'Atualização parcial'])
            ->assertOk()
            ->assertJsonPath('data.erp_customer_group_id', $groupId);

        $this->withHeaders($headers)
            ->deleteJson('/api/v1/erp/customer-groups/'.$groupId)
            ->assertOk()
            ->assertJsonPath('data.status', 'archived');

        $this->withHeaders($headers)
            ->patchJson('/api/v1/erp/contacts/'.$contactId, [
                'name' => 'Referência histórica explícita',
                'erp_customer_group_id' => $groupId,
            ])
            ->assertOk()
            ->assertJsonPath('data.erp_customer_group_id', $groupId);

        $this->withHeaders($headers)
            ->patchJson('/api/v1/erp/contacts/'.$contactId, ['notes' => 'Referência histórica preservada'])
            ->assertOk()
            ->assertJsonPath('data.erp_customer_group_id', $groupId);

        $this->withHeaders($headers)
            ->patchJson('/api/v1/erp/contacts/'.$contactId, ['erp_customer_group_id' => null])
            ->assertOk()
            ->assertJsonPath('data.erp_customer_group_id', null);

        $this->withHeaders($headers)
            ->patchJson('/api/v1/erp/contacts/'.$contactId, ['erp_customer_group_id' => $groupId])
            ->assertUnprocessable();

        $this->assertSame(1, DB::table('erp_customer_groups')->where('erp_company_id', $context['company']->id)->count());
        $this->assertSame(1, ErpContact::query()->where('erp_company_id', $context['company']->id)->count());
    }

    public function test_customer_groups_enforce_active_name_uniqueness_and_allow_reuse_after_archive(): void
    {
        $context = $this->companyContext();
        $headers = [...$context['headers'], 'X-CSRF-Token' => $this->csrf()];
        $first = $this->withHeaders($headers)
            ->postJson('/api/v1/erp/customer-groups', ['name' => 'Atacado', 'percentage_adjustment' => '0'])
            ->assertCreated()
            ->assertJsonPath('data.percentage_adjustment', '0.0000');

        $this->withHeaders($headers)
            ->patchJson('/api/v1/erp/customer-groups/'.$first->json('data.id'), ['name' => 'Atacado'])
            ->assertOk()
            ->assertJsonPath('data.percentage_adjustment', '0.0000');
        $this->withHeaders($headers)
            ->patchJson('/api/v1/erp/customer-groups/'.$first->json('data.id'), ['percentage_adjustment' => null])
            ->assertUnprocessable();

        $this->withHeaders($headers)
            ->postJson('/api/v1/erp/customer-groups', ['name' => 'atacado'])
            ->assertUnprocessable();

        $this->withHeaders($headers)
            ->deleteJson('/api/v1/erp/customer-groups/'.$first->json('data.id'))
            ->assertOk();

        $this->withHeaders($headers)
            ->postJson('/api/v1/erp/customer-groups', ['name' => 'ATACADO'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'ATACADO')
            ->assertJsonPath('data.percentage_adjustment', '0.0000');
    }

    public function test_customer_group_adjustment_accepts_signed_width_boundary_as_text(): void
    {
        $context = $this->companyContext();
        $headers = [...$context['headers'], 'X-CSRF-Token' => $this->csrf()];
        $group = $this->withHeaders($headers)
            ->postJson('/api/v1/erp/customer-groups', [
                'name' => 'Ajuste limite',
                'percentage_adjustment' => '-999999.9999',
            ])
            ->assertCreated()
            ->assertJsonPath('data.percentage_adjustment', '-999999.9999');

        $this->withHeaders($headers)
            ->patchJson('/api/v1/erp/customer-groups/'.$group->json('data.id'), ['percentage_adjustment' => '999999.9999'])
            ->assertOk()
            ->assertJsonPath('data.percentage_adjustment', '999999.9999');

        foreach ([
            ['percentage_adjustment' => '1000000.0000'],
            ['percentage_adjustment' => '-1000000.0000'],
            ['percentage_adjustment' => '0.00001'],
            ['percentage_adjustment' => 1.25],
        ] as $invalidPayload) {
            $this->withHeaders($headers)
                ->patchJson('/api/v1/erp/customer-groups/'.$group->json('data.id'), $invalidPayload)
                ->assertUnprocessable();
        }

        $this->assertSame('999999.9999', DB::table('erp_customer_groups')->where('id', $group->json('data.id'))->value('percentage_adjustment'));
    }

    public function test_product_fiscal_codes_and_rates_are_lossless_and_invalid_patch_is_atomic(): void
    {
        $context = $this->companyContext();
        $unit = ErpUnit::factory()->create(['erp_company_id' => $context['company']->id]);
        $headers = [...$context['headers'], 'X-CSRF-Token' => $this->csrf()];

        $product = $this->withHeaders($headers)
            ->postJson('/api/v1/erp/products', [
                'name' => 'Produto fiscal',
                'erp_unit_id' => $unit->id,
                'ncm' => '00',
                'cest' => '1234567',
                'origin_code' => '0',
                'icms_cst' => '00',
                'icms_csosn' => '000',
                'pis_cst' => '01',
                'cofins_cst' => '02',
                'ipi_cst' => '03',
                'icms_rate' => '0.0001',
                'pis_rate' => '0.0000',
                'cofins_rate' => '1.2300',
                'ipi_rate' => '0.5000',
                'simple_national_credit_rate' => '0.0001',
                'ibs_cbs_cst' => '999',
                'ibs_cbs_classification_code' => '123456',
            ])
            ->assertCreated()
            ->assertJsonPath('data.ncm', '00')
            ->assertJsonPath('data.origin_code', '0')
            ->assertJsonPath('data.icms_cst', '00')
            ->assertJsonPath('data.icms_csosn', '000')
            ->assertJsonPath('data.pis_cst', '01')
            ->assertJsonPath('data.cofins_cst', '02')
            ->assertJsonPath('data.ipi_cst', '03')
            ->assertJsonPath('data.icms_rate', '0.0001')
            ->assertJsonPath('data.pis_rate', '0.0000')
            ->assertJsonPath('data.cofins_rate', '1.2300')
            ->assertJsonPath('data.ibs_cbs_cst', '999')
            ->assertJsonPath('data.ibs_cbs_classification_code', '123456');
        $productId = $product->json('data.id');

        $this->withHeaders($headers)
            ->patchJson('/api/v1/erp/products/'.$productId, ['name' => 'Produto atualizado', 'pis_rate' => null])
            ->assertOk()
            ->assertJsonPath('data.ncm', '00')
            ->assertJsonPath('data.icms_cst', '00')
            ->assertJsonPath('data.icms_rate', '0.0001')
            ->assertJsonPath('data.pis_rate', null);

        $this->withHeaders($headers)
            ->patchJson('/api/v1/erp/products/'.$productId, ['name' => 'Não salvar', 'ncm' => '0'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.ncm.0', 'Informe o NCM com 8 dígitos ou 00.');

        foreach ([
            ['icms_rate' => 1.25],
            ['icms_rate' => '-0.0100'],
            ['icms_rate' => '1000.0000'],
            ['icms_rate' => '1.23456'],
        ] as $invalidRatePayload) {
            $this->withHeaders($headers)
                ->patchJson('/api/v1/erp/products/'.$productId, $invalidRatePayload)
                ->assertUnprocessable();
        }

        $this->assertSame('Produto atualizado', ErpProduct::query()->findOrFail($productId)->name);
        $this->assertSame('00', ErpProduct::query()->findOrFail($productId)->ncm);
    }

    public function test_direct_viewer_and_delegated_user_cannot_write_customer_groups(): void
    {
        $direct = $this->companyContext('viewer');
        $directHeaders = [...$direct['headers'], 'X-CSRF-Token' => $this->csrf()];

        $this->withHeaders($direct['headers'])
            ->getJson('/api/v1/erp/customer-groups')
            ->assertOk();
        $this->withHeaders($directHeaders)
            ->postJson('/api/v1/erp/customer-groups', ['name' => 'Viewer write'])
            ->assertForbidden();

        $office = Account::factory()->create();
        $delegatedUser = User::factory()->create();
        AccountMembership::factory()->create([
            'account_id' => $office->id,
            'user_id' => $delegatedUser->id,
            'role' => 'user',
        ]);
        $company = $this->newCompany();
        $owner = User::factory()->create();
        ErpCompanyMembership::factory()->create([
            'erp_company_id' => $company->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);
        ErpOfficeDelegation::factory()->create([
            'erp_company_id' => $company->id,
            'account_id' => $office->id,
            'authorized_by_user_id' => $owner->id,
            'permission_allowlist' => ['erp.customer-groups.*'],
        ]);

        $this->login($delegatedUser);
        $this->withHeader('X-CSRF-Token', $this->csrf())
            ->postJson('/api/v1/erp/company/switch', ['company_id' => $company->id])
            ->assertOk()
            ->assertJsonPath('current_company.access_kind', 'delegated');

        $delegatedHeaders = ['X-Company-Id' => (string) $company->id];
        $this->withHeaders($delegatedHeaders)
            ->getJson('/api/v1/erp/customer-groups')
            ->assertOk();
        $this->withHeaders([...$delegatedHeaders, 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/customer-groups', ['name' => 'Delegated write'])
            ->assertForbidden();
    }
}
