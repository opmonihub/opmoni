<?php

namespace Tests\Feature;

use App\Erp\ErpCompanyInitializer;
use App\Erp\ErpCompanyProvisioning;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpCompanySetupState;
use App\Models\Erp\ErpContact;
use App\Models\Erp\Sales\ErpInvoiceLayout;
use App\Models\Erp\Sales\ErpSale;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\ErpCommercialTestCase;

class ErpSaleRepresentationTest extends ErpCommercialTestCase
{
    public function test_new_company_layout_is_consumed_by_printable_commercial_html_without_payment_or_fiscal_claims(): void
    {
        $state = $this->provisionNewCompany();
        $layout = $state->defaultInvoiceLayout;
        $this->assertNotNull($layout);
        $this->assertSame('br-basic-v1.sale-layout', $layout->native_key);
        $this->assertSame('br-basic-v1.sale-layout', $state->preset_owned_keys['sale_layout']);
        $this->assertSame('active', $layout->status);
        $sale = $this->sale();
        $this->assertSame($layout->id, $sale->erp_invoice_layout_id);
        $this->assertSame('commercial-layout-v1', $sale->commercial_layout_snapshot['version']);
        $this->assertSame('commercial-v1', $sale->commercial_snapshot['version']);

        $response = $this->getJson($this->url($sale))->assertOk();
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $response->assertSee('lang="pt-BR"', false)->assertSee('Venda comercial')->assertSee('0001');
        $response->assertSee('Descrição')->assertSee('Quantidade')->assertSee('Valor unitário')->assertSee('Total');
        $response->assertSee('Serviço')->assertSee('10,00')->assertSee('BRL');
        $response->assertSee('Rascunho')->assertSee('Documento comercial sem validade fiscal.')->assertSee('Não comprova pagamento.');
        $response->assertDontSee('DANFE')->assertDontSee('ERP-PAYMENT-')->assertDontSee('Nota fiscal autorizada');
        $document = new DOMDocument;
        $this->assertTrue($document->loadHTML($response->getContent(), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING));
        $xpath = new DOMXPath($document);
        $this->assertSame('pt-BR', $document->documentElement->getAttribute('lang'));
        $this->assertSame(1, $xpath->query('//table')->length);
        $this->assertSame(0, $xpath->query('//script | //iframe | //img | //link')->length);
        $this->assertDatabaseCount('erp_payments', 0);
        $this->assertDatabaseCount('erp_fiscal_documents', 0);
    }

    public function test_posted_output_retains_frozen_labels_settings_and_business_facts_after_current_records_change(): void
    {
        $state = $this->provisionNewCompany();
        $layout = $state->defaultInvoiceLayout;
        $settings = $layout->layout_settings;
        $settings['labels']['title'] = 'Venda da loja';
        $settings['footer_text'] = 'Obrigado pela preferência.';
        $layout->update(['layout_settings' => $settings]);
        $customer = ErpContact::factory()->create(['erp_company_id' => $this->company->id, 'name' => 'Cliente original', 'is_customer' => true]);
        $sale = $this->sale(['erp_contact_id' => $customer->id]);
        $this->send('sales/'.$sale->id.'/post', ['draft_revision' => 1])->assertOk();
        $original = $this->getJson($this->url($sale))->assertOk()->getContent();
        $before = $sale->fresh()->getAttributes();
        $settings['labels']['title'] = 'Título atual';
        $settings['footer_text'] = 'Rodapé atual';
        $layout->update(['name' => 'Atual', 'layout_settings' => $settings, 'status' => 'archived']);
        $customer->update(['name' => 'Cliente atual', 'status' => 'archived']);
        $this->company->update(['name' => 'Empresa atual']);
        $this->establishment->update(['name' => 'Estabelecimento atual']);
        $replacement = ErpInvoiceLayout::query()->create(['erp_company_id' => $this->company->id, 'name' => 'Outro', 'layout_settings' => $settings]);
        $state->update(['default_invoice_layout_id' => $replacement->id]);

        $response = $this->getJson($this->url($sale))->assertOk();

        $this->assertSame($original, $response->getContent());
        $response->assertSee('Venda da loja')->assertSee('Obrigado pela preferência.')->assertSee('Cliente original');
        $response->assertSee('Venda lançada')->assertDontSee('Título atual')->assertDontSee('Empresa atual');
        $this->assertSame($before, $sale->fresh()->getAttributes());
    }

