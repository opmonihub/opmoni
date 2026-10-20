<?php

namespace Tests\Feature;

use App\Models\Erp\ErpOperationReceipt;
use App\Models\Erp\Finance\ErpObligation;
use App\Models\Erp\Sales\ErpSale;
use App\Models\Erp\Sales\ErpSaleReturn;
use App\Models\Erp\Sales\ErpSaleReturnLine;
use App\Models\Erp\Stock\ErpPurchase;
use App\Models\Erp\Stock\ErpStockBalance;
use App\Models\Erp\Stock\ErpStockMovement;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\ErpCommercialTestCase;

class ErpPartialReturnTest extends ErpCommercialTestCase
{
    public function test_partial_returns_create_opposite_credit_without_touching_paid_source(): void
    {
        $sale = $this->send('sales', ['erp_establishment_id' => $this->establishment->id, 'lines' => [['description' => 'Serviço', 'quantity' => '3', 'unit_price' => '3.3333', 'tax_amount' => '0.0001']]])->assertCreated()->json('data.id');
        $line = $this->getJson('/api/v1/erp/sales/'.$sale)->assertOk()->json('data.lines.0.id');
        $posted = $this->send('sales/'.$sale.'/post', ['draft_revision' => 1])->assertOk();
        $obligation = ErpObligation::where('source_type', 'erp_sale')->where('source_id', $sale)->firstOrFail();
        $payment = $this->send('payments', ['amount' => '10', 'direction' => 'in', 'method' => 'manual', 'erp_establishment_id' => $this->establishment->id])->assertCreated()->json('data.id');
        $this->send('payments/'.$payment.'/allocate', ['erp_obligation_id' => $obligation->id, 'amount' => '10'])->assertOk();
        $original = $obligation->fresh()->getAttributes();
        $source = ErpSale::findOrFail($sale)->getAttributes();
        foreach (['3.3333', '3.3333', '3.3334'] as $expected) {
            $return = $this->send('sale-returns', ['erp_sale_id' => $sale, 'reason' => 'Parcial', 'lines' => [['source_line_id' => $line, 'quantity' => '1']]])->assertCreated()->json('data.id');
            $this->send('sale-returns/'.$return.'/post', [])->assertOk()->assertJsonPath('data.total_amount', $expected);
        }
        $this->assertSame($original, $obligation->fresh()->getAttributes());
        $this->assertSame($source, ErpSale::findOrFail($sale)->getAttributes());
        $this->assertSame('10.0000', bcadd((string) ErpObligation::where('return_of_id', $obligation->id)->sum('amount'), '0', 4));
        $this->assertDatabaseCount('erp_payments', 1);
        $this->assertDatabaseCount('erp_cash_movements', 0);
        $this->send('sale-returns', ['erp_sale_id' => $sale, 'reason' => 'Excesso', 'lines' => [['source_line_id' => $line, 'quantity' => '1']]])->assertUnprocessable()->assertJsonValidationErrors('lines.0.quantity');
    }

    private function postedService(string $quantity = '3', string $price = '3.3333', array $extra = []): array
    {
        $id = $this->send('sales', ['erp_establishment_id' => $this->establishment->id, 'lines' => [$extra + ['description' => 'Serviço', 'quantity' => $quantity, 'unit_price' => $price]]])->assertCreated()->json('data.id');
        $this->send('sales/'.$id.'/post', ['draft_revision' => 1])->assertOk();

        return [$id, $this->getJson('/api/v1/erp/sales/'.$id)->assertOk()->json('data.lines.0.id')];
    }

    private function draftReturn(int $source, int $line, string $quantity, string $kind = 'sale', array $extra = []): int
    {
        return $this->send($kind.'-returns', $extra + ['erp_'.$kind.'_id' => $source, 'reason' => 'Devolução parcial', 'lines' => [['source_line_id' => $line, 'quantity' => $quantity]]])->assertCreated()->json('data.id');
    }

