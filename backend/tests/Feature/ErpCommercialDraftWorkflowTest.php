<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpContact;
use App\Models\Erp\ErpCustomerGroup;
use App\Models\Erp\ErpDiscountRule;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpOfficeDelegation;
use App\Models\Erp\ErpOperationReceipt;
use App\Models\Erp\ErpPriceGroup;
use App\Models\Erp\ErpUnit;
use App\Models\Erp\Finance\ErpObligation;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\Erp\Sales\ErpOrder;
use App\Models\Erp\Sales\ErpSale;
use App\Models\Erp\Stock\ErpStockBalance;
use App\Models\Erp\Stock\ErpStockMovement;
use Illuminate\Support\Facades\DB;
use Tests\Support\ErpCommercialTestCase;

class ErpCommercialDraftWorkflowTest extends ErpCommercialTestCase
{
    public function test_http_quote_order_sale_preserve_authoritative_lines_without_reserving_stock(): void
    {
        $body = ['erp_establishment_id' => $this->establishment->id, 'lines' => [
            ['erp_product_variant_id' => $this->variant->id, 'quantity' => '3', 'unit_price' => '10', 'discount_amount' => '1', 'tax_amount' => '2'],
        ], 'expected_total' => '31'];
        $quote = $this->send('quotes', $body, 'quote-create')->assertCreated()->assertJsonPath('data.total_amount', '31.0000')->json('data');
        $this->send('quotes', $body, 'quote-create')->assertCreated()->assertJsonPath('data.id', $quote['id']);
        $detail = $this->getJson('/api/v1/erp/quotes/'.$quote['id'])->assertOk()->assertJsonCount(1, 'data.lines');
        $line = $detail->json('data.lines.0');
        $order = $this->send('quotes/'.$quote['id'].'/convert', ['draft_revision' => 1])->assertOk()->json('data.order');
        $sale = $this->send('orders/'.$order['id'].'/convert', ['draft_revision' => 1])->assertOk()->json('data.sale');
        $this->getJson('/api/v1/erp/sales/'.$sale['id'])->assertOk()->assertJsonPath('data.lines.0.source_quote_line_id', $line['id'])->assertJsonPath('data.total_amount', '31.0000');
        $this->assertDatabaseCount('erp_stock_movements', 0);
        $this->assertDatabaseCount('erp_obligations', 0);
        $this->patchJson('/api/v1/erp/quotes/'.$quote['id'], ['expected_total' => '31'], ['Idempotency-Key' => 'edit-converted'])->assertConflict();
    }

    public function test_submitted_total_mismatch_rejects_whole_draft(): void
    {
        $this->send('sales', ['erp_establishment_id' => $this->establishment->id, 'invoice_no' => 'bad', 'expected_total' => '999', 'lines' => [['quantity' => '2', 'unit_price' => '10', 'description' => 'Serviço']]])->assertUnprocessable()->assertJsonValidationErrors('expected_total');
        $this->assertDatabaseCount('erp_sales', 0);
        $this->assertDatabaseCount('erp_operation_receipts', 0);
    }

    public function test_revision_draft_edits_id_ownership_and_consumed_documents_are_enforced(): void
    {
        $sale = $this->send('sales', $this->serviceBody())->assertCreated()->json('data');
        $this->send('sales/'.$sale['id'].'/post', [])->assertUnprocessable()->assertJsonValidationErrors('draft_revision');
        $detail = $this->getJson('/api/v1/erp/sales/'.$sale['id'])->assertOk()->json('data');
        $line = $detail['lines'][0];
        $this->patchJson('/api/v1/erp/sales/'.$sale['id'], ['draft_revision' => 1, 'lines' => [
            ['id' => $line['id'], 'description' => 'Alterado', 'quantity' => '2', 'unit_price' => '7'],
        ]], ['Idempotency-Key' => 'edit'])->assertOk()->assertJsonPath('data.total_amount', '14.0000')->assertJsonPath('data.draft_revision', 2);
        $before = ErpSale::findOrFail($sale['id'])->getAttributes();
        $this->patchJson('/api/v1/erp/sales/'.$sale['id'], ['lines' => [
            ['id' => 999999, 'description' => 'Errado', 'quantity' => '1', 'unit_price' => '1'],
        ]], ['Idempotency-Key' => 'foreign-line'])->assertUnprocessable()->assertJsonValidationErrors('lines.0.id');
        $this->assertSame($before, ErpSale::findOrFail($sale['id'])->getAttributes());
        $this->send('sales/'.$sale['id'].'/post', ['draft_revision' => 1])->assertConflict();
        $result = $this->send('sales/'.$sale['id'].'/post', ['draft_revision' => 2], 'post-edited')->assertOk();
        $this->send('sales/'.$sale['id'].'/post', ['draft_revision' => 2], 'post-edited')->assertOk()->assertExactJson($result->json());
        $this->send('sales/'.$sale['id'].'/post', ['draft_revision' => 2])->assertConflict();
        $this->patchJson('/api/v1/erp/sales/'.$sale['id'], ['lines' => []], ['Idempotency-Key' => 'posted-edit'])->assertConflict();
        $this->assertDatabaseCount('erp_obligations', 1);
    }

