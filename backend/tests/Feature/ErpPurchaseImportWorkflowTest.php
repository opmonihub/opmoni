<?php

namespace Tests\Feature;

use App\Erp\ErpOperationJournal;
use App\Erp\Fiscal\ErpFiscalPayloadBuilder;
use App\Erp\Stock\ErpStockOperation;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpContact;
use App\Models\Erp\ErpOperationReceipt;
use App\Models\Erp\ErpProductVariant;
use App\Models\Erp\ErpTaxRate;
use App\Models\Erp\Stock\ErpPurchase;
use App\Models\Erp\Stock\ErpPurchaseImport;
use App\Models\Erp\Stock\ErpPurchaseReturn;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\ErpCommercialTestCase;

class ErpPurchaseImportWorkflowTest extends ErpCommercialTestCase
{
    private function supplier(): ErpContact
    {
        return ErpContact::factory()->create(['erp_company_id' => $this->company->id, 'tax_id' => '11222333000181', 'is_supplier' => true]);
    }

    private function xml(string $price = '0.3333333333', string $gross = '1.00', string $extra = ''): string
    {
        $key = app(ErpFiscalPayloadBuilder::class)->buildAccessKey(['uf' => 'SP', 'cnpj' => '11222333000181', 'model' => '55', 'series' => 1, 'number' => 123, 'cnf' => '87654321', 'issued_at' => '2026-10-17T10:00:00-03:00']);
        $identification = '<cUF>'.substr($key, 0, 2).'</cUF><mod>'.substr($key, 20, 2).'</mod><serie>'.(int) substr($key, 22, 3).'</serie><nNF>'.(int) substr($key, 25, 9).'</nNF><tpEmis>'.substr($key, 34, 1).'</tpEmis><cNF>'.substr($key, 35, 8).'</cNF><cDV>'.substr($key, 43, 1).'</cDV>';

        return '<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe"><NFe><infNFe Id="NFe'.$key.'"><ide>'.$identification.'</ide><emit><CNPJ>11222333000181</CNPJ><xNome>Supplier source</xNome></emit><dest><CNPJ>30288513000100</CNPJ><xNome>Office destination</xNome></dest><det nItem="1"><prod><cProd>001</cProd><xProd>Source goods</xProd><uCom>UN</uCom><qCom>3</qCom><vUnCom>'.$price.'</vUnCom><vProd>'.$gross.'</vProd></prod><imposto><ICMS><ICMS00><vICMS>0.18</vICMS></ICMS00></ICMS></imposto></det><total><ICMSTot><vProd>'.$gross.'</vProd><vNF>'.$gross.'</vNF></ICMSTot>'.$extra.'</total></infNFe></NFe></nfeProc>';
    }

    private function preview(?string $xml = null): array
    {
        $this->establishment->update(['cnpj' => '30288513000100']);

        return $this->send('purchases/xml-preview', ['erp_establishment_id' => $this->establishment->id, 'xml' => $xml ?? $this->xml()])->assertOk()->json('data');
    }

    private function choices(string $token, int $supplier): array
    {
        return ['token' => $token, 'erp_establishment_id' => $this->establishment->id, 'erp_contact_id' => $supplier, 'items' => [['line' => 1, 'erp_product_variant_id' => $this->variant->id, 'unit_conversion_factor' => '1.00000000']]];
    }