    private function purchase(string $quantity, string $cost): array
    {
        $id = $this->send('purchases', ['erp_establishment_id' => $this->establishment->id, 'lines' => [['erp_product_variant_id' => $this->variant->id, 'quantity' => $quantity, 'unit_cost' => $cost]]])->assertCreated()->json('data.id');
        $this->send('purchases/'.$id.'/post', [])->assertOk();

        return [$id, ErpPurchase::findOrFail($id)->lines()->firstOrFail()->id];
    }

    public function test_tiny_net_discount_tax_components_conserve_independently_and_stale_drafts_do_not_reserve(): void
    {
        [$source, $line] = $this->postedService('3', '0.0001', ['discount_amount' => '0.0001', 'tax_amount' => '0.0001']);
        $first = $this->draftReturn($source, $line, '1');
        $second = $this->draftReturn($source, $line, '1');
        $third = $this->draftReturn($source, $line, '1');
        $this->send('sale-returns/'.$first.'/post', ['expected_total' => '0'])->assertOk()->assertJsonPath('data.total_amount', '0.0000')->assertJsonPath('data.financial_disposition', 'zero_consideration');
        $this->send('sale-returns/'.$second.'/post', ['expected_total' => '0'])->assertUnprocessable()->assertJsonValidationErrors('expected_total');
        $this->send('sale-returns/'.$second.'/post', [])->assertOk()->assertJsonPath('data.total_amount', '0.0001');
        $this->send('sale-returns/'.$third.'/post', [])->assertOk()->assertJsonPath('data.total_amount', '0.0002');
        $lines = ErpSaleReturnLine::all();
        foreach (['subtotal_amount' => '0.0003', 'discount_amount' => '0.0001', 'tax_amount' => '0.0001', 'total_amount' => '0.0003'] as $field => $expected) {
            $this->assertSame($expected, $lines->reduce(fn ($sum, $row) => bcadd($sum, $row[$field], 4), '0.0000'));
        }
        $this->assertDatabaseCount('erp_obligations', 3);
        $this->assertSame(['1.0000', '2.0000', '3.0000'], $lines->map(fn ($piece) => $piece->source_snapshot['cumulative_quantity'])->all());
        $this->getJson('/api/v1/erp/sales/'.$source)->assertOk()->assertJsonPath('data.finance.gross_original_due', '0.0003')->assertJsonPath('data.finance.opposite_return_credit', '0.0003')->assertJsonPath('data.finance.net_exposure', '0.0000')->assertJsonPath('data.returnable_lines.0.remaining_quantity', '0.0000');
    }

    public function test_purchase_partial_returns_restore_exact_credit_and_stock_cost_without_changing_source(): void
    {
        [$source, $line] = $this->purchase('3', '10');
        $original = ErpPurchase::findOrFail($source)->getAttributes();
        $obligation = ErpObligation::where('source_type', 'erp_purchase')->where('source_id', $source)->firstOrFail();
        $before = $obligation->getAttributes();
        foreach (['1', '2'] as $quantity) {
            $id = $this->draftReturn($source, $line, $quantity, 'purchase');
            $this->send('purchase-returns/'.$id.'/post', [])->assertOk()->assertJsonPath('data.total_amount', bcmul($quantity, '10', 4));
        }
        $this->assertSame($before, $obligation->fresh()->getAttributes());
        $this->assertSame($original, ErpPurchase::findOrFail($source)->getAttributes());
        $this->assertSame('0.0000', ErpStockBalance::firstOrFail()->quantity_on_hand);
        $credits = ErpObligation::where('return_of_id', $obligation->id)->get();
        $this->assertSame(['receivable'], $credits->pluck('direction')->unique()->values()->all());
        $this->assertSame('30.0000', $credits->reduce(fn ($sum, $row) => bcadd($sum, $row->amount, 4), '0.0000'));
    }