    protected function serviceBody(array $extra = []): array
    {
        return $extra + ['erp_establishment_id' => $this->establishment->id, 'lines' => [['description' => 'Serviço', 'quantity' => '1', 'unit_price' => '10']]];
    }

    public function test_pricing_precedence_zero_and_four_place_percent_do_not_stack(): void
    {
        $this->variant->update(['sale_price' => '100']);
        $customerGroup = ErpCustomerGroup::factory()->create(['erp_company_id' => $this->company->id, 'percentage_adjustment' => '10']);
        $contact = ErpContact::factory()->create(['erp_company_id' => $this->company->id, 'erp_customer_group_id' => $customerGroup->id, 'is_customer' => true]);
        $priceGroup = ErpPriceGroup::factory()->create(['erp_company_id' => $this->company->id, 'percentage_adjustment' => '20', 'is_default' => true]);
        $body = ['erp_establishment_id' => $this->establishment->id, 'erp_contact_id' => $contact->id, 'lines' => [['erp_product_variant_id' => $this->variant->id, 'quantity' => '1']]];
        $this->send('quotes', $body)->assertCreated()->assertJsonPath('data.total_amount', '120.0000');
        $this->send('quotes', $body + ['erp_price_group_id' => null])->assertCreated()->assertJsonPath('data.total_amount', '110.0000');
        $priceGroup->update(['percentage_adjustment' => '0']);
        $this->send('quotes', $body)->assertCreated()->assertJsonPath('data.total_amount', '100.0000');
        $this->variant->update(['sale_price' => '0']);
        $this->send('quotes', $body)->assertCreated()->assertJsonPath('data.total_amount', '0.0000');
        $this->variant->update(['sale_price' => '1000000']);
        $priceGroup->update(['percentage_adjustment' => '0.0001']);
        $quote = $this->send('quotes', $body, 'tiny-percent')->assertCreated()->assertJsonPath('data.total_amount', '1000001.0000')->json('data');
        $priceGroup->update(['percentage_adjustment' => '-50']);
        $this->send('quotes', $body, 'tiny-percent')->assertCreated()->assertJsonPath('data.id', $quote['id'])->assertJsonPath('data.total_amount', '1000001.0000');
        $order = $this->send('quotes/'.$quote['id'].'/convert', ['draft_revision' => 1])->assertOk()->assertJsonPath('data.order.total_amount', '1000001.0000')->json('data.order');
        $this->assertNotSame($quote['id'], 0);
        $this->assertSame('1000001.0000', ErpOrder::findOrFail($order['id'])->lines()->firstOrFail()->product_snapshot['pricing']['authoritative_price']);
    }