    public function test_preview_confirm_separate_post_preserves_exact_source_consideration_and_replay(): void
    {
        $supplier = $this->supplier();
        $before = $supplier->fresh()->getRawOriginal();
        $preview = $this->preview();
        $this->assertSame([], $preview['errors']);
        $this->assertSame('Supplier source', $preview['emit']['name']);
        $this->assertSame('Office destination', $preview['dest']['name']);
        $this->assertSame('001', $preview['det'][0]['codigo']);
        $this->assertSame('0.3333333333', $preview['det'][0]['vUnCom']);
        $this->assertDatabaseCount('erp_purchases', 0);
        $choices = $this->choices($preview['token'], $supplier->id);
        $result = $this->send('purchases/xml-confirm', $choices, 'confirm')->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.total_amount', '1.0000')->assertJsonPath('data.supplier_snapshot.id', $supplier->id)->assertJsonPath('data.lines.0.unit_conversion_factor', '1.00000000')->json();
        $purchase = ErpPurchase::findOrFail($result['data']['id']);
        $detail = $this->getJson('/api/v1/erp/purchases/'.$purchase->id)->assertOk();
        $detail->assertJsonMissingPath('data.source_snapshot.source_facts');
        $detail->assertJsonMissingPath('data.source_snapshot.det.0.source_facts');
        $detail->assertJsonMissingPath('data.lines.0.product_snapshot.source_item.source_facts');
        $detail->assertJsonPath('data.source_snapshot.chave', $preview['chave']);
        $detail->assertJsonPath('data.source_snapshot.det.0.xProd', 'Source goods');
        $detail->assertJsonPath('data.lines.0.product_snapshot.source_item.xProd', 'Source goods');
        $this->assertSame('0.3333', $purchase->lines->sole()->unit_cost);
        $this->assertSame('1.0000', $purchase->lines->sole()->line_total);
        $this->assertSame('0.0000', $purchase->lines->sole()->product_snapshot['amounts']['tax_amount']);
        $this->assertDatabaseCount('erp_stock_movements', 0);
        $this->assertDatabaseCount('erp_obligations', 0);
        $this->assertSame($before, $supplier->fresh()->getRawOriginal());
        $this->patchJson('/api/v1/erp/purchases/'.$purchase->id, ['draft_revision' => 1, 'lines' => [['id' => $purchase->lines->sole()->id, 'erp_product_variant_id' => $this->variant->id, 'description' => 'Source edit', 'quantity' => '3', 'unit_cost' => '0.3333', 'unit_conversion_factor' => '1.00000000']]], ['Idempotency-Key' => 'edit-source'])->assertOk()->assertJsonPath('data.total_amount', '1.0000');
        $purchase->refresh()->load('lines');
        $this->assertSame('0.3333333333', $purchase->lines->sole()->product_snapshot['source_item']['vUnCom']);
        $this->assertSame('1.0000', $purchase->lines->sole()->line_total);
        $this->send('purchases/'.$purchase->id.'/post', ['draft_revision' => 2], 'post')->assertOk();
        $this->assertDatabaseHas('erp_obligations', ['source_id' => $purchase->id, 'amount' => '1.0000', 'erp_contact_id' => $supplier->id]);
        $this->assertDatabaseHas('erp_stock_movements', ['source_line_id' => $purchase->lines->sole()->id, 'quantity' => '3.0000', 'total_cost' => '1.0000']);
        $this->travel(2)->hours();
        $supplier->update(['status' => 'archived']);
        $this->send('purchases/xml-confirm', $choices, 'confirm')->assertCreated()->assertExactJson($result);
        $this->send('purchases/xml-confirm', $choices, 'other')->assertConflict();
        $this->send('purchases/xml-preview/read', ['token' => $preview['token']])->assertOk()->assertJsonPath('data.state', 'completed');
    }

    public function test_raw_bounds_entities_and_structural_errors_create_no_token_or_business_rows(): void
    {
        $this->establishment->update(['cnpj' => '30288513000100']);
        foreach ([str_repeat(' ', 2_000_001), '<bad', '<!DOCTYPE NFe [<!ENTITY x SYSTEM "file:///etc/passwd">]><NFe>&x;</NFe>', "<NFe>\0</NFe>", '<?xml version="1.0" encoding="ISO-8859-1"?><NFe/>', '<root>'.$this->xml().'</root>'] as $xml) {
            $this->send('purchases/xml-preview', ['erp_establishment_id' => $this->establishment->id, 'xml' => $xml])->assertUnprocessable();
        }
        $this->assertDatabaseCount('erp_purchase_imports', 0);
        $this->assertDatabaseCount('erp_purchases', 0);
        $xml = $this->xml();
        $this->preview($xml.str_repeat(' ', 2_000_000 - strlen($xml)));
    }

