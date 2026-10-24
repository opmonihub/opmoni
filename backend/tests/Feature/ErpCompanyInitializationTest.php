<?php

namespace Tests\Feature;

use App\Erp\ErpCompanyInitializer;
use App\Erp\ErpCompanyProvisioning;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpContact;
use App\Models\Erp\ErpUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class ErpCompanyInitializationTest extends TestCase
{
    use RefreshDatabase;

    public function test_provisioning_persists_native_preferences_and_baseline_without_fiscal_or_identity_assumptions(): void
    {
        $owner = User::factory()->create();
        $company = app(ErpCompanyProvisioning::class)->createCompanyForOwner($owner, [
            'name' => 'Empresa nova', 'cnpj' => '30288513000100',
        ]);

        $expectedSettings = [
            'time_zone' => 'America/Sao_Paulo', 'date_format' => 'd/m/Y', 'time_format' => 24,
            'fy_start_month' => 1, 'accounting_method' => 'fifo', 'stock_overselling_enabled' => false,
        ];
        $this->assertCount(6, $company->operating_settings ?? []);
        foreach ($expectedSettings as $field => $value) {
            $this->assertSame($value, $company->operating_settings[$field], $field);
            $this->assertSame($value, $company->fresh()->operating_settings[$field], $field);
        }

        $state = $company->setupState()->firstOrFail();
        $this->assertSame('br-basic-v1', $state->preset_version);
        $this->assertSame(1, $state->revision);
        $this->assertSame('BRL', $state->currency);
        $unit = $state->defaultUnit;
        $this->assertSame($company->id, $unit->erp_company_id);
        $this->assertSame('UN', $unit->short_name);
        $this->assertSame('Unidade', $unit->actual_name);
        $this->assertFalse($unit->allow_decimal);
        $this->assertNull($unit->base_unit_id);
        $this->assertNull($unit->conversion_factor);
        $this->assertSame('active', $unit->status);

        $customer = $state->defaultCustomer;
        $this->assertSame($company->id, $customer->erp_company_id);
        $this->assertSame('Consumidor final', $customer->name);
        $this->assertTrue($customer->is_customer);
        $this->assertFalse($customer->is_supplier);
        $this->assertSame('active', $customer->status);
        foreach (['tax_id', 'address', 'email', 'phone', 'trade_name', 'notes'] as $field) {
            $this->assertNull($customer->{$field}, $field);
        }

        $this->assertSame(['unit' => 'br-basic-v1.unit.un', 'customer' => 'br-basic-v1.customer.counter-sale', 'sale_scheme' => 'br-basic-v1.sale-scheme', 'sale_layout' => 'br-basic-v1.sale-layout'], $state->preset_owned_keys);
        $this->assertSame('br-basic-v1.unit.un', $unit->native_key);
        $this->assertSame('br-basic-v1.customer.counter-sale', $customer->native_key);
        $this->assertSame(1, User::query()->count());
        $this->assertSame(0, Account::query()->count());
        $this->assertSame(0, AccountMembership::query()->count());
        $this->assertDatabaseCount('erp_tax_rates', 0);
        $this->assertDatabaseCount('erp_operation_natures', 0);
        $this->assertDatabaseCount('erp_fiscal_configurations', 0);
    }

    public function test_existing_owner_establishment_subscription_and_registration_contract_remain_intact(): void
    {
        $owner = User::factory()->create();
        $company = app(ErpCompanyProvisioning::class)->createCompanyForOwner($owner, [
            'name' => 'Empresa nova', 'cnpj' => '30288513000100',
        ]);

        $this->assertDatabaseHas('erp_company_memberships', [
            'erp_company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner',
        ]);
        $this->assertSame(1, $company->memberships()->count());
        $this->assertSame(1, $company->establishments()->count());
        $this->assertSame('Matriz', $company->establishments()->firstOrFail()->name);
        $this->assertSame('pending', $company->subscription->status);
        $this->assertNotNull($company->subscription->plan);
        $this->assertFalse(config('erp.registration_enabled'));
    }

    public function test_rerun_reuses_state_and_preserves_operator_changes_and_archived_baseline(): void
    {
        $company = app(ErpCompanyProvisioning::class)->createCompanyForOwner(User::factory()->create(), [
            'name' => 'Empresa nova', 'cnpj' => '30288513000100',
        ]);
        $state = $company->setupState()->firstOrFail();
        $unitId = $state->default_unit_id;
        $customerId = $state->default_customer_id;
        $state->defaultUnit->update(['actual_name' => 'Unidade personalizada', 'status' => 'archived']);
        $state->defaultCustomer->update(['name' => 'Cliente personalizado', 'status' => 'archived']);
        $settings = ['time_zone' => null, 'time_format' => 0, 'stock_overselling_enabled' => true, 'custom_labels' => ['sale' => '']];
        $company->update(['operating_settings' => $settings]);
        $state->update(['currency' => null, 'default_unit_id' => null, 'revision' => 7]);
        $before = DB::table('erp_company_setup_states')->where('id', $state->id)->first();

        $result = app(ErpCompanyInitializer::class)->initialize($company->fresh());

        $this->assertSame($state->id, $result->id);
        $this->assertEquals($before, DB::table('erp_company_setup_states')->where('id', $state->id)->first());
        $this->assertSame($settings, $company->fresh()->operating_settings);
        $this->assertDatabaseCount('erp_company_setup_states', 1);
        $this->assertDatabaseCount('erp_units', 1);
        $this->assertDatabaseCount('erp_contacts', 1);
        $this->assertDatabaseHas('erp_units', ['id' => $unitId, 'actual_name' => 'Unidade personalizada', 'status' => 'archived']);
        $this->assertDatabaseHas('erp_contacts', ['id' => $customerId, 'name' => 'Cliente personalizado', 'status' => 'archived']);
    }

    public function test_missing_settings_only_are_filled_and_legacy_namespaces_are_preserved(): void
    {
        $existing = [
            'time_zone' => null, 'date_format' => '', 'time_format' => 0,
            'stock_overselling_enabled' => false, 'fy_start_month' => null,
            'currency_id' => 987, 'purchase_currency_id' => 456, 'default_unit' => 123,
            'regime' => null, 'perc_icms_padrao' => '0.0000',
            'pos_settings' => ['nested' => ['null' => null, 'false' => false, 'zero' => 0, 'empty' => '', 'array' => []]],
        ];
        $company = ErpCompany::factory()->create(['operating_settings' => $existing]);

        $state = app(ErpCompanyInitializer::class)->initialize($company);

        $this->assertSame([...$existing, 'accounting_method' => 'fifo'], $company->fresh()->operating_settings);
        $this->assertSame('BRL', $state->currency);
        $this->assertSame(987, $company->fresh()->operating_settings['currency_id']);
    }

    public function test_customer_display_name_is_not_preset_ownership(): void
    {
        $company = ErpCompany::factory()->create();
        $existing = ErpContact::factory()->create(['erp_company_id' => $company->id, 'name' => 'Consumidor final']);

        $state = app(ErpCompanyInitializer::class)->initialize($company);

        $this->assertNotSame($existing->id, $state->default_customer_id);
        $this->assertNull($existing->fresh()->native_key);
        $this->assertDatabaseCount('erp_contacts', 2);
    }

    public function test_unowned_or_customized_un_requires_review_instead_of_adoption_or_overwrite(): void
    {
        $company = ErpCompany::factory()->create(['operating_settings' => ['time_format' => 12]]);
        $unit = ErpUnit::factory()->create([
            'erp_company_id' => $company->id, 'short_name' => 'UN', 'actual_name' => 'Pacote',
            'allow_decimal' => true, 'status' => 'archived',
        ]);

        try {
            app(ErpCompanyInitializer::class)->initialize($company);
            $this->fail('An unowned UN must require an explicit reuse/conflict decision.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('initialization.unit', $exception->errors());
        }

        $this->assertSame(['time_format' => 12], $company->fresh()->operating_settings);
        $this->assertSame('Pacote', $unit->fresh()->actual_name);
        $this->assertSame('archived', $unit->fresh()->status);
        $this->assertNull($unit->fresh()->native_key);
        $this->assertDatabaseCount('erp_company_setup_states', 0);
        $this->assertDatabaseCount('erp_contacts', 0);
    }

    public function test_required_customer_failure_rolls_back_company_and_every_provisioning_effect(): void
    {
        $owner = User::factory()->create();
        $beforeSettings = ErpCompany::factory()->create(['operating_settings' => ['custom' => ['keep' => false]]]);
        $dispatcher = ErpContact::getEventDispatcher();
        ErpContact::setEventDispatcher(clone $dispatcher);
        ErpContact::creating(function (): void {
            throw new RuntimeException('Required customer storage failed.');
        });

        try {
            app(ErpCompanyProvisioning::class)->createCompanyForOwner($owner, [
                'name' => 'Falha atômica', 'cnpj' => '30288513000100',
            ]);
            $this->fail('Provisioning must fail when a required native record cannot be stored.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Required customer storage failed.', $exception->getMessage());
        } finally {
            ErpContact::setEventDispatcher($dispatcher);
        }

        $this->assertDatabaseCount('erp_companies', 1);
        $this->assertSame(['custom' => ['keep' => false]], $beforeSettings->fresh()->operating_settings);
        foreach (['erp_company_memberships', 'erp_establishments', 'erp_subscriptions', 'erp_units', 'erp_contacts', 'erp_company_setup_states'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame(1, User::query()->count());
        $this->assertDatabaseCount('erp_plans', 0);
    }
}