    public function test_broad_grants_do_not_authorize_manual_price_or_discount_and_post_rechecks(): void
    {
        $this->variant->update(['sale_price' => '10']);
        $member = ErpCompanyMembership::where('user_id', $this->user->id)->firstOrFail();
        $member->update(['action_permissions' => ['erp.sales.*']]);
        $body = ['erp_establishment_id' => $this->establishment->id, 'lines' => [['erp_product_variant_id' => $this->variant->id, 'quantity' => '1', 'unit_price' => '9']]];
        $this->send('sales', $body)->assertUnprocessable()->assertJsonValidationErrors('lines.0.unit_price');
        $member->update(['action_permissions' => ['erp.sales.*', 'erp.sales.price.override']]);
        $sale = $this->send('sales', $body)->assertCreated()->json('data');
        $member->update(['action_permissions' => ['erp.sales.*']]);
        $this->send('sales/'.$sale['id'].'/post', ['draft_revision' => 1])->assertUnprocessable()->assertJsonValidationErrors('lines.0.unit_price');
        $body['lines'][0]['unit_price'] = '10';
        $body['lines'][0]['discount_amount'] = '1';
        $this->send('sales', $body)->assertUnprocessable()->assertJsonValidationErrors('lines.0.discount_amount');
        $this->assertDatabaseCount('erp_stock_movements', 0);
    }

    public function test_simple_rule_is_authoritative_and_unsupported_conditions_fail_without_writes(): void
    {
        $this->variant->update(['sale_price' => '10']);
        $rule = ErpDiscountRule::factory()->create(['erp_company_id' => $this->company->id, 'kind' => 'percentage', 'value' => '10', 'conditions' => [], 'coupon_code' => null]);
        ErpCompanyMembership::where('user_id', $this->user->id)->firstOrFail()->update(['action_permissions' => ['erp.sales.*']]);
        $body = ['erp_establishment_id' => $this->establishment->id, 'lines' => [['erp_product_variant_id' => $this->variant->id, 'quantity' => '1', 'erp_discount_rule_id' => $rule->id]]];
        $this->send('sales', $body)->assertCreated()->assertJsonPath('data.total_amount', '9.0000');
        $rule->update(['conditions' => ['invented' => true]]);
        $this->send('sales', $body)->assertUnprocessable()->assertJsonValidationErrors('lines.0.erp_discount_rule_id');
        $this->assertDatabaseCount('erp_sales', 1);
    }

    public function test_strict_raw_decimals_alias_conflicts_and_header_allocation(): void
    {
        foreach ([1, true, ' 1', '1 ', '1e0', '1,0', '1.00001'] as $invalid) {
            $body = $this->serviceBody();
            $body['lines'][0]['quantity'] = $invalid;
            $this->send('sales', $body)->assertUnprocessable()->assertJsonValidationErrors('lines.0.quantity');
        }
        $this->send('sales', $this->serviceBody(['invoice_no' => 'A', 'sale_no' => 'B']))->assertUnprocessable()->assertJsonValidationErrors('sale_no');
        $this->send('quotes', ['erp_establishment_id' => $this->establishment->id, 'total_amount' => '1'])->assertUnprocessable()->assertJsonValidationErrors('lines');
        $body = $this->serviceBody(['lines' => array_fill(0, 3, ['description' => 'Parcela', 'quantity' => '1', 'unit_price' => '1']), 'discount' => ['discount_type' => 'fixed', 'discount_amount' => '1'], 'tax_rate_percent' => '10']);
        $id = $this->send('sales', $body)->assertCreated()->assertJsonPath('data.total_amount', '2.2000')->json('data.id');
        $detail = $this->getJson('/api/v1/erp/sales/'.$id)->assertOk();
        $this->assertSame(['0.3333', '0.3333', '0.3334'], array_column($detail->json('data.lines'), 'discount_amount'));
        $this->assertSame('0.2000', array_reduce($detail->json('data.lines'), fn ($sum, $line) => bcadd($sum, $line['tax_amount'], 4), '0.0000'));
        $body['lines'][0]['line_total'] = '999';
        $this->send('sales', $body)->assertUnprocessable()->assertJsonValidationErrors('lines.0.line_total');
    }