    public function test_wrong_destination_key_and_non_array_lines_are_rejected_without_purchase_writes(): void
    {
        $this->establishment->update(['cnpj' => '30288513000100']);
        $supplier = $this->supplier();
        $valid = $this->xml();
        $wrongDestination = str_replace('<CNPJ>30288513000100</CNPJ>', '<CNPJ>99999999000191</CNPJ>', $valid);
        $wrongInvoice = str_replace('<nNF>123</nNF>', '<nNF>124</nNF>', $valid);
        foreach ([$wrongDestination, $wrongInvoice] as $xml) {
            $this->send('purchases/xml-preview', ['erp_establishment_id' => $this->establishment->id, 'xml' => $xml])->assertUnprocessable();
        }
        foreach ([true, 'broken', null] as $line) {
            $this->send('purchases', ['erp_establishment_id' => $this->establishment->id, 'erp_contact_id' => $supplier->id, 'lines' => [$line]])->assertUnprocessable();
        }
        $this->assertDatabaseCount('erp_purchase_imports', 0);
        $this->assertDatabaseCount('erp_purchases', 0);
        $this->assertDatabaseCount('erp_stock_movements', 0);
    }

    public function test_xml_display_alias_cannot_override_verified_issuer_identity(): void
    {
        $verifiedSupplier = $this->supplier();
        $injectedSupplier = ErpContact::factory()->create(['erp_company_id' => $this->company->id, 'tax_id' => '99999999000191', 'is_supplier' => true]);
        $xml = str_replace('<xNome>Supplier source</xNome>', '<xNome>Supplier source</xNome><cpf_cnpj>99999999000191</cpf_cnpj>', $this->xml());

        $preview = $this->preview($xml);
        $this->assertSame('11.222.333/0001-81', $preview['emit']['cpf_cnpj']);
        $this->send('purchases/xml-confirm', $this->choices($preview['token'], $injectedSupplier->id), 'issuer-alias-injection')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('erp_contact_id');

        $malformedAlias = str_replace('<xNome>Supplier source</xNome>', '<xNome>Supplier source</xNome><cpf_cnpj><nested>99999999000191</nested></cpf_cnpj>', $this->xml());
        $malformedPreview = $this->preview($malformedAlias);
        $this->assertSame('11.222.333/0001-81', $malformedPreview['emit']['cpf_cnpj']);
        $this->send('purchases/xml-confirm', $this->choices($malformedPreview['token'], $injectedSupplier->id), 'issuer-alias-malformed')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('erp_contact_id');

        $this->assertDatabaseCount('erp_purchases', 0);
        $this->assertDatabaseHas('erp_purchase_imports', ['token_digest' => hash('sha256', $preview['token']), 'completed_at' => null]);
        $this->assertDatabaseCount('erp_operation_receipts', 0);
        $this->send('purchases/xml-preview/read', ['token' => $preview['token']])->assertOk()->assertJsonPath('data.state', 'pending');
        $this->send('purchases/xml-preview/read', ['token' => $malformedPreview['token']])->assertOk()->assertJsonPath('data.state', 'pending');
        $this->send('purchases/xml-confirm', $this->choices($preview['token'], $verifiedSupplier->id), 'issuer-alias-valid')
            ->assertCreated()
            ->assertJsonPath('data.supplier_snapshot.tax_id', '11222333000181');
    }

    public function test_mapping_line_array_and_object_values_are_validation_errors_and_leave_token_pending(): void
    {
        $supplier = $this->supplier();
        $preview = $this->preview();
        $responses = [];
        foreach ([[], (object) ['unexpected' => 'value']] as $index => $lineId) {
            $body = $this->choices($preview['token'], $supplier->id);
            $body['items'][0]['line'] = $lineId;
            $responses[] = $this->send('purchases/xml-confirm', $body, 'malformed-line-id-'.$index);
        }
        foreach ($responses as $response) {
            $response->assertUnprocessable()->assertJsonValidationErrors('items.0.line');
        }
        $this->assertDatabaseCount('erp_purchases', 0);
        $this->assertDatabaseCount('erp_operation_receipts', 0);
        $this->send('purchases/xml-preview/read', ['token' => $preview['token']])->assertOk()->assertJsonPath('data.state', 'pending');

        $this->send('purchases/xml-confirm', $this->choices($preview['token'], $supplier->id), 'valid-line-id')->assertCreated();
    }

