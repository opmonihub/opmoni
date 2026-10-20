<?php

namespace Tests\Feature;

use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpDiscountRule;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpOperationReceipt;
use App\Models\Erp\ErpPriceGroup;
use App\Models\Erp\ErpTaxRate;
use App\Models\Erp\ErpUnit;
use App\Models\Erp\Finance\ErpObligation;
use App\Models\Erp\Sales\ErpSale;
use App\Models\Erp\Sales\ErpSaleReturn;
use App\Models\Erp\Stock\ErpPurchaseReturn;
use Illuminate\Support\Facades\DB;
use Tests\Support\ErpCommercialTestCase;

class ErpCommercialCorrectionsTest extends ErpCommercialTestCase
{
    private function service(string $kind = 'sales', string $quantity = '2', string $price = '10'): array
    {
        return $this->send($kind, ['erp_establishment_id' => $this->establishment->id, 'lines' => [['description' => 'Service', 'quantity' => $quantity, 'unit_price' => $price]]])->assertCreated()->json('data');
    }

    private function posted(): array
    {
        $sale = $this->service();
        $this->send('sales/'.$sale['id'].'/post', ['draft_revision' => 1])->assertOk();
        $model = ErpSale::findOrFail($sale['id']);

        return [$model, $model->lines()->firstOrFail(), ErpObligation::where('source_type', 'erp_sale')->where('source_id', $model->id)->firstOrFail()];
    }

    private function returnBody($sale, $line, string $quantity = '1'): array
    {
        return ['erp_sale_id' => $sale->id, 'reason' => 'Correction', 'lines' => [['source_line_id' => $line->id, 'quantity' => $quantity]]];
    }