    public function test_consumed_purchase_cannot_borrow_return_entitlement_from_a_new_receipt(): void
    {
        [$source, $line] = $this->purchase('1', '10');
        $sale = $this->send('sales', ['erp_establishment_id' => $this->establishment->id, 'lines' => [['erp_product_variant_id' => $this->variant->id, 'quantity' => '1']]])->assertCreated()->json('data.id');
        $this->send('sales/'.$sale.'/post', ['draft_revision' => 1])->assertOk();
        $this->purchase('1', '20');
        $id = $this->draftReturn($source, $line, '1', 'purchase');
        $this->send('purchase-returns/'.$id.'/post', [])->assertUnprocessable();
        $this->assertSame('1.0000', ErpStockBalance::firstOrFail()->quantity_on_hand);
        $this->assertDatabaseHas('erp_purchase_returns', ['id' => $id, 'status' => 'draft']);
    }

    public function test_stock_return_zero_consideration_and_late_credit_failure_are_atomic(): void
    {
        $this->purchase('2', '7');
        $sale = $this->send('sales', ['erp_establishment_id' => $this->establishment->id, 'lines' => [['erp_product_variant_id' => $this->variant->id, 'quantity' => '2', 'unit_price' => '10', 'discount_amount' => '20']]])->assertCreated()->json('data.id');
        $this->send('sales/'.$sale.'/post', ['draft_revision' => 1])->assertOk()->assertJsonPath('data.financial_disposition', 'zero_consideration');
        $line = ErpSale::findOrFail($sale)->lines()->firstOrFail()->id;
        $id = $this->draftReturn($sale, $line, '1');
        $this->send('sale-returns/'.$id.'/post', [])->assertOk()->assertJsonPath('data.financial_disposition', 'zero_consideration');
        $this->assertSame('1.0000', ErpStockBalance::firstOrFail()->quantity_on_hand);
        $this->assertSame(0, ErpObligation::where('source_type', 'erp_sale_return')->count());
        [$source, $serviceLine] = $this->postedService('1', '10');
        $next = $this->draftReturn($source, $serviceLine, '1');
        ErpOperationReceipt::created(function ($receipt): void {
            if ($receipt->operation === 'erp.sale-returns.post') {
                abort(503, 'After credit and receipt');
            }
        });
        try {
            $this->send('sale-returns/'.$next.'/post', [], 'return-atomic')->assertStatus(503);
        } finally {
            ErpOperationReceipt::flushEventListeners();
        }
        $this->assertDatabaseHas('erp_sale_returns', ['id' => $next, 'status' => 'draft']);
        $this->assertSame(0, ErpObligation::where('source_type', 'erp_sale_return')->count());
        $this->send('sale-returns/'.$next.'/post', [], 'return-atomic')->assertOk();
        $this->assertSame(1, ErpObligation::where('source_type', 'erp_sale_return')->count());
    }