    public function test_zero_explicit_conversion_factor_is_rejected_for_manual_purchase_and_xml_confirmation(): void
    {
        $supplier = $this->supplier();
        $manual = ['erp_establishment_id' => $this->establishment->id, 'erp_contact_id' => $supplier->id, 'lines' => [['erp_product_variant_id' => $this->variant->id, 'quantity' => '3', 'unit_cost' => '1', 'unit_conversion_factor' => '0']]];
        $manualResponse = $this->send('purchases', $manual, 'zero-manual-factor');

        $preview = $this->preview();
        $choices = $this->choices($preview['token'], $supplier->id);
        $choices['items'][0]['unit_conversion_factor'] = '0';
        $importResponse = $this->send('purchases/xml-confirm', $choices, 'zero-import-factor');
        $manualResponse->assertUnprocessable()->assertJsonValidationErrors('lines.0.unit_conversion_factor');
        $importResponse->assertUnprocessable()->assertJsonValidationErrors('lines.0.unit_conversion_factor');

        $this->assertDatabaseCount('erp_purchases', 0);
        $this->assertDatabaseCount('erp_purchase_lines', 0);
        $this->assertDatabaseCount('erp_stock_movements', 0);
        $this->send('purchases/xml-preview/read', ['token' => $preview['token']])->assertOk()->assertJsonPath('data.state', 'pending');
    }

    public function test_preview_and_token_read_redact_source_facts_but_keep_private_source_durable(): void
    {
        $supplier = $this->supplier();
        $this->establishment->update(['cnpj' => '30288513000100']);
        $xml = str_replace('<xProd>Source goods</xProd>', '<xProd>Source goods</xProd><privateMarker>private-value</privateMarker>', $this->xml());
        $response = $this->send('purchases/xml-preview', ['erp_establishment_id' => $this->establishment->id, 'xml' => $xml])->assertOk();
        $preview = $response->json('data');
        $response->assertJsonMissingPath('data.det.0.source_facts');
        $response->assertJsonPath('data.det.0.xProd', 'Source goods');
        $stored = ErpPurchaseImport::where('token_digest', hash('sha256', $preview['token']))->firstOrFail();
        $this->assertSame('private-value', $stored->source_snapshot['det'][0]['source_facts']['prod']['privateMarker']);

        $read = $this->send('purchases/xml-preview/read', ['token' => $preview['token']])->assertOk();
        $read->assertJsonMissingPath('data.det.0.source_facts');
        $read->assertJsonPath('data.det.0.xProd', 'Source goods');
        $stored->refresh();
        $this->assertSame('private-value', $stored->source_snapshot['det'][0]['source_facts']['prod']['privateMarker']);
        $purchaseId = $this->send('purchases/xml-confirm', $this->choices($preview['token'], $supplier->id), 'private-source-confirm')->assertCreated()->json('data.id');
        $purchase = ErpPurchase::findOrFail($purchaseId)->load('lines');
        $this->assertSame('private-value', $purchase->source_snapshot['det'][0]['source_facts']['prod']['privateMarker']);
        $this->assertSame('private-value', $purchase->lines->sole()->product_snapshot['source_item']['source_facts']['prod']['privateMarker']);
    }

