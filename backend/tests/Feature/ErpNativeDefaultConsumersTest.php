<?php

namespace Tests\Feature;

use App\Erp\ErpCompanyInitializer;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpCompanySetupState;
use App\Models\Erp\ErpContact;
use App\Models\Erp\Sales\ErpSale;
use InvalidArgumentException;
use Tests\Support\ErpCommercialTestCase;

class ErpNativeDefaultConsumersTest extends ErpCommercialTestCase
{
    private function initialize(): ErpCompanySetupState
    {
        return app(ErpCompanyInitializer::class)->initialize($this->company);
    }

    private function body(array $extra = []): array
    {
        return $extra + [
            'erp_establishment_id' => $this->establishment->id,
            'lines' => [['description' => 'Serviço de balcão', 'quantity' => '1', 'unit_price' => '10']],
        ];
    }

    public function test_settings_expose_typed_native_defaults_without_rewriting_legacy_preferences(): void
    {
        $state = $this->initialize();
        $legacy = ['default_unit' => 931, 'currency_id' => 257, 'purchase_currency_id' => 702,
            'pos_settings' => ['null' => null, 'false' => false, 'zero' => 0, 'empty' => '']];
        $this->company->update(['operating_settings' => $legacy]);

        $this->getJson('/api/v1/erp/company/settings')->assertOk()
            ->assertJsonPath('data.default_unit', 931)
            ->assertJsonPath('data.currency_id', 257)
            ->assertJsonPath('data.purchase_currency_id', 702)
            ->assertJsonPath('data.native_defaults.revision', $state->revision)
            ->assertJsonPath('data.native_defaults.preset_version', 'br-basic-v1')
            ->assertJsonPath('data.native_defaults.currency', ['status' => 'available', 'code' => 'BRL'])
            ->assertJsonPath('data.native_defaults.unit.id', $state->default_unit_id)
            ->assertJsonPath('data.native_defaults.unit.label', 'UN · Unidade')
            ->assertJsonPath('data.native_defaults.customer.id', $state->default_customer_id);

        $this->patchJson('/api/v1/erp/company/settings', ['time_format' => 12])->assertOk()
            ->assertJsonPath('data.native_defaults.unit.id', $state->default_unit_id)
            ->assertJsonPath('data.default_product_tax_profile_id', null);
        $this->assertSame($legacy + ['time_format' => 12], $this->company->fresh()->operating_settings);
    }

    public function test_legacy_numeric_ids_are_unresolved_even_when_a_native_unit_has_the_same_id(): void
    {
        $unitId = $this->variant->product->erp_unit_id;
        $this->company->update(['operating_settings' => ['default_unit' => $unitId, 'currency_id' => 1]]);

        $this->getJson('/api/v1/erp/company/settings')->assertOk()
            ->assertJsonPath('data.default_unit', $unitId)
            ->assertJsonPath('data.currency_id', 1)
            ->assertJsonPath('data.native_defaults.unit', ['status' => 'unresolved', 'id' => null, 'label' => null])
            ->assertJsonPath('data.native_defaults.currency', ['status' => 'unresolved', 'code' => null]);
        $this->assertDatabaseCount('erp_company_setup_states', 0);
    }

    public function test_units_index_can_resolve_the_first_product_default_without_company_settings_permission(): void
    {
        $state = $this->initialize();
        ErpCompanyMembership::where('user_id', $this->user->id)->firstOrFail()->update([
            'role' => 'operator', 'action_permissions' => ['erp.units.index', 'erp.units.show', 'erp.products.store'],
        ]);
        $this->getJson('/api/v1/erp/company/settings')->assertForbidden();
        $default = $this->getJson('/api/v1/erp/units?per_page=1')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.native_default_unit.status', 'available')
            ->assertJsonPath('meta.native_default_unit.id', $state->default_unit_id)->json('meta.native_default_unit');
        $this->getJson('/api/v1/erp/units/'.$default['id'])->assertOk()->assertJsonPath('data.status', 'active');
        $this->postJson('/api/v1/erp/products', [
            'name' => 'Primeiro produto', 'kind' => 'goods', 'erp_unit_id' => $default['id'],
            'track_stock' => true, 'variants' => [['name' => 'Padrão']],
        ])->assertCreated()->assertJsonPath('data.erp_unit_id', $state->default_unit_id);
    }