    public function test_units_factor_eight_and_aggregate_overflow_are_field_errors(): void
    {
        $unit = ErpUnit::factory()->create(['erp_company_id' => $this->company->id, 'base_unit_id' => $this->variant->product->erp_unit_id, 'conversion_factor' => '2.50000000', 'allow_decimal' => true]);
        $this->variant->update(['sale_price' => '4']);
        $body = ['erp_establishment_id' => $this->establishment->id, 'lines' => [['erp_product_variant_id' => $this->variant->id, 'erp_unit_id' => $unit->id, 'quantity' => '0.5', 'unit_conversion_factor' => '2.50000000']]];
        $id = $this->send('quotes', $body)->assertCreated()->assertJsonPath('data.total_amount', '5.0000')->json('data.id');
        $this->getJson('/api/v1/erp/quotes/'.$id)->assertOk()->assertJsonPath('data.lines.0.base_quantity', '1.2500');
        $unit->update(['conversion_factor' => '1.00000001']);
        $body['lines'][0]['unit_conversion_factor'] = '1.00000001';
        $this->send('quotes', $body)->assertUnprocessable()->assertJsonValidationErrors('lines.0.quantity');
        $this->send('sales', $this->serviceBody(['lines' => array_fill(0, 2, ['description' => 'Limite', 'quantity' => '1', 'unit_price' => '999999999999999999'])]))->assertUnprocessable()->assertJsonValidationErrors('subtotal_amount');
    }

    public function test_numbering_skips_manual_collisions_and_all_store_receipts_are_bounded(): void
    {
        $this->send('quotes', $this->serviceBody(['quote_no' => 'Q-000001']))->assertCreated();
        $this->send('quotes', $this->serviceBody())->assertCreated()->assertJsonPath('data.quote_no', 'Q-000002');
        $this->send('quotes', $this->serviceBody(['quote_no' => 'Q-000001']))->assertUnprocessable()->assertJsonValidationErrors('quote_no');
        $body = $this->serviceBody(['lines' => array_fill(0, 100, ['description' => str_repeat('a', 500), 'quantity' => '1', 'unit_price' => '1'])]);
        $response = $this->send('sales', $body, 'large-store')->assertCreated();
        $this->assertLessThan(16384, strlen($response->getContent()));
        $this->send('sales', $body, 'large-store')->assertCreated()->assertExactJson($response->json());
        $body['lines'][0]['quantity'] = '2';
        $this->send('sales', $body, 'large-store')->assertConflict();
    }

