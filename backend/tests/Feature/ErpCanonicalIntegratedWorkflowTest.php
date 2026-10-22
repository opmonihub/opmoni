<?php

namespace Tests\Feature;

use App\Erp\Accounting\ErpAccountingHandoffService;
use App\Erp\Fiscal\ErpFiscalPayloadBuilder;
use App\Models\Account;
use App\Models\Client;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpOfficeDelegation;
use App\Models\Erp\Finance\ErpCashSession;
use App\Models\Erp\Finance\ErpObligation;
use App\Models\Erp\Finance\ErpPayment;
use App\Models\Erp\Finance\ErpPaymentAllocation;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\Erp\Sales\ErpSale;
use App\Models\Erp\Stock\ErpPurchase;
use App\Models\Erp\Stock\ErpStockBalance;
use App\Models\Erp\Stock\ErpStockLot;
use App\Models\Erp\Stock\ErpStockMovement;
use App\Models\FiscalDocument;
use App\Tenant\CurrentTenant;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ApprovesErpAccountingMappings;
use Tests\Support\BuildsVerifiedErpFiscalEvidence;
use Tests\Support\ErpCommercialTestCase;

class ErpCanonicalIntegratedWorkflowTest extends ErpCommercialTestCase
{
    use ApprovesErpAccountingMappings;
    use BuildsVerifiedErpFiscalEvidence;