    public function test_archived_unit_is_unavailable_and_a_fresh_lookup_recovers_without_relabeling_it(): void
    {
        $state = $this->initialize();
        $state->defaultUnit->update(['status' => 'archived']);
        $this->getJson('/api/v1/erp/units?per_page=1')->assertOk()
            ->assertJsonPath('meta.native_default_unit.status', 'unavailable');
        $this->assertSame('archived', $state->defaultUnit->fresh()->status);

        $replacement = $this->variant->product->unit;
        $state->update(['default_unit_id' => $replacement->id, 'revision' => 2]);
        $this->getJson('/api/v1/erp/units?per_page=1')->assertOk()
            ->assertJsonPath('meta.native_default_unit.status', 'available')
            ->assertJsonPath('meta.native_default_unit.id', $replacement->id);
    }

    public function test_native_null_is_an_explicit_unset_preference_and_does_not_revive_legacy_ids(): void
    {
        $state = $this->initialize();
        $state->update(['currency' => null, 'default_unit_id' => null, 'default_customer_id' => null]);
        $this->company->update(['operating_settings' => ['default_unit' => $this->variant->product->erp_unit_id, 'currency_id' => 1]]);
        $this->getJson('/api/v1/erp/company/settings')->assertOk()
            ->assertJsonPath('data.native_defaults.currency', ['status' => 'unset', 'code' => null])
            ->assertJsonPath('data.native_defaults.unit', ['status' => 'unset', 'id' => null, 'label' => null])
            ->assertJsonPath('data.native_defaults.customer', ['status' => 'unset', 'id' => null, 'label' => null]);
        $this->send('sales', $this->body())->assertUnprocessable()->assertJsonValidationErrors('currency');
        $this->send('sales', $this->body(['currency' => 'BRL']))->assertCreated()->assertJsonPath('data.erp_contact_id', null);
        $state->update(['currency' => 'USD']);
        $this->getJson('/api/v1/erp/company/settings')->assertOk()
            ->assertJsonPath('data.native_defaults.currency', ['status' => 'unavailable', 'code' => null]);
        $this->send('sales', $this->body())->assertUnprocessable()->assertJsonValidationErrors('currency');
    }

    public function test_new_counter_sale_resolves_customer_and_iso_currency_but_invents_no_recipient_facts(): void
    {
        $state = $this->initialize();
        $id = $this->send('sales', $this->body())->assertCreated()
            ->assertJsonPath('data.erp_contact_id', $state->default_customer_id)
            ->assertJsonPath('data.currency', 'BRL')->json('data.id');
        $sale = ErpSale::findOrFail($id);
        $this->assertSame('Consumidor final', $sale->party_snapshot['name']);
        $this->assertNull($sale->party_snapshot['tax_id']);
        $this->assertNull($sale->party_snapshot['address']);
        $this->assertNull($state->defaultCustomer->fresh()->tax_id);
        $this->assertNull($state->defaultCustomer->fresh()->address);
        $this->assertDatabaseCount('erp_fiscal_documents', 0);
    }

    public function test_explicit_customer_and_explicit_null_override_the_native_default(): void
    {
        $this->initialize();
        $customer = ErpContact::factory()->create(['erp_company_id' => $this->company->id, 'is_customer' => true]);
        $this->send('sales', $this->body(['erp_contact_id' => $customer->id]))->assertCreated()->assertJsonPath('data.erp_contact_id', $customer->id);
        $this->send('sales', $this->body(['erp_contact_id' => null]))->assertCreated()->assertJsonPath('data.erp_contact_id', null);
    }