    public function test_f1_unproved_legacy_lines_make_read_quantities_and_finance_unavailable_without_rewriting_history(): void
    {
        [$sale, $line, $obligation] = $this->posted();
        ErpSaleReturn::create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $this->establishment->id, 'erp_sale_id' => $sale->id, 'status' => 'posted', 'total_amount' => '5']);
        $before = DB::table('erp_sale_returns')->get()->toJson();
        $this->getJson('/api/v1/erp/sales/'.$sale->id)->assertOk()->assertJsonPath('data.needs_reconciliation', true)->assertJsonPath('data.returnability.needs_reconciliation', true)->assertJsonPath('data.returnable_lines.0.remaining_quantity', null)->assertJsonPath('data.returnable_lines.0.returned_quantity', null)->assertJsonPath('data.finance.net_exposure', null)->assertJsonPath('data.finance.needs_reconciliation', true);
        $this->send('sale-returns', $this->returnBody($sale, $line))->assertConflict()->assertJsonPath('code', 'legacy_operation_requires_reconciliation');
        $this->assertSame($before, DB::table('erp_sale_returns')->get()->toJson());
    }

    public function test_f1_financial_incoherence_is_not_presented_as_opposite_credit_or_net_exposure(): void
    {
        foreach (['principal', 'currency', 'orphan', 'direction', 'missing_credit', 'credit_amount'] as $fault) {
            [$sale,$line,$original] = $this->posted();
            if (in_array($fault, ['direction', 'missing_credit', 'credit_amount'], true)) {
                $return = $this->send('sale-returns', $this->returnBody($sale, $line))->assertCreated()->json('data.id');
                $this->send('sale-returns/'.$return.'/post', [])->assertOk();
                $credit = ErpObligation::where('return_of_id', $original->id)->firstOrFail();
                if ($fault === 'direction') {
                    $credit->update(['direction' => 'receivable']);
                }
                if ($fault === 'credit_amount') {
                    $credit->update(['amount' => '9']);
                }
                if ($fault === 'missing_credit') {
                    $credit->update(['return_of_id' => null]);
                }
            } elseif ($fault === 'principal') {
                $original->update(['amount' => '21']);
            } elseif ($fault === 'currency') {
                $original->update(['currency' => 'USD']);
            } else {
                ErpObligation::create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $this->establishment->id, 'direction' => 'payable', 'category' => 'return', 'amount' => '1', 'open_amount' => '1', 'currency' => 'BRL', 'status' => 'open', 'source_type' => 'erp_sale_return', 'source_id' => 999999, 'return_of_id' => $original->id]);
            }
            $this->getJson('/api/v1/erp/sales/'.$sale->id)->assertOk()->assertJsonPath('data.finance.needs_reconciliation', true)->assertJsonPath('data.finance.opposite_return_credit', null)->assertJsonPath('data.finance.net_exposure', null);
        }
    }

    public function test_f2_equal_grand_total_with_unallocated_header_components_blocks_store_and_post(): void
    {
        [$sale,$line] = $this->posted();
        $body = $this->returnBody($sale, $line);
        $draft = $this->send('sale-returns', $body)->assertCreated()->json('data.id');
        $sale->update(['discount_amount' => '1', 'tax_amount' => '1']);
        $before = DB::table('erp_operation_receipts')->count();
        $this->send('sale-returns', $body)->assertConflict()->assertJsonPath('code', 'legacy_operation_requires_reconciliation');
        $this->send('sale-returns/'.$draft.'/post', [])->assertConflict()->assertJsonPath('code', 'legacy_operation_requires_reconciliation');
        $this->assertSame($before, DB::table('erp_operation_receipts')->count());
        $this->assertDatabaseHas('erp_sale_returns', ['id' => $draft, 'status' => 'draft']);
        $this->assertSame(0, ErpObligation::where('source_type', 'erp_sale_return')->count());
    }

    public function test_f3_selected_tax_rate_uses_final_header_discounted_base_in_every_draft_domain(): void
    {
        $rate = ErpTaxRate::factory()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $this->establishment->id, 'rate' => '10']);
        $rule = ErpDiscountRule::factory()->create(['erp_company_id' => $this->company->id, 'kind' => 'percentage', 'value' => '50', 'conditions' => [], 'coupon_code' => null]);
        foreach (['quotes', 'orders', 'sales'] as $domain) {
            foreach ([['discount' => ['discount_type' => 'fixed', 'discount_amount' => '50']], ['discount' => ['discount_type' => 'percentage', 'discount_amount' => '50']], ['erp_discount_rule_id' => $rule->id]] as $header) {
                $body = $header + ['erp_establishment_id' => $this->establishment->id, 'lines' => [['description' => 'Tax base', 'quantity' => '1', 'unit_price' => '100', 'erp_tax_rate_id' => $rate->id, 'tax_amount' => '5']], 'tax_amount' => '5', 'expected_total' => '55'];
                $id = $this->send($domain, $body)->assertCreated()->assertJsonPath('data.total_amount', '55.0000')->json('data.id');
                $this->getJson('/api/v1/erp/'.$domain.'/'.$id)->assertOk()->assertJsonPath('data.lines.0.product_snapshot.amounts.net_amount', '50.0000')->assertJsonPath('data.lines.0.tax_amount', '5.0000');
            }
        }
    }

    public function test_f4_original_receipt_scope_survives_move_and_old_reference_fallback_denies_hidden_original(): void
    {
        $a = $this->establishment;
        $b = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $body = ['erp_establishment_id' => $a->id, 'lines' => [['description' => 'Original scope', 'quantity' => '1', 'unit_price' => '10']]];
        $id = $this->send('sales', $body, 'scope-store')->assertCreated()->json('data.id');
        $move = ['erp_establishment_id' => $b->id];
        $this->patchJson('/api/v1/erp/sales/'.$id, $move, ['Idempotency-Key' => 'scope-move'])->assertOk();
        // Simulate a preserved Task9 receipt without the new immutable refs.
        $receipt = ErpOperationReceipt::where('operation', 'erp.sales.store')->firstOrFail();
        $receipt->update(['resource_refs' => ['resource_id' => $id]]);
        $member = ErpCompanyMembership::where('user_id', $this->user->id)->firstOrFail();
        $member->update(['role' => 'operator', 'access_all_establishments' => false, 'action_permissions' => ['erp.sales.*', 'erp.operations.lookup', 'erp.sales.price.override']]);
        $member->establishments()->attach($b->id, ['erp_company_id' => $this->company->id]);
        $this->send('sales', $body, 'scope-store')->assertNotFound();
        $this->patchJson('/api/v1/erp/sales/'.$id, $move, ['Idempotency-Key' => 'scope-move'])->assertNotFound();
        $this->getJson('/api/v1/erp/operations/lookup?operation=erp.sales.store', ['Idempotency-Key' => 'scope-store'])->assertNotFound();
        $this->patchJson('/api/v1/erp/sales/'.$id, [], ['Idempotency-Key' => 'healthy-b'])->assertOk();
        $this->patchJson('/api/v1/erp/sales/'.$id, [], ['Idempotency-Key' => 'healthy-b'])->assertOk();
    }

    public function test_f4_ancestor_scope_filters_detail_and_list_before_pagination(): void
    {
        $quote = $this->service('quotes');
        $order = $this->send('quotes/'.$quote['id'].'/convert', ['draft_revision' => 1])->assertOk()->json('data.order.id');
        $b = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $this->patchJson('/api/v1/erp/orders/'.$order, ['erp_establishment_id' => $b->id], ['Idempotency-Key' => 'move-order'])->assertOk();
        $member = ErpCompanyMembership::where('user_id', $this->user->id)->firstOrFail();
        $member->update(['role' => 'operator', 'access_all_establishments' => false, 'action_permissions' => ['erp.orders.*', 'erp.sales.price.override']]);
        $member->establishments()->attach($b->id, ['erp_company_id' => $this->company->id]);
        $this->getJson('/api/v1/erp/orders/'.$order)->assertNotFound();
        $this->getJson('/api/v1/erp/orders?per_page=1')->assertOk()->assertJsonPath('meta.total', 0)->assertJsonCount(0, 'data');
        $this->send('orders/'.$order.'/convert', ['draft_revision' => 2])->assertNotFound();
    }

    public function test_f5_omitted_lines_preserve_accepted_price_factor_snapshots_and_ids_then_explicit_lines_refresh(): void
    {
        $this->variant->update(['sale_price' => '10']);
        $group = ErpPriceGroup::factory()->create(['erp_company_id' => $this->company->id, 'is_default' => true, 'percentage_adjustment' => '0']);
        $unit = ErpUnit::factory()->create(['erp_company_id' => $this->company->id, 'base_unit_id' => $this->variant->product->erp_unit_id, 'conversion_factor' => '2.00000000', 'allow_decimal' => true]);
        $quote = $this->send('quotes', ['erp_establishment_id' => $this->establishment->id, 'lines' => [['erp_product_variant_id' => $this->variant->id, 'erp_unit_id' => $unit->id, 'quantity' => '1']]])->assertCreated()->json('data.id');
        $order = $this->send('quotes/'.$quote.'/convert', ['draft_revision' => 1])->assertOk()->json('data.order.id');
        $this->variant->update(['sale_price' => '20']);
        $group->update(['percentage_adjustment' => '50', 'is_default' => false]);
        $unit->update(['conversion_factor' => '3.00000000']);
        $this->variant->product->update(['name' => 'Changed current product', 'sale_price' => '999']);
        ErpPriceGroup::factory()->create(['erp_company_id' => $this->company->id, 'is_default' => true, 'percentage_adjustment' => '75']);
        foreach (['quotes' => $quote, 'orders' => $order] as $domain => $id) {
            if ($domain === 'quotes') {
                continue;
            }
            $before = $this->getJson('/api/v1/erp/'.$domain.'/'.$id)->assertOk()->json('data.lines');
            $this->patchJson('/api/v1/erp/'.$domain.'/'.$id, [], ['Idempotency-Key' => 'no-op-'.$domain])->assertOk()->assertJsonPath('data.total_amount', '20.0000');
            $this->patchJson('/api/v1/erp/'.$domain.'/'.$id, ['currency' => 'BRL'], ['Idempotency-Key' => 'header-'.$domain])->assertOk()->assertJsonPath('data.total_amount', '20.0000');
            $this->assertSame($before, $this->getJson('/api/v1/erp/'.$domain.'/'.$id)->assertOk()->json('data.lines'));
            $this->patchJson('/api/v1/erp/'.$domain.'/'.$id, ['lines' => [['id' => $before[0]['id'], 'erp_product_variant_id' => $this->variant->id, 'erp_unit_id' => $unit->id, 'quantity' => '1']]], ['Idempotency-Key' => 'refresh-'.$domain])->assertOk()->assertJsonPath('data.total_amount', '105.0000');
        }
    }

    public function test_f6_derived_unit_price_width_is_checked_independently_from_gross_before_any_write(): void
    {
        $this->variant->update(['sale_price' => '9999999999999999']);
        $unit = ErpUnit::factory()->create(['erp_company_id' => $this->company->id, 'base_unit_id' => $this->variant->product->erp_unit_id, 'conversion_factor' => '1000.00000000', 'allow_decimal' => true]);
        $body = ['erp_establishment_id' => $this->establishment->id, 'erp_price_group_id' => null, 'lines' => [['erp_product_variant_id' => $this->variant->id, 'erp_unit_id' => $unit->id, 'quantity' => '0.0001']]];
        $this->send('sales', $body)->assertUnprocessable()->assertJsonValidationErrors('lines.0.unit_price');
        foreach (['erp_sales', 'erp_commercial_number_sequences', 'erp_operation_receipts'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->variant->update(['sale_price' => '99999999999999']);
        $unit->update(['conversion_factor' => '10000.00000000']);
        $this->send('sales', $body)->assertCreated()->assertJsonPath('data.total_amount', '99999999999999.0000');
    }

    public function test_f1_supplier_legacy_projection_and_proved_partial_full_zero_controls(): void
    {
        $purchase = $this->send('purchases', ['erp_establishment_id' => $this->establishment->id, 'lines' => [['erp_product_variant_id' => $this->variant->id, 'quantity' => '2', 'unit_cost' => '7']]])->assertCreated()->json('data.id');
        $this->send('purchases/'.$purchase.'/post', [])->assertOk();
        $legacy = ErpPurchaseReturn::create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $this->establishment->id, 'erp_purchase_id' => $purchase, 'status' => 'posted', 'total_amount' => '1']);
        $before = $legacy->fresh()->getAttributes();
        $this->getJson('/api/v1/erp/purchase-returns/'.$legacy->id)->assertOk()->assertJsonPath('data.returnability.needs_reconciliation', true)->assertJsonPath('data.returnability.reconciliation_reason', 'legacy_return_lines_missing')->assertJsonPath('data.returnable_lines.0.remaining_quantity', null)->assertJsonPath('data.finance.net_exposure', null);
        $this->assertSame($before, $legacy->fresh()->getAttributes());
        [$sale,$line] = $this->posted();
        $first = $this->send('sale-returns', $this->returnBody($sale, $line))->assertCreated()->json('data.id');
        $this->send('sale-returns/'.$first.'/post', [])->assertOk();
        $this->getJson('/api/v1/erp/sale-returns/'.$first)->assertOk()->assertJsonPath('data.returnability.needs_reconciliation', false)->assertJsonPath('data.returnable_lines.0.remaining_quantity', '1.0000')->assertJsonPath('data.finance.net_exposure', '10.0000');
        $second = $this->send('sale-returns', $this->returnBody($sale, $line))->assertCreated()->json('data.id');
        $this->send('sale-returns/'.$second.'/post', [])->assertOk();
        $this->getJson('/api/v1/erp/sales/'.$sale->id)->assertOk()->assertJsonPath('data.returnable_lines.0.remaining_quantity', '0.0000')->assertJsonPath('data.finance.net_exposure', '0.0000')->assertJsonPath('data.finance.needs_reconciliation', false);
        $zero = $this->service('sales', '1', '0');
        $this->send('sales/'.$zero['id'].'/post', ['draft_revision' => 1])->assertOk();
        $this->getJson('/api/v1/erp/sales/'.$zero['id'])->assertOk()->assertJsonPath('data.finance.net_exposure', '0.0000')->assertJsonPath('data.finance.opposite_return_credit', '0.0000')->assertJsonPath('data.finance.needs_reconciliation', false);
    }

    public function test_f3_tax_and_discount_residuals_reconcile_and_wrong_final_tax_assertion_is_atomic(): void
    {
        $rate = ErpTaxRate::factory()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $this->establishment->id, 'rate' => '10']);
        $body = ['erp_establishment_id' => $this->establishment->id, 'discount' => ['discount_type' => 'fixed', 'discount_amount' => '1'], 'lines' => array_fill(0, 3, ['description' => 'Residual', 'quantity' => '1', 'unit_price' => '1', 'erp_tax_rate_id' => $rate->id]), 'expected_total' => '2.1998'];
        $id = $this->send('quotes', $body)->assertCreated()->json('data.id');
        $detail = $this->getJson('/api/v1/erp/quotes/'.$id)->assertOk()->json('data');
        $this->assertSame(['0.3333', '0.3333', '0.3334'], array_column($detail['lines'], 'discount_amount'));
        $this->assertSame(['0.0666', '0.0666', '0.0666'], array_column($detail['lines'], 'tax_amount'));
        $body['lines'][0]['tax_amount'] = '0.1';
        $this->send('quotes', $body)->assertUnprocessable()->assertJsonValidationErrors('lines.0.tax_amount');
        $this->assertDatabaseCount('erp_quotes', 1);
    }

    public function test_f5_noop_and_header_adjustments_preserve_unit_price_provenance_and_only_refresh_intended_components(): void
    {
        $this->variant->update(['sale_price' => '100']);
        $rate = ErpTaxRate::factory()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $this->establishment->id, 'rate' => '10']);
        foreach (['quotes', 'orders', 'sales'] as $kind) {
            $id = $this->send($kind, ['erp_establishment_id' => $this->establishment->id, 'lines' => [['erp_product_variant_id' => $this->variant->id, 'quantity' => '1', 'erp_tax_rate_id' => $rate->id]]])->assertCreated()->json('data.id');
            $before = $this->getJson('/api/v1/erp/'.$kind.'/'.$id)->assertOk()->json('data.lines');
            $this->variant->update(['sale_price' => '200']);
            $rate->update(['rate' => '30']);
            $this->patchJson('/api/v1/erp/'.$kind.'/'.$id, [], ['Idempotency-Key' => 'retained-noop-'.$kind])->assertOk()->assertJsonPath('data.total_amount', '110.0000');
            $this->assertSame($before, $this->getJson('/api/v1/erp/'.$kind.'/'.$id)->assertOk()->json('data.lines'));
            $this->patchJson('/api/v1/erp/'.$kind.'/'.$id, ['discount' => ['discount_type' => 'fixed', 'discount_amount' => '50'], 'expected_total' => '55'], ['Idempotency-Key' => 'retained-discount-'.$kind])->assertOk()->assertJsonPath('data.total_amount', '55.0000');
            $after = $this->getJson('/api/v1/erp/'.$kind.'/'.$id)->assertOk()->json('data.lines.0');
            $this->assertSame($before[0]['id'], $after['id']);
            $this->assertSame($before[0]['product_snapshot']['pricing'], $after['product_snapshot']['pricing']);
            $this->assertSame('100.0000', $after['unit_price']);
            $this->assertSame('5.0000', $after['tax_amount']);
            $this->variant->update(['sale_price' => '100']);
            $rate->update(['rate' => '10']);
        }
    }

    public function test_f4_sale_and_return_list_keep_quote_ancestor_scope_before_totals(): void
    {
        $quote = $this->service('quotes');
        $order = $this->send('quotes/'.$quote['id'].'/convert', ['draft_revision' => 1])->assertOk()->json('data.order.id');
        $sale = $this->send('orders/'.$order.'/convert', ['draft_revision' => 1])->assertOk()->json('data.sale.id');
        $b = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $this->patchJson('/api/v1/erp/sales/'.$sale, ['erp_establishment_id' => $b->id], ['Idempotency-Key' => 'sale-move'])->assertOk();
        $this->send('sales/'.$sale.'/post', ['draft_revision' => 2])->assertOk();
        $model = ErpSale::findOrFail($sale);
        $line = $model->lines()->firstOrFail();
        $return = $this->send('sale-returns', $this->returnBody($model, $line))->assertCreated()->json('data.id');
        $member = ErpCompanyMembership::where('user_id', $this->user->id)->firstOrFail();
        $member->update(['role' => 'operator', 'access_all_establishments' => false, 'action_permissions' => ['erp.sales.*', 'erp.sale-returns.*']]);
        $member->establishments()->attach($b->id, ['erp_company_id' => $this->company->id]);
        $this->getJson('/api/v1/erp/sales/'.$sale)->assertNotFound();
        $this->getJson('/api/v1/erp/sales?per_page=1')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/erp/sale-returns/'.$return)->assertNotFound();
        $this->getJson('/api/v1/erp/sale-returns?per_page=1')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_f2_coherent_legacy_line_components_remain_returnable_without_backfilling_source(): void
    {
        [$sale,$line] = $this->posted();
        $sale->update(['draft_revision' => null, 'commercial_snapshot' => null]);
        $line->update(['product_snapshot' => null, 'gross_amount' => null]);
        $beforeSale = $sale->fresh()->getAttributes();
        $beforeLine = $line->fresh()->getAttributes();
        $return = $this->send('sale-returns', $this->returnBody($sale, $line))->assertCreated()->json('data.id');
        $this->send('sale-returns/'.$return.'/post', [])->assertOk()->assertJsonPath('data.total_amount', '10.0000');
        $this->assertSame($beforeSale, $sale->fresh()->getAttributes());
        $this->assertSame($beforeLine, $line->fresh()->getAttributes());
    }

    public function test_f4_legacy_update_receipt_without_before_scope_requires_explicit_reconciliation(): void
    {
        $id = $this->service()['id'];
        $this->patchJson('/api/v1/erp/sales/'.$id, [], ['Idempotency-Key' => 'legacy-update'])->assertOk();
        $receipt = ErpOperationReceipt::where('operation', 'erp.sales.update')->firstOrFail();
        $receipt->update(['resource_refs' => ['resource_id' => $id]]);
        $this->patchJson('/api/v1/erp/sales/'.$id, [], ['Idempotency-Key' => 'legacy-update'])->assertConflict()->assertJsonPath('reason', 'original_scope_unproved');
        $this->getJson('/api/v1/erp/operations/lookup?operation=erp.sales.update', ['Idempotency-Key' => 'legacy-update'])->assertConflict()->assertJsonPath('reason', 'original_scope_unproved');
        $this->assertSame(2, ErpSale::findOrFail($id)->draft_revision);
    }

    public function test_f1_proof_uses_accepted_quantities_when_return_draft_ids_post_out_of_order(): void
    {
        $sale = $this->send('sales', ['erp_establishment_id' => $this->establishment->id, 'lines' => [['description' => 'Residual', 'quantity' => '3', 'unit_price' => '3.3333', 'tax_amount' => '0.0001']]])->assertCreated()->json('data');
        $this->send('sales/'.$sale['id'].'/post', ['draft_revision' => 1])->assertOk();
        $model = ErpSale::findOrFail($sale['id']);
        $line = $model->lines()->firstOrFail();
        $drafts = [];
        foreach (range(1, 3) as $_) {
            $drafts[] = $this->send('sale-returns', $this->returnBody($model, $line))->assertCreated()->json('data.id');
        }
        foreach (array_reverse($drafts) as $id) {
            $this->send('sale-returns/'.$id.'/post', [])->assertOk();
        }
        $this->getJson('/api/v1/erp/sales/'.$model->id)->assertOk()->assertJsonPath('data.returnability.needs_reconciliation', false)->assertJsonPath('data.returnable_lines.0.remaining_quantity', '0.0000')->assertJsonPath('data.finance.net_exposure', '0.0000');
    }
}