    public function test_draft_edits_keep_captured_layout_while_rendering_current_saved_draft_facts_and_retry_is_read_only(): void
    {
        $state = $this->provisionNewCompany();
        $sale = $this->sale();
        $captured = $sale->commercial_layout_snapshot;
        $settings = $state->defaultInvoiceLayout->layout_settings;
        $settings['labels']['title'] = 'Novo título';
        $state->defaultInvoiceLayout->update(['layout_settings' => $settings]);
        $body = $this->body(['lines' => [['description' => 'Serviço editado', 'quantity' => '2', 'unit_price' => '12.50']]]);
        $this->patchJson('/api/v1/erp/sales/'.$sale->id, $body, ['Idempotency-Key' => 'edit-lines'])->assertOk();
        $this->patchJson('/api/v1/erp/sales/'.$sale->id, ['draft_revision' => 2], ['Idempotency-Key' => 'edit-retained'])->assertOk();
        $this->assertSame($captured, $sale->fresh()->commercial_layout_snapshot);
        $before = DB::table('erp_commercial_number_sequences')->get()->toJson();
        $receipts = DB::table('erp_operation_receipts')->count();
        $response = $this->getJson($this->url($sale))->assertOk()->assertSee('Serviço editado')->assertSee('25,00')->assertDontSee('Novo título');
        $this->assertSame($response->getContent(), $this->getJson($this->url($sale))->assertOk()->getContent());
        $this->assertSame($before, DB::table('erp_commercial_number_sequences')->get()->toJson());
        $this->assertSame($receipts, DB::table('erp_operation_receipts')->count());
    }

    public function test_order_to_sale_conversion_captures_layout_and_keeps_accepted_quote_line_facts(): void
    {
        $state = $this->provisionNewCompany();
        $quote = $this->send('quotes', $this->body())->assertCreated()->json('data.id');
        $order = $this->send('quotes/'.$quote.'/convert', ['draft_revision' => 1])->assertOk()->json('data.order.id');
        $saleId = $this->send('orders/'.$order.'/convert', ['draft_revision' => 1])->assertOk()->json('data.sale.id');
        $sale = ErpSale::findOrFail($saleId);
        $this->assertSame($state->default_invoice_layout_id, $sale->erp_invoice_layout_id);
        $this->getJson($this->url($sale))->assertOk()->assertSee('Venda comercial')->assertSee('0001')->assertSee('Serviço');
    }

    public function test_legacy_sale_without_captured_layout_returns_explicit_conflict_without_current_default_repair(): void
    {
        $sale = $this->sale();
        $before = $sale->getAttributes();
        $this->getJson($this->url($sale))->assertConflict()->assertJsonPath('code', 'commercial_representation_unavailable');
        $this->assertSame($before, $sale->fresh()->getAttributes());
        $this->provisionNewCompany();
        $this->getJson($this->url($sale))->assertNotFound();
    }

    public function test_new_sale_fails_atomically_when_explicit_default_layout_is_invalid_or_archived(): void
    {
        $state = $this->provisionNewCompany();
        $state->defaultInvoiceLayout->update(['status' => 'archived']);
        $this->send('sales', $this->body(), 'retry-layout-failure')->assertUnprocessable()->assertJsonValidationErrors('default_invoice_layout_id');
        $this->assertDatabaseCount('erp_sales', 0);
        $this->assertDatabaseCount('erp_commercial_number_sequences', 0);
        $state->defaultInvoiceLayout->update(['status' => 'active', 'layout_settings' => ['version' => 'unsupported']]);
        $this->send('sales', $this->body(), 'retry-layout-failure')->assertUnprocessable()->assertJsonValidationErrors('default_invoice_layout_id');
        $this->assertDatabaseCount('erp_sales', 0);
        $this->assertDatabaseCount('erp_commercial_number_sequences', 0);
    }

    public function test_unrecognized_frozen_version_fails_without_fallback_and_retry_preserves_saved_data(): void
    {
        $this->provisionNewCompany();
        $sale = $this->sale();
        $captured = $sale->commercial_layout_snapshot;
        $sale->update(['commercial_layout_snapshot' => [...$captured, 'version' => 'future-layout-v2']]);
        $before = $sale->fresh()->getAttributes();
        $this->getJson($this->url($sale))->assertConflict()->assertJsonPath('code', 'commercial_representation_unavailable');
        $this->assertSame($before, $sale->fresh()->getAttributes());
        $sale->update(['commercial_layout_snapshot' => $captured]);
        $this->getJson($this->url($sale))->assertOk()->assertSee('Serviço');
    }