    public function test_default_customer_does_not_satisfy_required_recipient_facts_for_either_native_fiscal_model(): void
    {
        $state = $this->initialize();
        $id = $this->send('sales', $this->body())->assertCreated()
            ->assertJsonPath('data.erp_contact_id', $state->default_customer_id)->json('data.id');
        $party = ErpSale::findOrFail($id)->party_snapshot;

        foreach (['NF-e 55 posted sale', 'NFC-e 65 posted sale'] as $case) {
            [$builder, , $source] = ErpFiscalModelBuilderSchemaTest::builderProvider()[$case];
            app($builder)->validateSource($source);
            $source['recipient'] = ['legal_name' => $party['name'], 'state_registration_indicator' => 9];
            try {
                app($builder)->validateSource($source);
                $this->fail($case.' must request the actual missing recipient identity and address.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('recipient.cnpj', $exception->getMessage());
                $this->assertStringContainsString('recipient.address', $exception->getMessage());
            }
        }

        $this->assertNull($state->defaultCustomer->fresh()->tax_id);
        $this->assertNull($state->defaultCustomer->fresh()->address);
        $this->assertDatabaseCount('erp_fiscal_documents', 0);
        $this->assertDatabaseCount('erp_fiscal_attempts', 0);
    }

    public function test_quotes_orders_and_conversions_do_not_adopt_the_counter_sale_customer(): void
    {
        $this->initialize();
        $quote = $this->send('quotes', $this->body())->assertCreated()->assertJsonPath('data.erp_contact_id', null)->json('data.id');
        $this->send('orders', $this->body())->assertCreated()->assertJsonPath('data.erp_contact_id', null);
        $order = $this->send('quotes/'.$quote.'/convert', ['draft_revision' => 1])->assertOk()->json('data.order.id');
        $this->send('orders/'.$order.'/convert', ['draft_revision' => 1])->assertOk()->assertJsonPath('data.sale.erp_contact_id', null);
    }

    public function test_existing_sale_without_customer_stays_explicitly_unassigned_after_setup_and_draft_edits(): void
    {
        $id = $this->send('sales', $this->body(['erp_contact_id' => null]))->assertCreated()->json('data.id');
        $this->initialize();
        $this->patchJson('/api/v1/erp/sales/'.$id, ['draft_revision' => 1, 'lines' => $this->body()['lines']], ['Idempotency-Key' => 'edit-native-default'])
            ->assertOk()->assertJsonPath('data.erp_contact_id', null);
        $this->patchJson('/api/v1/erp/sales/'.$id, ['draft_revision' => 2], ['Idempotency-Key' => 'edit-native-retain'])
            ->assertOk()->assertJsonPath('data.erp_contact_id', null);
    }

    public function test_archived_or_non_customer_default_fails_before_document_receipt_or_number_allocation(): void
    {
        $state = $this->initialize();
        foreach ([['status' => 'archived'], ['status' => 'active', 'is_customer' => false]] as $change) {
            $state->defaultCustomer->update($change);
            $this->send('sales', $this->body())->assertUnprocessable()->assertJsonValidationErrors('erp_contact_id');
            $this->assertDatabaseCount('erp_sales', 0);
            $this->assertDatabaseCount('erp_operation_receipts', 0);
            $this->assertDatabaseCount('erp_commercial_number_sequences', 0);
        }
        $state->defaultCustomer->update(['is_customer' => true]);
        $this->send('sales', $this->body())->assertCreated()->assertJsonPath('data.invoice_no', '0001');
    }

    public function test_default_customer_cannot_enable_an_explicit_foreign_company_contact(): void
    {
        $this->initialize();
        $foreign = ErpContact::factory()->create(['erp_company_id' => ErpCompany::factory()->create()->id, 'is_customer' => true]);
        $this->send('sales', $this->body(['erp_contact_id' => $foreign->id]))->assertUnprocessable()->assertJsonValidationErrors('erp_contact_id');
        $this->assertDatabaseCount('erp_sales', 0);
        $this->assertDatabaseCount('erp_operation_receipts', 0);
    }

    public function test_response_loss_retry_keeps_the_committed_customer_and_number_after_defaults_change(): void
    {
        $state = $this->initialize();
        $body = $this->body();
        $first = $this->send('sales', $body, 'native-default-retry')->assertCreated();
        $replacement = ErpContact::factory()->create(['erp_company_id' => $this->company->id, 'is_customer' => true]);
        $state->update(['default_customer_id' => $replacement->id, 'revision' => 2]);
        $this->send('sales', $body, 'native-default-retry')->assertCreated()->assertExactJson($first->json());
        $this->assertDatabaseCount('erp_sales', 1);
        $this->assertDatabaseCount('erp_operation_receipts', 1);
    }

    public function test_opaque_legacy_currency_cannot_silently_become_native_brl(): void
    {
        $this->company->update(['operating_settings' => ['currency_id' => 1]]);
        $this->send('sales', $this->body())->assertUnprocessable()->assertJsonValidationErrors('currency');
        $this->send('sales', $this->body(['currency' => 'BRL']))->assertCreated()->assertJsonPath('data.currency', 'BRL');
        $this->assertSame(1, $this->company->fresh()->operating_settings['currency_id']);
        $this->assertDatabaseCount('erp_company_setup_states', 0);
    }

    public function test_sales_permission_is_rechecked_and_denial_does_not_create_a_defaulted_sale(): void
    {
        $this->initialize();
        ErpCompanyMembership::where('user_id', $this->user->id)->firstOrFail()->update([
            'role' => 'operator', 'action_permissions' => ['erp.sales.show'],
        ]);
        $this->send('sales', $this->body())->assertForbidden();
        $this->assertDatabaseCount('erp_sales', 0);
        $this->assertDatabaseCount('erp_operation_receipts', 0);
    }
}