    public function test_xml_purchase_transfer_paid_sale_full_return_and_synthetic_authorized_input_handoff_reconcile(): void
    {
        config(['erp.release_enabled' => true]);
        Storage::fake('local');
        Storage::fake('fiscal');
        $this->freezeTime();
        $this->establishment->update(['cnpj' => $this->company->cnpj]);
        $supplier = $this->manualPurchaseSupplier();
        $supplier->update(['tax_id' => '11222333000181']);
        $preview = $this->send('purchases/xml-preview', ['erp_establishment_id' => $this->establishment->id, 'xml' => $this->purchaseXml()])
            ->assertOk()->assertJsonPath('data.errors', [])->json('data');
        $this->assertDatabaseCount('erp_purchases', 0);
        $this->assertDatabaseCount('erp_stock_movements', 0);
        $this->assertDatabaseCount('erp_obligations', 0);

        $choices = ['token' => $preview['token'], 'erp_establishment_id' => $this->establishment->id, 'erp_contact_id' => $supplier->id,
            'items' => [['line' => 1, 'erp_product_variant_id' => $this->variant->id, 'unit_conversion_factor' => '1.00000000']]];
        $confirmed = $this->send('purchases/xml-confirm', $choices, 'story-import-confirm')->assertCreated()
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.total_amount', '1.0000');
        $purchase = ErpPurchase::findOrFail($confirmed->json('data.id'));
        $this->assertDatabaseCount('erp_stock_movements', 0);
        $this->assertDatabaseCount('erp_obligations', 0);
        $this->send('purchases/'.$purchase->id.'/post', ['draft_revision' => 1], 'story-purchase-post')->assertOk()->assertJsonPath('data.status', 'posted');
        $receipt = ErpStockMovement::where('source_type', 'erp_purchase')->where('source_id', $purchase->id)->sole();
        $this->assertSame('in', $receipt->direction);
        $this->assertSame('1.0000', $receipt->quantity);
        $this->assertSame('1.0000', $receipt->total_cost);
        $payable = ErpObligation::where('source_type', 'erp_purchase')->where('source_id', $purchase->id)->sole();
        $this->assertSame('payable', $payable->direction);
        $this->assertSame('1.0000', $payable->amount);

        $destination = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $transferBody = ['from_establishment_id' => $this->establishment->id, 'to_establishment_id' => $destination->id,
            'erp_product_variant_id' => $this->variant->id, 'quantity' => '1', 'source_operation_key' => 'story-transfer'];
        $transfer = $this->send('stock/transfers', $transferBody, 'story-transfer')->assertCreated();
        $this->send('stock/transfers', $transferBody, 'story-transfer')->assertCreated()->assertExactJson($transfer->json());
        $this->assertDatabaseCount('erp_stock_movements', 3);
        $transferMovements = ErpStockMovement::where('source_type', 'erp_stock_transfer')->where('source_id', $transfer->json('data.id'))->orderBy('id')->get();
        $this->assertCount(2, $transferMovements);
        $this->assertSame(['out', 'in'], $transferMovements->pluck('direction')->all());
        $this->assertSame([$this->establishment->id, $destination->id], $transferMovements->pluck('erp_establishment_id')->all());
        $this->assertSame(['1.0000', '1.0000'], $transferMovements->pluck('quantity')->all());
        $this->assertSame(['1.0000', '1.0000'], $transferMovements->pluck('total_cost')->all());
        $transferredLot = ErpStockLot::where('erp_establishment_id', $destination->id)->sole();
        $this->assertSame('1.0000', $transferredLot->available_quantity);
        $this->assertSame('1.0000', $transferredLot->total_cost);
        $this->assertNotNull($transferredLot->parent_lot_id);

        $saleId = $this->send('sales', ['erp_establishment_id' => $destination->id, 'invoice_no' => 'story-sale',
            'lines' => [['erp_product_variant_id' => $this->variant->id, 'quantity' => '1', 'unit_price' => '30']]])
            ->assertCreated()->json('data.id');
        $this->send('sales/'.$saleId.'/post', ['draft_revision' => 1], 'story-sale-post')->assertOk()
            ->assertJsonPath('data.status', 'posted')->assertJsonPath('data.total_amount', '30.0000');
        $sale = ErpSale::findOrFail($saleId);
        $saleMovement = ErpStockMovement::where('source_type', 'erp_sale')->where('source_id', $saleId)->sole();
        $this->assertSame('out', $saleMovement->direction);
        $this->assertSame('1.0000', $saleMovement->total_cost);
        $this->assertSame('0.0000', ErpStockBalance::where('erp_establishment_id', $destination->id)->sole()->quantity_on_hand);
        $receivable = ErpObligation::where('source_type', 'erp_sale')->where('source_id', $saleId)->sole();
        $this->assertSame('receivable', $receivable->direction);
        $this->assertSame('30.0000', $receivable->amount);

        $register = $this->send('cash-registers', ['name' => 'Story register', 'erp_establishment_id' => $destination->id])->assertCreated()->json('data.id');
        $session = $this->send('cash-sessions', ['erp_cash_register_id' => $register, 'currency' => 'BRL', 'opening_amount' => '0'])->assertCreated()->json('data.id');
        $payment = $this->send('payments', ['amount' => '30', 'direction' => 'in', 'method' => 'cash', 'erp_establishment_id' => $destination->id, 'erp_cash_session_id' => $session])
            ->assertCreated()->json('data.id');
        $allocationId = $this->send('payments/'.$payment.'/allocate', ['erp_obligation_id' => $receivable->id, 'amount' => '30'])->assertOk()->json('data.id');
        $this->assertSame('0.0000', $receivable->fresh()->open_amount);
        $this->assertSame('30.0000', ErpPaymentAllocation::findOrFail($allocationId)->amount);
        $this->send('cash-sessions/'.$session.'/close', ['declared_closing_amount' => '30'], 'story-cash-close')->assertOk()
            ->assertJsonPath('data.expected_closing_amount', '30.0000')->assertJsonPath('data.closing_difference', '0.0000');
        $this->handoffAlreadyAuthorizedSyntheticInput($destination, $sale);
        $fiscalDocument = ErpFiscalDocument::sole();
        $originalFiscal = $fiscalDocument->getAttributes();
        $originalAttempts = $fiscalDocument->attempts()->orderBy('id')->get()->map->getAttributes()->all();
        $originalSale = $sale->fresh()->getAttributes();
        $originalReceivable = $receivable->fresh()->getAttributes();
        $originalPayment = ErpPayment::findOrFail($payment)->getAttributes();
        $originalAllocation = ErpPaymentAllocation::findOrFail($allocationId)->getAttributes();
        $originalCashSession = ErpCashSession::findOrFail($session)->getAttributes();

        $returnId = $this->send('sale-returns', ['erp_sale_id' => $saleId, 'reason' => 'Synthetic acceptance return', 'expected_total' => '30.0000',
            'lines' => [['source_line_id' => $sale->lines()->sole()->id, 'quantity' => '1.0000']]])->assertCreated()->json('data.id');
        $postedReturn = $this->send('sale-returns/'.$returnId.'/post', [], 'story-full-return')->assertOk()
            ->assertJsonPath('data.status', 'posted')->assertJsonPath('data.total_amount', '30.0000');
        $this->send('sale-returns/'.$returnId.'/post', [], 'story-full-return')->assertOk()->assertExactJson($postedReturn->json());
        $credit = ErpObligation::where('return_of_id', $receivable->id)->sole();
        $this->assertSame('payable', $credit->direction);
        $this->assertSame('30.0000', $credit->amount);
        $this->assertSame('30.0000', $credit->open_amount);
        $this->assertSame($originalSale, $sale->fresh()->getAttributes());
        $this->assertSame($originalReceivable, $receivable->fresh()->getAttributes());
        $this->assertSame($originalPayment, ErpPayment::findOrFail($payment)->getAttributes());
        $this->assertSame($originalAllocation, ErpPaymentAllocation::findOrFail($allocationId)->getAttributes());
        $this->assertSame($originalCashSession, ErpCashSession::findOrFail($session)->getAttributes());
        $this->assertDatabaseCount('erp_payments', 1);
        $this->assertDatabaseCount('erp_cash_movements', 1);
        $this->assertDatabaseCount('erp_stock_movements', 5);
        $returnMovement = ErpStockMovement::where('source_type', 'erp_sale_return')->where('source_id', $returnId)->sole();
        $this->assertSame('in', $returnMovement->direction);
        $this->assertSame('1.0000', $returnMovement->total_cost);
        $this->assertSame($saleMovement->id, $returnMovement->reversal_of_id);
        $this->assertSame('0.0000', ErpStockBalance::where('erp_establishment_id', $this->establishment->id)->sole()->quantity_on_hand);
        $this->assertSame('1.0000', ErpStockBalance::where('erp_establishment_id', $destination->id)->sole()->quantity_on_hand);

        $this->assertSame($originalFiscal, $fiscalDocument->fresh()->getAttributes());
        $this->assertSame($originalAttempts, $fiscalDocument->attempts()->orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame('authorized', $fiscalDocument->fresh()->status);
        $this->assertSame(0, $fiscalDocument->events()->count());

        $purchaseReport = $this->getJson('/api/v1/erp/reports/purchases')->assertOk()->assertJsonPath('data.totals.document_count', 1)
            ->assertJsonPath('data.totals.posted_total', '1.0000')->assertJsonPath('data.ledgers.documents.rows.0.id', $purchase->id);
        $this->assertSame($payable->id, $purchaseReport->json('data.ledgers.obligations.rows.0.id'));
        $this->getJson('/api/v1/erp/reports/sales')->assertOk()->assertJsonPath('data.totals.document_count', 1)
            ->assertJsonPath('data.totals.posted_total', '30.0000')->assertJsonPath('data.ledgers.documents.rows.0.id', $saleId)
            ->assertJsonPath('data.ledgers.returns.rows.0.id', $returnId)->assertJsonPath('data.ledgers.returns.totals.amount', '30.0000')
            ->assertJsonPath('data.ledgers.obligations.totals.gross_original_due', '0.0000')
            ->assertJsonPath('data.ledgers.obligations.totals.opposite_return_credit', '30.0000')->assertJsonPath('data.ledgers.obligations.totals.net_exposure', '-30.0000');
        $stockReport = $this->getJson('/api/v1/erp/reports/stock')->assertOk()->assertJsonPath('data.totals.quantity_on_hand', '1.0000')
            ->assertJsonPath('data.ledgers.lots.totals.known_cost_by_currency.BRL', '1.0000')->assertJsonPath('data.ledgers.lots.totals.unvalued_quantity', '0.0000')
            ->assertJsonPath('data.ledgers.movements.row_count', 5)->assertJsonPath('data.ledgers.movements.totals.quantity_in', '3.0000')
            ->assertJsonPath('data.ledgers.movements.totals.quantity_out', '2.0000')->assertJsonPath('data.ledgers.movements.totals.net_quantity', '1.0000');
        $this->assertSame(ErpStockMovement::orderBy('id')->pluck('id')->all(), array_column($stockReport->json('data.ledgers.movements.rows'), 'id'));
        $this->assertSame($destination->id, $stockReport->json('data.ledgers.lots.rows.0.erp_establishment_id'));
        $financial = $this->getJson('/api/v1/erp/reports/financial')->assertOk()->assertJsonPath('data.totals.obligations_amount', '61.0000')
            ->assertJsonPath('data.totals.obligations_open_amount', '31.0000')->assertJsonPath('data.totals.payments_posted_total', '30.0000')
            ->assertJsonPath('data.ledgers.payments.row_count', 1)->assertJsonPath('data.ledgers.allocations.row_count', 1)
            ->assertJsonPath('data.ledgers.cash.row_count', 1)->assertJsonPath('data.ledgers.cash.totals.net_amount', '30.0000');
        $this->assertSame($payment, $financial->json('data.ledgers.payments.rows.0.id'));
        $this->assertSame($allocationId, $financial->json('data.ledgers.allocations.rows.0.id'));
        $this->assertSame([$payable->id, $receivable->id, $credit->id], array_column($financial->json('data.ledgers.obligations.rows'), 'id'));
    }

    private function handoffAlreadyAuthorizedSyntheticInput(ErpEstablishment $issuer, ErpSale $sale): void
    {
        $office = Account::factory()->create();
        $client = resolve(CurrentTenant::class)->run($office->id, fn () => Client::factory()->company()->create(['account_id' => $office->id, 'tax_id' => $this->company->cnpj]));
        $delegation = ErpOfficeDelegation::factory()->create(['erp_company_id' => $this->company->id, 'account_id' => $office->id,
            'authorized_by_user_id' => $this->user->id, 'permission_allowlist' => ['erp.read']]);
        $mapping = $this->approveAccountingMapping($delegation, $issuer, $client);

        // Locally signed, schema-valid accepted evidence; no provider call.
        $bytes = '<nfeProc/>';
        $path = 'erp/fiscal/story-already-authorized-input.xml';
        Storage::disk('local')->put($path, $bytes);
        $document = ErpFiscalDocument::query()->create([
            'erp_company_id' => $this->company->id, 'erp_establishment_id' => $issuer->id, 'erp_sale_id' => $sale->id,
            'model' => '55', 'environment' => 'homologation', 'series' => 1, 'number' => 9001,
            'access_key' => '35251030288513000100550010000090011234567890', 'status' => 'authorized', 'authorized_at' => now(),
            'idempotency_key' => 'story-authorized-input', 'storage_path' => $path, 'artifact_byte_length' => strlen($bytes),
            'artifact_sha256' => hash('sha256', $bytes), 'commercial_snapshot' => ['total_amount' => $sale->total_amount],
        ]);
        $bytes = $this->attachVerifiedFiscalEvidence($document);
        $handoff = app(ErpAccountingHandoffService::class);
        $intent = $handoff->ensureDeliveryIntent($document, $this->user->id);
        $this->assertNotNull($intent);
        $delivered = $handoff->deliver($intent);
        $this->assertSame('delivered', $delivered->status);
        $this->assertSame($office->id, $delivered->account_id);
        $this->assertSame($client->id, $delivered->client_id);
        $this->assertSame($mapping->id, $delivered->mapping_id);
        $published = FiscalDocument::withoutGlobalScope('account')->sole();
        $this->assertSame($client->id, $published->client_id);
        $this->assertSame(hash('sha256', $bytes), $published->sha256);
        $this->assertSame($bytes, Storage::disk('fiscal')->get($published->storage_path));
        $history = $delivered->getAttributes();
        $this->assertSame($intent->id, $handoff->ensureDeliveryIntent($document, $this->user->id)->id);
        $this->assertSame($history, $handoff->deliver($delivered)->getAttributes());
        $this->assertSame($history, $handoff->retry($delivered)->getAttributes());
        $this->assertDatabaseCount('erp_accounting_deliveries', 1);
        $this->assertDatabaseCount('fiscal_documents', 1);
        $this->assertSame([$published->storage_path], Storage::disk('fiscal')->allFiles());
    }

    private function purchaseXml(): string
    {
        $key = app(ErpFiscalPayloadBuilder::class)->buildAccessKey(['uf' => 'SP', 'cnpj' => '11222333000181', 'model' => '55', 'series' => 1, 'number' => 123,
            'cnf' => '87654321', 'issued_at' => '2026-10-17T10:00:00-03:00']);
        $identification = '<cUF>'.substr($key, 0, 2).'</cUF><mod>55</mod><serie>1</serie><nNF>123</nNF><tpEmis>'.substr($key, 34, 1)
            .'</tpEmis><cNF>'.substr($key, 35, 8).'</cNF><cDV>'.substr($key, 43, 1).'</cDV>';

        return '<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe"><NFe><infNFe Id="NFe'.$key.'"><ide>'.$identification.'</ide><emit><CNPJ>11222333000181</CNPJ><xNome>Story supplier</xNome></emit>'
            .'<dest><CNPJ>30288513000100</CNPJ><xNome>Story company</xNome></dest><det nItem="1"><prod><cProd>001</cProd><xProd>Story goods</xProd><uCom>UN</uCom><qCom>1</qCom><vUnCom>1.00</vUnCom><vProd>1.00</vProd></prod></det>'
            .'<total><ICMSTot><vProd>1.00</vProd><vNF>1.00</vNF></ICMSTot></total></infNFe></NFe></nfeProc>';
    }
}