    public function test_discounted_import_amount_is_preserved_during_metadata_only_line_edit(): void
    {
        $supplier = $this->supplier();
        $xml = str_replace('<vProd>1.00</vProd>', '<vProd>1.00</vProd><vDesc>1.00</vDesc>', $this->xml('0.3333333333', '1.00'));
        $xml = str_replace('<vNF>1.00</vNF>', '<vNF>0.00</vNF>', $xml);
        $preview = $this->preview($xml);
        $this->assertSame([], $preview['errors']);
        $this->assertSame('1.0000', $preview['det'][0]['amounts']['gross_amount']);
        $this->assertSame('1.0000', $preview['det'][0]['amounts']['discount_amount']);

        $purchaseId = $this->send('purchases/xml-confirm', $this->choices($preview['token'], $supplier->id), 'discounted-source-confirm')->assertCreated()->json('data.id');
        $purchase = ErpPurchase::findOrFail($purchaseId)->load('lines');
        $line = $purchase->lines->sole();
        $response = $this->patchJson('/api/v1/erp/purchases/'.$purchaseId, ['draft_revision' => 1, 'lines' => [['id' => $line->id, 'erp_product_variant_id' => $this->variant->id, 'description' => 'Metadata edit', 'quantity' => '3', 'unit_cost' => '0.3333', 'unit_conversion_factor' => '1.00000000']]], ['Idempotency-Key' => 'discounted-source-edit']);

        $response->assertOk()->assertJsonPath('data.total_amount', '0.0000');
        $line->refresh();
        $this->assertSame('1.0000', $line->product_snapshot['amounts']['gross_amount']);
        $this->assertSame('1.0000', $line->product_snapshot['amounts']['discount_amount']);
        $this->assertSame('0.0000', $line->line_total);
        $this->assertSame('0.3333333333', $line->product_snapshot['source_item']['vUnCom']);
    }