    public function test_inline_cash_post_and_receipt_failure_roll_back_all_participants(): void
    {
        $register = $this->send('cash-registers', ['name' => 'Caixa', 'erp_establishment_id' => $this->establishment->id])->assertCreated()->json('data.id');
        $session = $this->send('cash-sessions', ['erp_cash_register_id' => $register, 'currency' => 'BRL', 'opening_amount' => '0'])->assertCreated()->json('data.id');
        $id = $this->send('sales', $this->serviceBody())->assertCreated()->json('data.id');
        $body = ['draft_revision' => 1, 'payment' => ['direction' => 'in', 'amount' => '10', 'method' => 'cash', 'erp_cash_session_id' => $session]];
        ErpOperationReceipt::created(function ($receipt): void {
            if ($receipt->operation === 'erp.sales.post') {
                abort(503, 'Injected after all participants');
            }
        });
        try {
            $this->send('sales/'.$id.'/post', $body, 'atomic-cash')->assertStatus(503);
        } finally {
            ErpOperationReceipt::flushEventListeners();
        }
        foreach (['erp_payments', 'erp_payment_allocations', 'erp_obligations', 'erp_cash_movements'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame('draft', ErpSale::findOrFail($id)->status);
        $posted = $this->send('sales/'.$id.'/post', $body, 'atomic-cash')->assertOk();
        $this->send('sales/'.$id.'/post', $body, 'atomic-cash')->assertOk()->assertExactJson($posted->json());
        $this->assertDatabaseCount('erp_payments', 1);
        $this->assertDatabaseCount('erp_payment_allocations', 1);
        $this->assertDatabaseCount('erp_cash_movements', 1);
        $this->assertSame('0.0000', ErpObligation::firstOrFail()->open_amount);
        $this->send('cash-sessions/'.$session.'/close', ['declared_closing_amount' => '10'])->assertOk();
        $next = $this->send('sales', $this->serviceBody())->assertCreated()->json('data.id');
        $this->send('sales/'.$next.'/post', $body)->assertUnprocessable();
        $this->assertSame('draft', ErpSale::findOrFail($next)->status);
        $this->assertDatabaseCount('erp_payments', 1);
    }

    public function test_fiscal_request_copies_frozen_evidence_after_catalog_and_party_edits(): void
    {
        $this->variant->update(['sale_price' => '12']);
        $this->variant->product->update(['kind' => 'service', 'track_stock' => false, 'ncm' => '12345678']);
        $contact = ErpContact::factory()->create(['erp_company_id' => $this->company->id, 'is_customer' => true, 'name' => 'Original']);
        $id = $this->send('sales', ['erp_establishment_id' => $this->establishment->id, 'erp_contact_id' => $contact->id, 'lines' => [['erp_product_variant_id' => $this->variant->id, 'quantity' => '1']]])->assertCreated()->json('data.id');
        $this->variant->update(['sale_price' => '99']);
        $contact->update(['name' => 'Changed']);
        $this->send('sales/'.$id.'/post', ['draft_revision' => 1])->assertOk();
        $sale = ErpSale::findOrFail($id);
        $frozen = $sale->commercial_snapshot;
        $this->variant->product->update(['ncm' => null, 'status' => 'archived']);
        $contact->update(['status' => 'archived']);
        $this->company->update(['name' => 'Changed company']);
        $document = $this->send('fiscal/documents', ['erp_sale_id' => $id, 'erp_establishment_id' => $this->establishment->id, 'model' => '55', 'number' => 17, 'idempotency_key' => 'frozen-fiscal'])->assertCreated()->json('data.id');
        $copy = ErpFiscalDocument::findOrFail($document)->commercial_snapshot;
        $this->assertSame($frozen['lines'], $copy['lines']);
        $this->assertSame('Original', $copy['party']['name']);
        $this->assertSame('12345678', $copy['lines'][0]['product_snapshot']['product']['ncm']);
        $this->assertSame($frozen, $sale->fresh()->commercial_snapshot);
        $this->assertSame('Original', ErpObligation::firstOrFail()->party_snapshot['name']);
    }

    public function test_current_establishment_action_and_related_scopes_deny_history_replay_and_lookup(): void
    {
        $body = $this->serviceBody();
        $id = $this->send('sales', $body, 'protected-draft')->assertCreated()->json('data.id');
        $member = ErpCompanyMembership::where('user_id', $this->user->id)->firstOrFail();
        $member->update(['role' => 'operator', 'access_all_establishments' => false, 'action_permissions' => ['erp.sales.*', 'erp.operations.lookup']]);
        $this->getJson('/api/v1/erp/sales/'.$id)->assertNotFound();
        $this->send('sales', $body, 'protected-draft')->assertNotFound();
        $this->getJson('/api/v1/erp/operations/lookup?operation=erp.sales.store', ['Idempotency-Key' => 'protected-draft'])->assertNotFound();
        $member->establishments()->attach($this->establishment->id, ['erp_company_id' => $this->company->id]);
        $this->send('sales', $body, 'protected-draft')->assertCreated();
        $member->update(['action_permissions' => ['erp.sales.show']]);
        $this->send('sales', $body, 'protected-draft')->assertForbidden();
        $foreign = ErpEstablishment::factory()->create();
        $member->update(['role' => 'owner', 'action_permissions' => ['*']]);
        $this->send('sales', $this->serviceBody(['erp_establishment_id' => $foreign->id]))->assertUnprocessable()->assertJsonValidationErrors('erp_establishment_id');
        $this->assertDatabaseCount('erp_sales', 1);
    }

    public function test_final_session_fence_failure_rolls_back_sale_finance_and_receipt(): void
    {
        $id = $this->send('sales', $this->serviceBody())->assertCreated()->json('data.id');
        ErpOperationReceipt::created(function ($receipt): void {
            if ($receipt->operation === 'erp.sales.post') {
                request()->attributes->set('access.session_lock', new class
                {
                    public function isOwnedByCurrentProcess(): bool
                    {
                        return false;
                    }
                });
            }
        });
        try {
            $this->send('sales/'.$id.'/post', ['draft_revision' => 1], 'fence')->assertStatus(503);
        } finally {
            ErpOperationReceipt::flushEventListeners();
        }
        $this->assertSame('draft', ErpSale::findOrFail($id)->status);
        $this->assertSame(0, ErpOperationReceipt::where('operation', 'erp.sales.post')->count());
        $this->assertDatabaseCount('erp_obligations', 0);
        $this->send('sales/'.$id.'/post', ['draft_revision' => 1], 'fence')->assertOk();
    }

    public function test_stock_and_obligation_failures_rollback_real_lots_before_retry(): void
    {
        $purchase = $this->send('purchases', ['erp_establishment_id' => $this->establishment->id, 'lines' => [['erp_product_variant_id' => $this->variant->id, 'quantity' => '2', 'unit_cost' => '7']]])->assertCreated()->json('data.id');
        $this->send('purchases/'.$purchase.'/post', [])->assertOk();
        $sale = $this->send('sales', ['erp_establishment_id' => $this->establishment->id, 'lines' => [['erp_product_variant_id' => $this->variant->id, 'quantity' => '1']]])->assertCreated()->json('data.id');
        $original = DB::table('erp_stock_lots')->get()->toJson();
        ErpObligation::created(function ($row): void {
            if ($row->source_type === 'erp_sale') {
                abort(422, 'After obligation');
            }
        });
        try {
            $this->send('sales/'.$sale.'/post', ['draft_revision' => 1], 'stock-finance')->assertUnprocessable();
        } finally {
            ErpObligation::flushEventListeners();
        }
        $this->assertSame($original, DB::table('erp_stock_lots')->get()->toJson());
        $this->assertSame('2.0000', ErpStockBalance::firstOrFail()->quantity_on_hand);
        $this->assertSame(0, DB::table('erp_stock_movements')->where('source_type', 'erp_sale')->count());
        $this->assertSame('draft', ErpSale::findOrFail($sale)->status);
        $this->send('sales/'.$sale.'/post', ['draft_revision' => 1], 'stock-finance')->assertOk();
        $this->assertSame('1.0000', ErpStockBalance::firstOrFail()->quantity_on_hand);
    }

    public function test_delegated_manual_capabilities_require_exact_grant_and_current_office_write_role(): void
    {
        $office = Account::factory()->create();
        $officeMember = AccountMembership::factory()->create(['account_id' => $office->id, 'user_id' => $this->user->id, 'role' => 'admin']);
        ErpCompanyMembership::where('user_id', $this->user->id)->delete();
        $delegation = ErpOfficeDelegation::factory()->create(['erp_company_id' => $this->company->id, 'account_id' => $office->id, 'permission_allowlist' => ['erp.read', 'erp.sales.store']]);
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $csrf)->postJson('/api/v1/login', ['email' => $this->user->email, 'password' => 'password'])->assertOk();
        $this->withHeader('X-CSRF-Token', $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'))->postJson('/api/v1/erp/company/switch', ['company_id' => $this->company->id])->assertOk();
        $this->withHeaders(['X-Company-Id' => (string) $this->company->id, 'X-CSRF-Token' => $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token')]);
        $this->send('sales', $this->serviceBody())->assertUnprocessable()->assertJsonValidationErrors('lines.0.unit_price');
        $delegation->update(['permission_allowlist' => ['erp.read', 'erp.sales.store', 'erp.sales.price.override']]);
        $this->send('sales', $this->serviceBody(), 'delegated-commercial')->assertCreated();
        $officeMember->update(['role' => 'user']);
        $this->send('sales', $this->serviceBody(), 'delegated-commercial')->assertForbidden();
        $this->assertDatabaseCount('erp_sales', 1);
    }

    public function test_numeric_representations_replay_one_effect_and_returns_reject_tenders(): void
    {
        $body = $this->serviceBody();
        $original = $this->send('sales', $body, 'normalized-commercial')->assertCreated();
        $body['erp_establishment_id'] = (string) $body['erp_establishment_id'];
        $body['lines'][0]['quantity'] = '1.0000';
        $body['lines'][0]['unit_price'] = '10.0000';
        $this->send('sales', $body, 'normalized-commercial')->assertCreated()->assertExactJson($original->json());
        $id = $original->json('data.id');
        $this->send('sales/'.$id.'/post', ['draft_revision' => 1])->assertOk();
        $line = ErpSale::findOrFail($id)->lines()->firstOrFail()->id;
        $return = $this->send('sale-returns', ['erp_sale_id' => $id, 'reason' => 'Partial', 'lines' => [['source_line_id' => $line, 'quantity' => '1']]])->assertCreated()->json('data.id');
        $this->send('sale-returns/'.$return.'/post', ['payment' => ['direction' => 'out', 'amount' => '10', 'method' => 'manual']])->assertUnprocessable()->assertJsonValidationErrors('operation');
        $this->assertDatabaseCount('erp_payments', 0);
        $this->assertDatabaseHas('erp_sale_returns', ['id' => $return, 'status' => 'draft']);
    }

    public function test_saved_stock_unit_and_price_semantics_survive_structural_catalog_changes_before_conversion_and_post(): void
    {
        $purchase = $this->send('purchases', ['erp_establishment_id' => $this->establishment->id, 'lines' => [['erp_product_variant_id' => $this->variant->id, 'quantity' => '10', 'unit_cost' => '7']]])->assertCreated()->json('data.id');
        $this->send('purchases/'.$purchase.'/post', [])->assertOk();
        $this->variant->update(['sale_price' => '4']);
        $unit = ErpUnit::factory()->create(['erp_company_id' => $this->company->id, 'base_unit_id' => $this->variant->product->erp_unit_id, 'conversion_factor' => '2.50000000', 'allow_decimal' => true]);
        $quote = $this->send('quotes', ['erp_establishment_id' => $this->establishment->id, 'lines' => [['erp_product_variant_id' => $this->variant->id, 'erp_unit_id' => $unit->id, 'quantity' => '2']]])->assertCreated()->assertJsonPath('data.total_amount', '20.0000')->json('data.id');
        $unit->update(['conversion_factor' => '7.00000000']);
        $newBase = ErpUnit::factory()->create(['erp_company_id' => $this->company->id]);
        $this->variant->product->update(['kind' => 'service', 'track_stock' => false, 'erp_unit_id' => $newBase->id]);
        $this->variant->update(['sale_price' => '99']);
        $order = $this->send('quotes/'.$quote.'/convert', ['draft_revision' => 1])->assertOk()->json('data.order.id');
        $sale = $this->send('orders/'.$order.'/convert', ['draft_revision' => 1])->assertOk()->json('data.sale.id');
        $this->send('sales/'.$sale.'/post', ['draft_revision' => 1])->assertOk()->assertJsonPath('data.total_amount', '20.0000');
        $movement = ErpStockMovement::where('source_type', 'erp_sale')->where('source_id', $sale)->firstOrFail();
        $this->assertSame('5.0000', $movement->quantity);
        $this->assertSame('5.0000', ErpStockBalance::firstOrFail()->quantity_on_hand);
        $saved = ErpSale::findOrFail($sale)->lines()->firstOrFail();
        $this->assertSame('2.50000000', $saved->unit_conversion_factor);
        $this->assertSame('goods', $saved->product_snapshot['product']['kind']);
        $this->assertTrue($saved->product_snapshot['product']['track_stock']);
        $this->assertSame('10.0000', $saved->unit_price);
    }

    public function test_saved_service_with_legacy_true_stock_flag_posts_and_returns_without_inventing_stock(): void
    {
        $this->variant->product->update(['kind' => 'service', 'track_stock' => true]);
        $this->variant->update(['sale_price' => '10']);
        $sale = $this->send('sales', ['erp_establishment_id' => $this->establishment->id, 'lines' => [['erp_product_variant_id' => $this->variant->id, 'quantity' => '1']]])->assertCreated()->json('data.id');
        $this->send('sales/'.$sale.'/post', ['draft_revision' => 1])->assertOk();
        $this->variant->product->update(['kind' => 'goods', 'track_stock' => true]);
        $line = ErpSale::findOrFail($sale)->lines()->firstOrFail()->id;
        $return = $this->send('sale-returns', ['erp_sale_id' => $sale, 'reason' => 'Service correction', 'lines' => [['source_line_id' => $line, 'quantity' => '1']]])->assertCreated()->json('data.id');
        $this->send('sale-returns/'.$return.'/post', [])->assertOk()->assertJsonPath('data.total_amount', '10.0000')->assertJsonPath('data.financial_disposition', 'opposite_credit');
        $this->assertDatabaseCount('erp_stock_movements', 0);
        $this->assertDatabaseCount('erp_stock_balances', 0);
    }
}