    public function test_output_escapes_labels_and_business_text_and_does_not_capture_unrelated_private_settings(): void
    {
        $state = $this->provisionNewCompany();
        $settings = $state->defaultInvoiceLayout->layout_settings;
        $settings['labels']['title'] = '<script>alert(1)</script>';
        $settings['footer_text'] = '<img src=x onerror=alert(1)>';
        $settings['private_token'] = 'must-never-appear';
        $state->defaultInvoiceLayout->update(['layout_settings' => $settings]);
        $sale = $this->sale(['lines' => [['description' => '<svg onload=alert(1)>', 'quantity' => '1', 'unit_price' => '10']]]);
        $response = $this->getJson($this->url($sale))->assertOk();
        $response->assertSee('&lt;script&gt;', false)->assertSee('&lt;svg', false)->assertSee('&lt;img', false);
        $response->assertDontSee('<script>', false)->assertDontSee('<svg', false)->assertDontSee('<img', false)->assertDontSee('must-never-appear');
        $document = new DOMDocument;
        $this->assertTrue($document->loadHTML($response->getContent(), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING));
        $this->assertSame(0, (new DOMXPath($document))->query('//script | //svg | //img | //*[@onerror or @onload]')->length);
        $this->assertStringNotContainsString('must-never-appear', json_encode($sale->commercial_layout_snapshot));
        $this->assertStringContainsString("script-src 'none'", $response->headers->get('Content-Security-Policy'));
    }

    public function test_decimal_presentation_reads_exact_frozen_strings_without_float_rounding(): void
    {
        $this->provisionNewCompany();
        $sale = $this->sale();
        $facts = $sale->commercial_snapshot;
        $facts['lines'][0]['unit_price'] = '9007199254740991.1234';
        $facts['lines'][0]['line_total'] = '9007199254740991.1234';
        $facts['totals']['subtotal_amount'] = '9007199254740991.1234';
        $facts['totals']['total_amount'] = '9007199254740991.1234';
        $sale->update(['commercial_snapshot' => $facts]);
        $this->getJson($this->url($sale))->assertOk()->assertSee('9.007.199.254.740.991,1234');
        $this->assertSame('9007199254740991.1234', $sale->fresh()->commercial_snapshot['totals']['total_amount']);
    }

    public function test_company_context_and_current_grant_and_establishment_scope_are_enforced_for_representation(): void
    {
        $this->provisionNewCompany();
        $sale = $this->sale();
        $member = ErpCompanyMembership::query()->where('user_id', $this->user->id)->where('erp_company_id', $this->company->id)->firstOrFail();
        $member->update(['role' => 'operator', 'access_all_establishments' => false, 'action_permissions' => ['erp.sales.representation']]);
        $this->getJson($this->url($sale))->assertNotFound();
        $member->establishments()->attach($this->establishment->id, ['erp_company_id' => $this->company->id]);
        $this->getJson($this->url($sale))->assertOk();
        $member->update(['action_permissions' => ['erp.sales.index']]);
        $this->getJson($this->url($sale))->assertForbidden();
        $member->update(['action_permissions' => ['erp.sales.representation']]);
        $this->withHeader('X-Company-Id', '999999')->getJson($this->url($sale))->assertConflict()->assertJsonPath('code', 'company_context_changed');
    }

    public function test_required_layout_storage_failure_rolls_back_all_provisioning_facts(): void
    {
        $tables = ['erp_companies', 'erp_company_memberships', 'erp_establishments', 'erp_subscriptions', 'erp_units', 'erp_contacts', 'erp_company_setup_states', 'erp_invoice_schemes', 'erp_invoice_layouts'];
        $before = array_map(fn ($table) => DB::table($table)->count(), array_combine($tables, $tables));
        $dispatcher = ErpInvoiceLayout::getEventDispatcher();
        ErpInvoiceLayout::setEventDispatcher(clone $dispatcher);
        ErpInvoiceLayout::creating(function (): void {
            throw new RuntimeException('Layout storage failed.');
        });
        try {
            app(ErpCompanyProvisioning::class)->createCompanyForOwner($this->user, ['name' => 'Falha', 'cnpj' => '11222333000181']);
            $this->fail('Required layout failure must roll back provisioning.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Layout storage failed.', $exception->getMessage());
        } finally {
            ErpInvoiceLayout::setEventDispatcher($dispatcher);
        }
        foreach ($before as $table => $count) {
            $this->assertDatabaseCount($table, $count);
        }
    }

