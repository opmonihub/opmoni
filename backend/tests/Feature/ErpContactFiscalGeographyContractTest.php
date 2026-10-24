<?php

namespace Tests\Feature;

use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpContact;
use App\Models\Erp\Sales\ErpSale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsReviewedCommercialTax;
use Tests\Support\ErpCommercialTestCase;

class ErpContactFiscalGeographyContractTest extends ErpCommercialTestCase
{
    use BuildsReviewedCommercialTax;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->withCredentials()->withCookie(config('session.cookie'), $this->app['session']->getId());
    }

    public function test_name_only_contact_save_does_not_require_or_invent_fiscal_geography(): void
    {
        $id = $this->postJson('/api/v1/erp/contacts', ['name' => 'Contato básico'])
            ->assertCreated()->assertJsonPath('data.name', 'Contato básico')
            ->assertJsonPath('data.address', null)->assertJsonPath('data.tax_id', null)->json('data.id');

        $contact = ErpContact::findOrFail($id);
        $this->assertSame($this->company->id, $contact->erp_company_id);
        $this->assertNull($contact->address);
        $this->getJson('/api/v1/erp/contacts/'.$id)->assertOk()->assertJsonPath('data.address', null);
        $this->assertNoCommercialEffects();
        $this->assertDatabaseCount('erp_fiscal_attempts', 0);
    }

    public function test_frontend_projected_geography_changes_and_clear_replace_address_without_losing_supplied_extensions(): void
    {
        $extensions = ['street' => 'Rua Um', 'number' => '10', 'integration_code' => 'CLIENT-77', 'country_code' => '1058'];
        $original = $extensions + ['city' => 'São Paulo', 'state' => 'SP', 'uf' => 'SP', 'city_code' => '3550308'];
        $id = $this->postJson('/api/v1/erp/contacts', ['name' => 'Contato geográfico', 'address' => $original])
            ->assertCreated()->assertJsonPath('data.address', $original)->json('data.id');

        // The frontend supplies the complete projection; the API replaces, rather than merges, address.
        $changed = $extensions + ['city' => 'Rio de Janeiro', 'state' => 'RJ', 'uf' => 'RJ'];
        $this->patchJson('/api/v1/erp/contacts/'.$id, ['address' => $changed])
            ->assertOk()->assertJsonPath('data.address', $changed);
        $this->assertSame($changed, ErpContact::findOrFail($id)->address);
        $this->assertArrayNotHasKey('city_code', ErpContact::findOrFail($id)->address);

        $this->patchJson('/api/v1/erp/contacts/'.$id, ['address' => $extensions])
            ->assertOk()->assertJsonPath('data.address', $extensions);
        $this->patchJson('/api/v1/erp/contacts/'.$id, ['name' => 'Contato sem geografia'])
            ->assertOk()->assertJsonPath('data.address', $extensions);
        $this->getJson('/api/v1/erp/contacts/'.$id)->assertOk()->assertJsonPath('data.address', $extensions);
        $this->assertSame($extensions, ErpContact::findOrFail($id)->address);
        $this->assertNoCommercialEffects();
    }

    public function test_uf_only_projection_retains_its_exact_alias_and_foreign_contacts_remain_inaccessible(): void
    {
        $address = ['city' => 'São Paulo', 'uf' => 'SP', 'city_code' => '3550308', 'integration_code' => 'CLIENT-77'];
        $id = $this->postJson('/api/v1/erp/contacts', ['name' => 'Contato com UF', 'address' => $address])
            ->assertCreated()->json('data.id');
        $this->patchJson('/api/v1/erp/contacts/'.$id, ['name' => 'Nome revisado', 'address' => $address])
            ->assertOk()->assertJsonPath('data.address', $address);
        $this->assertSame($address, ErpContact::findOrFail($id)->address);
        $this->assertArrayNotHasKey('state', ErpContact::findOrFail($id)->address);

        $foreign = ErpContact::factory()->create([
            'erp_company_id' => ErpCompany::factory()->create()->id, 'address' => $address,
        ])->fresh();
        $before = $foreign->getAttributes();
        $this->getJson('/api/v1/erp/contacts/'.$foreign->id)->assertNotFound();
        $this->patchJson('/api/v1/erp/contacts/'.$foreign->id, ['address' => ['city' => 'Outra cidade']])->assertNotFound();
        $this->assertSame($before, $foreign->fresh()->getAttributes());
        $this->assertSame($address, ErpContact::findOrFail($id)->address);
    }

    public function test_saved_contact_geography_is_frozen_as_recipient_facts_and_passes_native_sale_readiness(): void
    {
        $contact = $this->createFiscalContact();
        $address = $contact->address;
        $id = $this->send('sales', $this->fiscalSaleBody($contact))->assertCreated()->json('data.id');
        $sale = ErpSale::findOrFail($id);

        $this->assertSame($address, $sale->party_snapshot['address']);
        $this->assertSame($address, $sale->commercial_snapshot['party']['address']);
        $this->assertSame('3550308', $sale->party_snapshot['address']['city_code']);
        $this->assertSame('SP', $sale->party_snapshot['address']['uf']);
        $this->assertSame('source_prerequisites_checked', $sale->commercial_snapshot['fiscal_context']['readiness']);
        $this->assertSame([], $sale->commercial_snapshot['fiscal_context']['errors']);

        $this->send('sales/'.$id.'/post', ['draft_revision' => 1])->assertOk()->assertJsonPath('data.status', 'posted');
        $this->assertSame($address, $sale->fresh()->party_snapshot['address']);
        $this->assertSame($address, $sale->fresh()->commercial_snapshot['party']['address']);
        $this->assertDatabaseCount('erp_obligations', 1);
        $this->assertDatabaseCount('erp_stock_movements', 0);
        $this->assertDatabaseCount('erp_fiscal_documents', 0);
        $this->assertDatabaseCount('erp_fiscal_attempts', 0);
    }

    public function test_cleared_code_saves_basic_contact_but_frozen_sale_reports_actionable_error_before_effects(): void
    {
        $contact = $this->createFiscalContact();
        $completeAddress = $contact->address;
        $clearedAddress = $completeAddress;
        unset($clearedAddress['city_code']);
        $this->patchJson('/api/v1/erp/contacts/'.$contact->id, ['address' => $clearedAddress])
            ->assertOk()->assertJsonPath('data.address', $clearedAddress);
        $this->assertSame($clearedAddress, $contact->fresh()->address);

        $id = $this->send('sales', $this->fiscalSaleBody($contact))->assertCreated()->json('data.id');
        $sale = ErpSale::findOrFail($id);
        $this->assertSame($clearedAddress, $sale->party_snapshot['address']);
        $this->assertSame($clearedAddress, $sale->commercial_snapshot['party']['address']);
        $readiness = $sale->commercial_snapshot['fiscal_context'];
        $this->assertSame('incomplete', $readiness['readiness']);
        $this->assertSame(['party.address.city_code'], array_keys($readiness['errors']));
        $this->assertStringContainsString('recipient.address.city_code', $readiness['errors']['party.address.city_code'][0]);

        $before = $sale->getAttributes();
        $lines = $sale->lines()->get()->toArray();
        $receipts = DB::table('erp_operation_receipts')->count();
        $numbers = DB::table('erp_commercial_number_sequences')->orderBy('id')->get()->toArray();
        $response = $this->send('sales/'.$id.'/post', ['draft_revision' => 1])->assertUnprocessable()
            ->assertJsonValidationErrors('party.address.city_code');
        $this->assertSame($readiness['errors'], $response->json('errors'));

        $this->patchJson('/api/v1/erp/contacts/'.$contact->id, ['address' => $completeAddress])
            ->assertOk()->assertJsonPath('data.address', $completeAddress);
        $this->send('sales/'.$id.'/post', ['draft_revision' => 1])->assertUnprocessable()
            ->assertJsonValidationErrors('party.address.city_code');
        $this->assertSame($before, $sale->fresh()->getAttributes());
        $this->assertSame($lines, $sale->fresh()->lines()->get()->toArray());
        $this->assertSame($receipts, DB::table('erp_operation_receipts')->count());
        $this->assertEquals($numbers, DB::table('erp_commercial_number_sequences')->orderBy('id')->get()->toArray());
        $this->assertNoCommercialEffects();
        $this->assertDatabaseCount('erp_fiscal_attempts', 0);
    }

    private function createFiscalContact(): ErpContact
    {
        $recipient = ErpFiscalModelBuilderSchemaTest::builderProvider()['NF-e 55 posted sale'][2]['recipient'];
        $address = $recipient['address'] + ['state' => 'SP', 'integration_code' => 'CLIENT-77'];
        $id = $this->postJson('/api/v1/erp/contacts', [
            'name' => $recipient['legal_name'], 'tax_id' => $recipient['cnpj'], 'is_customer' => true, 'address' => $address,
        ])->assertCreated()->assertJsonPath('data.address', $address)->json('data.id');

        return ErpContact::findOrFail($id);
    }

    private function fiscalSaleBody(ErpContact $contact): array
    {
        $this->configureReviewedCommercialTax();
        $this->patchJson('/api/v1/erp/tax-settings/product-tax-profiles/'.$this->taxProfileId, [
            'fields' => ['icms_cst' => '00', 'pis_cst' => '07', 'cofins_cst' => '07', 'icms_rate' => '18.0000'],
            'expected_revision' => 1,
        ])->assertOk();
        $this->patchJson('/api/v1/erp/products/'.$this->variant->erp_product_id, [
            'ncm' => '12345678', 'origin_code' => '0', 'sku' => 'GEOGRAPHY-SKU', 'track_stock' => false,
            'tax_field_intents' => ['icms_cst' => 'inherit', 'pis_cst' => 'inherit', 'cofins_cst' => 'inherit', 'icms_rate' => 'inherit'],
        ])->assertOk();
        $this->variant->product->unit->update(['short_name' => 'UN']);
        $issuer = ErpFiscalModelBuilderSchemaTest::builderProvider()['NF-e 55 posted sale'][2]['issuer'];
        $this->establishment->update(['cnpj' => $issuer['cnpj']]);
        $address = $issuer['address'];
        $this->patchJson('/api/v1/erp/fiscal/configuration/'.$this->establishment->id, [
            'environment' => 'homologation',
            'issuer' => ['tax_regime' => 3, 'state_registration' => $issuer['state_registration'], 'uf' => $address['uf'],
                'address' => ['street' => $address['street'], 'number' => $address['number'], 'district' => $address['district'],
                    'city_code' => $address['city_code'], 'city_name' => $address['city'], 'zip_code' => $address['postal_code']]],
            'models' => ['55' => ['series' => 1, 'next_number' => 1, 'operation_nature_id' => $this->natureId]],
        ])->assertOk();

        return $this->reviewedBody([
            'fiscal_model' => '55', 'erp_contact_id' => $contact->id,
            'tax_context' => $this->classificationContext(['final_consumer' => true, 'presence' => '1']),
            'lines' => [$this->reviewedLine(['quantity' => '1', 'unit_price' => '100', 'discount_amount' => '0', 'erp_tax_rate_id' => null])],
        ]);
    }
}