    public function test_return_source_line_company_and_document_are_durable_database_invariants(): void
    {
        [$source,$line] = $this->postedService('1', '10');
        [$other,$otherLine] = $this->postedService('1', '10');
        $id = $this->draftReturn($source, $line, '1');
        $original = ErpSaleReturnLine::where('erp_sale_return_id', $id)->firstOrFail();
        foreach ([['source_line_id' => $otherLine], ['source_document_id' => $other], ['erp_company_id' => 999999]] as $changes) {
            try {
                DB::transaction(fn () => DB::table('erp_sale_return_lines')->where('id', $original->id)->update($changes));
                $this->fail('Return identity mutation must violate relational constraints.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('FOREIGN KEY', $exception->getMessage());
            }
        }
        $this->assertSame($original->getAttributes(), $original->fresh()->getAttributes());
    }

    public function test_legacy_unallocated_returns_and_mismatched_source_totals_require_reconciliation(): void
    {
        [$source,$line] = $this->postedService('2', '10');
        ErpSaleReturn::create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $this->establishment->id, 'erp_sale_id' => $source, 'status' => 'posted', 'total_amount' => '5']);
        $body = ['erp_sale_id' => $source, 'reason' => 'Partial', 'lines' => [['source_line_id' => $line, 'quantity' => '1']]];
        $this->send('sale-returns', $body)->assertConflict()->assertJsonPath('code', 'legacy_operation_requires_reconciliation');
        [$other,$otherLine] = $this->postedService('1', '10');
        ErpSale::findOrFail($other)->update(['total_amount' => '11']);
        $body['erp_sale_id'] = $other;
        $body['lines'][0]['source_line_id'] = $otherLine;
        $this->send('sale-returns', $body)->assertConflict()->assertJsonPath('code', 'legacy_operation_requires_reconciliation');
    }

    public function test_commercial_partial_deficit_returns_cancel_then_restore_actual_settled_cost(): void
    {
        $this->company->update(['operating_settings' => ['stock_overselling_enabled' => true]]);
        $sale = $this->send('sales', ['erp_establishment_id' => $this->establishment->id, 'lines' => [['erp_product_variant_id' => $this->variant->id, 'quantity' => '3', 'unit_price' => '10']]])->assertCreated()->json('data.id');
        $this->send('sales/'.$sale.'/post', ['draft_revision' => 1])->assertOk();
        $line = ErpSale::findOrFail($sale)->lines()->firstOrFail()->id;
        $first = $this->draftReturn($sale, $line, '1');
        $this->send('sale-returns/'.$first.'/post', [])->assertOk()->assertJsonPath('data.total_amount', '10.0000');
        $this->assertSame('-2.0000', ErpStockBalance::firstOrFail()->quantity_on_hand);
        $this->purchase('2', '7');
        $this->assertSame('0.0000', ErpStockBalance::firstOrFail()->quantity_on_hand);
        $remaining = $this->draftReturn($sale, $line, '2');
        $this->send('sale-returns/'.$remaining.'/post', [])->assertOk()->assertJsonPath('data.total_amount', '20.0000');
        $movement = ErpStockMovement::where('source_type', 'erp_sale_return')->where('source_id', $remaining)->firstOrFail();
        $this->assertSame('14.0000', $movement->known_cost);
        $this->assertSame('2.0000', ErpStockBalance::firstOrFail()->quantity_on_hand);
        $this->assertSame('30.0000', ErpObligation::where('source_type', 'erp_sale')->where('source_id', $sale)->firstOrFail()->open_amount);
    }

    public function test_unproved_legacy_credit_and_mismatched_principal_require_reconciliation(): void
    {
        [$source, $line] = $this->postedService('2', '10');
        $original = ErpObligation::where('source_type', 'erp_sale')->where('source_id', $source)->firstOrFail();
        ErpObligation::create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $this->establishment->id, 'direction' => 'payable', 'category' => 'return', 'amount' => '1', 'open_amount' => '1', 'currency' => 'BRL', 'status' => 'open', 'source_type' => 'erp_sale_return', 'source_id' => 999999, 'return_of_id' => $original->id]);
        $return = $this->draftReturn($source, $line, '1');
        $this->send('sale-returns/'.$return.'/post', [])->assertConflict()->assertJsonPath('code', 'legacy_operation_requires_reconciliation');
        $this->assertDatabaseHas('erp_sale_returns', ['id' => $return, 'status' => 'draft']);
        [$other,$otherLine] = $this->postedService('2', '10');
        ErpObligation::where('source_type', 'erp_sale')->where('source_id', $other)->update(['amount' => '21']);
        $return = $this->draftReturn($other, $otherLine, '1');
        $this->send('sale-returns/'.$return.'/post', [])->assertConflict()->assertJsonPath('code', 'legacy_operation_requires_reconciliation');
        $this->assertSame(1, ErpObligation::where('return_of_id', $original->id)->count());
    }
}