    public function test_initializer_rerun_preserves_edited_layout_and_never_changes_existing_frozen_document(): void
    {
        $state = $this->provisionNewCompany();
        $sale = $this->sale();
        $before = $sale->getAttributes();
        $settings = $state->defaultInvoiceLayout->layout_settings;
        $settings['labels']['title'] = 'Personalizado';
        $state->defaultInvoiceLayout->update(['layout_settings' => $settings, 'status' => 'archived']);

        app(ErpCompanyInitializer::class)->initialize($this->company);

        $this->assertDatabaseCount('erp_invoice_layouts', 1);
        $this->assertSame('Personalizado', $state->defaultInvoiceLayout->fresh()->layout_settings['labels']['title']);
        $this->assertSame('archived', $state->defaultInvoiceLayout->fresh()->status);
        $this->assertSame($before, $sale->fresh()->getAttributes());
    }

    public function test_additive_upgrade_leaves_historical_sale_and_existing_layout_unadopted(): void
    {
        $migration = require database_path('migrations/2026_10_24_020000_add_native_commercial_layout_snapshots.php');
        $migration->down();
        $settings = ['legacy' => ['keep' => null, 'false' => false, 'zero' => 0, 'empty' => '']];
        $layout = ErpInvoiceLayout::query()->create(['erp_company_id' => $this->company->id, 'name' => 'Histórico', 'layout_settings' => $settings, 'is_default' => true]);
        $sale = ErpSale::query()->create([
            'erp_company_id' => $this->company->id, 'erp_establishment_id' => $this->establishment->id,
            'erp_invoice_layout_id' => $layout->id, 'invoice_no' => 'S-000042', 'status' => 'posted',
            'total_amount' => '10.0000', 'currency' => 'BRL', 'commercial_snapshot' => ['version' => 'commercial-v1', 'legacy' => true],
        ]);
        $before = (array) DB::table('erp_sales')->where('id', $sale->id)->first();

        $migration->up();

        $this->assertNull($sale->fresh()->commercial_layout_snapshot);
        $this->assertNull($layout->fresh()->native_key);
        $this->assertSame($settings, $layout->fresh()->layout_settings);
        foreach ($before as $key => $value) {
            $this->assertSame($value, ((array) DB::table('erp_sales')->where('id', $sale->id)->first())[$key], $key);
        }
        $this->getJson($this->url($sale))->assertConflict()->assertJsonPath('code', 'commercial_representation_unavailable');
        $this->assertSame('S-000042', $sale->fresh()->invoice_no);
    }

    public function test_unknown_commercial_snapshot_version_is_not_silently_reinterpreted_as_the_current_contract(): void
    {
        $this->provisionNewCompany();
        $sale = $this->sale();
        $facts = $sale->commercial_snapshot;
        $sale->update(['commercial_snapshot' => [...$facts, 'version' => 'future-commercial-v99']]);
        $before = $sale->fresh()->getAttributes();

        $this->getJson($this->url($sale))->assertConflict()->assertJsonPath('code', 'commercial_representation_unavailable');

        $this->assertSame($before, $sale->fresh()->getAttributes());
    }

    private function provisionNewCompany(): ErpCompanySetupState
    {
        $this->company = app(ErpCompanyProvisioning::class)->createCompanyForOwner($this->user, ['name' => 'Empresa original', 'cnpj' => '11222333000181']);
        $this->company->subscription->update(['status' => 'active']);
        $this->establishment = $this->company->establishments()->firstOrFail();
        $this->postJson('/api/v1/erp/company/switch', ['company_id' => $this->company->id])->assertOk();
        $this->withHeaders(['X-Company-Id' => (string) $this->company->id, 'X-CSRF-Token' => $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token')]);

        return $this->company->setupState()->firstOrFail();
    }

    private function sale(array $changes = []): ErpSale
    {
        return ErpSale::findOrFail($this->send('sales', $this->body($changes))->assertCreated()->json('data.id'));
    }

    private function body(array $changes = []): array
    {
        return array_replace(['erp_establishment_id' => $this->establishment->id, 'lines' => [['description' => 'Serviço', 'quantity' => '1', 'unit_price' => '10']]], $changes);
    }

    private function url(ErpSale $sale): string
    {
        return '/api/v1/erp/sales/'.$sale->id.'/representation';
    }
}