    public function test_imported_line_recalculates_tax_and_total_when_tax_rate_changes_without_explicit_tax(): void
    {
        $supplier = $this->supplier();
        $rate = ErpTaxRate::factory()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $this->establishment->id, 'name' => 'Ten percent', 'rate' => '10.0000']);
        $preview = $this->preview($this->xml('3.3333333333', '10.00'));
        $this->assertSame([], $preview['errors']);
        $purchaseId = $this->send('purchases/xml-confirm', $this->choices($preview['token'], $supplier->id), 'tax-source-confirm')->assertCreated()->json('data.id');
        $purchase = ErpPurchase::findOrFail($purchaseId)->load('lines');
        $line = $purchase->lines->sole();

        $patch = ['draft_revision' => 1, 'lines' => [['id' => $line->id, 'erp_product_variant_id' => $this->variant->id, 'description' => 'Tax metadata edit', 'quantity' => '3', 'unit_cost' => '3.3333', 'unit_conversion_factor' => '1.00000000', 'erp_tax_rate_id' => $rate->id]]];
        $this->patchJson('/api/v1/erp/purchases/'.$purchaseId, $patch, ['Idempotency-Key' => 'tax-metadata-edit'])
            ->assertOk()
            ->assertJsonPath('data.total_amount', '11.0000');

        $purchase->refresh()->load('lines');
        $line = $purchase->lines->sole();
        $this->assertSame('11.0000', $purchase->total_amount);
        $this->assertSame('10.0000', $line->product_snapshot['amounts']['gross_amount']);
        $this->assertSame('10.0000', $line->product_snapshot['amounts']['net_amount']);
        $this->assertSame('1.0000', $line->product_snapshot['amounts']['tax_amount']);
        $this->assertSame('11.0000', $line->product_snapshot['amounts']['line_total']);
        $this->assertSame($rate->id, $line->product_snapshot['tax_rate']['id']);

        $otherRate = ErpTaxRate::factory()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $this->establishment->id, 'name' => 'Twenty percent', 'rate' => '20.0000']);
        $mismatchedTax = $patch;
        $mismatchedTax['draft_revision'] = 2;
        $mismatchedTax['lines'][0]['erp_tax_rate_id'] = $otherRate->id;
        $mismatchedTax['lines'][0]['tax_amount'] = '1.0000';
        $this->patchJson('/api/v1/erp/purchases/'.$purchaseId, $mismatchedTax, ['Idempotency-Key' => 'tax-explicit-mismatch'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.0.tax_amount');
        $this->assertSame('11.0000', $purchase->fresh()->total_amount);
        $this->assertSame($rate->id, $line->fresh()->product_snapshot['tax_rate']['id']);
    }

    public function test_blocked_source_remains_inspectable_and_expiry_and_mapping_failures_are_atomic(): void
    {
        $supplier = $this->supplier();
        $blocked = $this->preview(str_replace('<vNF>1.00</vNF>', '<vNF>2.00</vNF>', $this->xml()));
        $this->assertNotEmpty($blocked['errors']);
        $this->send('purchases/xml-preview/read', ['token' => $blocked['token']])->assertOk()->assertJsonPath('data.state', 'blocked');
        $this->send('purchases/xml-confirm', $this->choices($blocked['token'], $supplier->id))->assertUnprocessable();
        $preview = $this->preview();
        $choices = $this->choices($preview['token'], $supplier->id);
        $wrong = $choices;
        $wrong['items'][0]['erp_product_variant_id'] = true;
        $this->send('purchases/xml-confirm', $wrong, 'retry')->assertUnprocessable();
        $foreign = ErpProductVariant::factory()->create();
        $wrong = $choices;
        $wrong['items'][0]['erp_product_variant_id'] = $foreign->id;
        $this->send('purchases/xml-confirm', $wrong, 'foreign-variant')->assertUnprocessable();
        $this->assertDatabaseCount('erp_purchases', 0);
        $this->travel(2)->hours();
        $this->send('purchases/xml-confirm', $choices, 'retry')->assertUnprocessable()->assertJsonValidationErrors('token');
    }

    public function test_manual_edit_retains_omitted_lines_and_atomic_replacement_and_posted_immutability(): void
    {
        $supplier = $this->supplier();
        $body = ['erp_establishment_id' => $this->establishment->id, 'erp_contact_id' => $supplier->id, 'lines' => [['erp_product_variant_id' => $this->variant->id, 'quantity' => '2', 'unit_cost' => '10', 'discount_amount' => '1', 'tax_amount' => '2']]];
        $id = $this->send('purchases', $body, 'manual')->assertCreated()->assertJsonPath('data.total_amount', '21.0000')->json('data.id');
        $before = DB::table('erp_purchase_lines')->where('erp_purchase_id', $id)->get()->toJson();
        $this->variant->product->update(['name' => 'Changed', 'kind' => 'service']);
        $this->patchJson('/api/v1/erp/purchases/'.$id, ['draft_revision' => 1, 'document_no' => 'EDIT'], ['Idempotency-Key' => 'edit'])->assertOk()->assertJsonPath('data.draft_revision', 2);
        $this->assertSame($before, DB::table('erp_purchase_lines')->where('erp_purchase_id', $id)->get()->toJson());
        $this->patchJson('/api/v1/erp/purchases/'.$id, ['draft_revision' => 1], ['Idempotency-Key' => 'stale'])->assertConflict();
        $this->send('purchases/'.$id.'/post', ['draft_revision' => 2])->assertOk();
        $this->assertDatabaseCount('erp_stock_movements', 1);
        $this->patchJson('/api/v1/erp/purchases/'.$id, ['document_no' => 'BAD'], ['Idempotency-Key' => 'posted'])->assertConflict();
    }

    public function test_named_source_components_and_missing_deson_flags_reconcile_without_double_adding_included_taxes(): void
    {
        foreach ([['', '100.00', '0.0000'], ['', '90.00', '10.0000'], ['<indDeduzDeson>0</indDeduzDeson>', '100.00', '0.0000'], ['<indDeduzDeson>1</indDeduzDeson>', '90.00', '10.0000']] as [$flag, $total, $discount]) {
            $xml = str_replace('<vICMS>0.18</vICMS>', '<vICMS>0.18</vICMS><vICMSDeson>10.00</vICMSDeson>'.$flag, $this->xml('33.3333333333', '100.00'));
            $xml = str_replace('<vNF>100.00</vNF>', '<vNF>'.$total.'</vNF>', $xml);
            $preview = $this->preview($xml);
            $this->assertSame([], $preview['errors']);
            $this->assertSame($discount, $preview['det'][0]['amounts']['discount_amount']);
            $this->assertSame('0.0000', $preview['det'][0]['amounts']['tax_amount']);
        }
        $xml = str_replace('<vICMS>0.18</vICMS>', '<vICMS>0.18</vICMS><vICMSDeson>10.00</vICMSDeson>', $this->xml('33.3333333333', '100.00'));
        $preview = $this->preview(str_replace('<vNF>100.00</vNF>', '<vNF>95.00</vNF>', $xml));
        $this->assertSame('total.vICMSDeson', $preview['errors'][0]['field']);
        $unknown = $this->preview(str_replace('<vNF>1.00</vNF>', '<vNF>1.00</vNF><vFutureTax>1.00</vFutureTax>', $this->xml()));
        $this->assertSame('source.vFutureTax', $unknown['errors'][0]['field']);
        $this->assertDatabaseCount('erp_purchases', 0);
    }

    public function test_confirm_and_patch_receipt_failures_rollback_all_rows_and_preserve_retry_identity(): void
    {
        $supplier = $this->supplier();
        $preview = $this->preview();
        $choices = $this->choices($preview['token'], $supplier->id);
        ErpOperationReceipt::creating(function ($receipt): void {
            if ($receipt->operation === 'erp.purchases.xml-confirm') {
                throw ValidationException::withMessages(['receipt' => 'Injected failure']);
            }
        });
        try {
            $this->send('purchases/xml-confirm', $choices, 'rollback')->assertUnprocessable();
        } finally {
            ErpOperationReceipt::flushEventListeners();
        }
        $this->assertDatabaseCount('erp_purchases', 0);
        $this->assertDatabaseCount('erp_purchase_lines', 0);
        $this->assertDatabaseHas('erp_purchase_imports', ['completed_at' => null]);
        $id = $this->send('purchases/xml-confirm', $choices, 'rollback')->assertCreated()->json('data.id');
        $before = ErpPurchase::findOrFail($id)->getRawOriginal();
        ErpOperationReceipt::creating(function ($receipt): void {
            if ($receipt->operation === 'erp.purchases.update') {
                throw ValidationException::withMessages(['receipt' => 'Injected failure']);
            }
        });
        try {
            $this->patchJson('/api/v1/erp/purchases/'.$id, ['document_no' => 'MUTATED'], ['Idempotency-Key' => 'update-rollback'])->assertUnprocessable();
        } finally {
            ErpOperationReceipt::flushEventListeners();
        }
        $this->assertSame($before, ErpPurchase::findOrFail($id)->getRawOriginal());
        $this->getJson('/api/v1/erp/purchases/'.$id)->assertOk()->assertJsonPath('data.source_access_key', $preview['chave']);
    }

    public function test_legacy_stock_v1_purchase_post_receipt_still_replays_after_purchase_journal_upgrade(): void
    {
        $supplier = $this->supplier();
        $id = $this->send('purchases', ['erp_establishment_id' => $this->establishment->id, 'erp_contact_id' => $supplier->id, 'lines' => [['erp_product_variant_id' => $this->variant->id, 'quantity' => '2', 'unit_cost' => '5']]])->assertCreated()->json('data.id');
        $response = $this->send('purchases/'.$id.'/post', [], 'legacy-purchase-v1')->assertOk();
        $receipt = ErpOperationReceipt::query()->where('operation', 'erp.purchases.post')->firstOrFail();

        $legacyRequest = Request::create('/api/v1/erp/purchases/'.$id.'/post', 'POST');
        $legacyRequest->setUserResolver(fn () => $this->user);
        $membership = ErpCompanyMembership::query()->where('erp_company_id', $this->company->id)->where('user_id', $this->user->id)->firstOrFail();
        $legacyRequest->attributes->set(CurrentCompany::class, new CurrentCompany($this->company, 'direct', $membership, null, null));
        $payload = app(ErpStockOperation::class)->canonical($legacyRequest, ErpStockOperation::PURCHASE, $id);
        foreach (['source_import_id', 'source_access_key', 'source_sha256', 'source_byte_length', 'source_snapshot', 'draft_revision', 'commercial_snapshot'] as $field) {
            unset($payload['snapshot'][$field]);
        }
        foreach ($payload['snapshot']['lines'] ?? [] as $index => $line) {
            foreach (['ordinal', 'source_ordinal', 'description', 'erp_unit_id', 'base_quantity'] as $field) {
                unset($line[$field]);
            }
            $payload['snapshot']['lines'][$index] = $line;
        }
        $journal = app(ErpOperationJournal::class);
        $canonicalJson = new \ReflectionMethod($journal, 'canonicalJson');
        $canonicalJson->setAccessible(true);
        $receipt->update([
            'canonical_version' => 'v1',
            'payload_hash' => hash('sha256', 'v1:'.$canonicalJson->invoke($journal, $payload)),
        ]);

        $this->send('purchases/'.$id.'/post', [], 'legacy-purchase-v1')->assertOk()->assertExactJson($response->json());
        $this->assertDatabaseCount('erp_stock_movements', 1);
        $this->assertDatabaseCount('erp_obligations', 1);
    }

    public function test_bounded_maximum_purchase_line_count_fits_idempotent_mutation_receipt(): void
    {
        $lines = array_fill(0, 500, ['erp_product_variant_id' => $this->variant->id, 'quantity' => '1', 'unit_cost' => '1']);
        $supplier = $this->supplier();
        $body = ['erp_establishment_id' => $this->establishment->id, 'erp_contact_id' => $supplier->id, 'lines' => $lines];
        $response = $this->send('purchases', $body, 'maximum-purchase-lines')->assertCreated();
        $this->assertCount(500, $response->json('data.lines'));
        $this->assertLessThan(1_048_576, strlen($response->getContent()));
        $this->assertSame(['id', 'erp_product_variant_id', 'quantity', 'unit_cost', 'line_total', 'unit_conversion_factor'], array_keys($response->json('data.lines.0')));
        $this->assertSame($response->json(), $this->send('purchases', $body, 'maximum-purchase-lines')->assertCreated()->json());
    }

    public function test_import_factory_creates_same_company_establishment_and_actor(): void
    {
        $import = ErpPurchaseImport::factory()->create();

        $this->assertDatabaseHas('erp_establishments', ['id' => $import->erp_establishment_id, 'erp_company_id' => $import->erp_company_id]);
        $this->assertDatabaseHas('users', ['id' => $import->actor_user_id]);
    }

    public function test_purchase_return_snapshot_uses_original_supplier_after_contact_changes(): void
    {
        $supplier = $this->supplier();
        $supplier->update(['name' => 'Original supplier']);
        $id = $this->send('purchases', ['erp_establishment_id' => $this->establishment->id, 'erp_contact_id' => $supplier->id, 'lines' => [['erp_product_variant_id' => $this->variant->id, 'quantity' => '1', 'unit_cost' => '10']]])->assertCreated()->json('data.id');
        $this->send('purchases/'.$id.'/post', [], 'purchase-post')->assertOk();
        $lineId = ErpPurchase::findOrFail($id)->lines()->sole()->id;
        $supplier->update(['name' => 'Changed supplier']);

        $returnId = $this->send('purchase-returns', ['erp_purchase_id' => $id, 'reason' => 'Supplier return', 'lines' => [['source_line_id' => $lineId, 'quantity' => '1']]])->assertCreated()->json('data.id');
        $return = ErpPurchaseReturn::findOrFail($returnId);

        $this->assertSame('Original supplier', $return->commercial_snapshot['party']['name']);
        $this->assertSame('Original supplier', ErpPurchase::findOrFail($id)->supplier_snapshot['name']);
    }
}
