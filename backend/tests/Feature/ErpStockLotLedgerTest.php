<?php

namespace Tests\Feature;

use App\Erp\ErpCommercialCatalog;
use App\Erp\Stock\ErpStockLedger;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpContact;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpOperationReceipt;
use App\Models\Erp\ErpProduct;
use App\Models\Erp\ErpProductVariant;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\ErpUnit;
use App\Models\Erp\Sales\ErpSale;
use App\Models\Erp\Stock\ErpPurchase;
use App\Models\Erp\Stock\ErpStockAllocation;
use App\Models\Erp\Stock\ErpStockCostSettlement;
use App\Models\Erp\Stock\ErpStockLot;
use App\Models\Erp\Stock\ErpStockMovement;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ErpStockLotLedgerTest extends TestCase
{
    use RefreshDatabase;

    private ?ErpContact $purchaseSupplier = null;

    private function manualPurchaseSupplier(): ErpContact
    {
        return $this->purchaseSupplier ??= ErpContact::factory()->create(['erp_company_id' => $this->company->id, 'is_supplier' => true]);
    }

    private ErpCompany $company;

    private ErpEstablishment $establishment;

    private ErpProductVariant $variant;

    protected function setUp(): void
    {
        if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_DATABASE') !== ':memory:') {
            throw new \RuntimeException('Stock feature tests require SQLite memory before RefreshDatabase.');
        }
        parent::setUp();
        config(['access.ingress_key' => 'test-key', 'erp.operating_writes_enabled' => true, 'cache.default' => 'array', 'session.driver' => 'array', 'session.store' => null]);
        $this->withHeaders(['X-Opmoni-Ingress-Key' => 'test-key', 'X-Opmoni-Client-IP' => '127.0.0.1', 'X-Opmoni-Client-Proto' => 'http', 'Origin' => 'http://localhost:3000']);
        $user = User::factory()->create();
        $this->company = ErpCompany::factory()->create(['cnpj' => '30288513000100']);
        ErpCompanyMembership::factory()->create(['erp_company_id' => $this->company->id, 'user_id' => $user->id, 'role' => 'owner']);
        ErpSubscription::factory()->create(['erp_company_id' => $this->company->id, 'erp_plan_id' => app(ErpCommercialCatalog::class)->ensureBasicPlan()->id, 'status' => 'active']);
        $this->establishment = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $unit = ErpUnit::factory()->create(['erp_company_id' => $this->company->id]);
        $product = ErpProduct::factory()->create(['erp_company_id' => $this->company->id, 'erp_unit_id' => $unit->id, 'kind' => 'goods', 'track_stock' => true]);
        $this->variant = ErpProductVariant::factory()->create(['erp_product_id' => $product->id]);
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $csrf)->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $this->withHeaders(['X-Company-Id' => (string) $this->company->id, 'X-CSRF-Token' => $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token')]);
    }

    private function receive(string $quantity, string $cost, string $factor = '1', string $currency = 'BRL'): ErpStockMovement
    {
        $id = $this->commercialPost('/api/v1/erp/purchases', ['currency' => $currency, 'erp_establishment_id' => $this->establishment->id, 'erp_contact_id' => $this->manualPurchaseSupplier()->id, 'lines' => [['erp_product_variant_id' => $this->variant->id, 'quantity' => $quantity, 'unit_cost' => $cost, 'unit_conversion_factor' => $factor]]])->assertCreated()->json('data.id');
        $this->commercialPost("/api/v1/erp/purchases/{$id}/post", [], ['Idempotency-Key' => 'receipt-'.$id])->assertOk();

        return ErpStockMovement::where('source_type', 'erp_purchase')->where('source_id', $id)->sole();
    }

    private function issue(string $quantity): ErpStockMovement
    {
        $id = $this->commercialPost('/api/v1/erp/sales', ['erp_establishment_id' => $this->establishment->id, 'invoice_no' => 'S-'.bin2hex(random_bytes(6)), 'lines' => [['erp_product_variant_id' => $this->variant->id, 'quantity' => $quantity, 'unit_price' => '40']]])->assertCreated()->json('data.id');
        $this->commercialPost("/api/v1/erp/sales/{$id}/post", [], ['Idempotency-Key' => 'issue-'.$id])->assertOk();

        return ErpStockMovement::where('source_type', 'erp_sale')->where('source_id', $id)->sole();
    }

    public static function methods(): array
    {
        return [['fifo', '10.0000', 0], ['lifo', '20.0000', 1]];
    }

    #[DataProvider('methods')]
    public function test_http_receipts_and_sales_persist_selected_lot_cost(string $method, string $cost, int $receiptIndex): void
    {
        $this->patchJson('/api/v1/erp/company/settings', ['accounting_method' => $method])->assertOk();
        $receipts = [$this->receive('1', '10'), $this->receive('1', '20')];
        $issue = $this->issue('1');
        $this->assertSame($method, $issue->valuation_method);
        $this->assertSame($cost, $issue->total_cost);
        $allocation = DB::table('erp_stock_allocations')->where('erp_stock_movement_id', $issue->id)->sole();
        $lot = DB::table('erp_stock_lots')->find($allocation->erp_stock_lot_id);
        $this->assertSame($receipts[$receiptIndex]->id, $lot->erp_stock_movement_id);
        $this->getJson('/api/v1/erp/stock/movements/'.$issue->id)->assertOk()->assertJsonPath('data.allocations.0.cost', $cost);
    }

    public function test_receipt_exact_total_residual_survives_three_http_issues(): void
    {
        $this->receive('1', '10', '3');
        $issues = [$this->issue('1'), $this->issue('1'), $this->issue('1')];
        $this->assertSame(['3.3333', '3.3333', '3.3334'], array_map(fn ($issue) => $issue->total_cost, $issues));
        $this->assertSame('0.0000', $this->getJson('/api/v1/erp/stock/balances')->assertOk()->json('data.0.quantity_on_hand'));
    }

    public function test_eight_place_factor_is_preserved_when_base_quantity_is_exact(): void
    {
        $movement = $this->receive('10000', '1', '1.00000001');
        $this->assertSame('10000.0001', $movement->quantity);
        $line = DB::table('erp_purchase_lines')->sole();
        $this->assertSame('1.00000001', bcadd((string) $line->unit_conversion_factor, '0', 8));
        $this->commercialPost('/api/v1/erp/purchases', ['erp_establishment_id' => $this->establishment->id, 'erp_contact_id' => $this->manualPurchaseSupplier()->id, 'lines' => [['erp_product_variant_id' => $this->variant->id, 'quantity' => '1', 'unit_cost' => '1', 'unit_conversion_factor' => '1.00000001']]])->assertUnprocessable()->assertJsonValidationErrors('lines.0.unit_conversion_factor');
        $this->assertDatabaseCount('erp_purchases', 1);
    }

    public function test_sale_return_uses_original_allocations_after_method_and_receipt_change(): void
    {
        $first = $this->receive('1', '10');
        $issue = $this->issue('1');
        $before = $issue->getRawOriginal();
        $this->receive('1', '20');
        $this->patchJson('/api/v1/erp/company/settings', ['accounting_method' => 'lifo'])->assertOk();
        $id = $this->commercialPost('/api/v1/erp/sale-returns', $this->fullReturnBody('sale', $issue->source_id, '40'))->assertCreated()->json('data.id');
        $this->commercialPost('/api/v1/erp/sale-returns/'.$id.'/post', [], ['Idempotency-Key' => 'return-'.$id])->assertOk();
        $return = ErpStockMovement::where('reversal_of_id', $issue->id)->sole();
        $this->assertSame('10.0000', $return->total_cost);
        $this->assertSame($before, $issue->fresh()->getRawOriginal());
        $child = DB::table('erp_stock_lots')->where('erp_stock_movement_id', $return->id)->sole();
        $root = DB::table('erp_stock_lots')->where('erp_stock_movement_id', $first->id)->sole();
        $this->assertSame($root->id, $child->root_lot_id);
    }

    public function test_http_oversell_settles_receipts_without_rewriting_the_issue(): void
    {
        $this->patchJson('/api/v1/erp/company/settings', ['stock_overselling_enabled' => true])->assertOk()->assertJsonPath('data.stock_overselling_enabled', true);
        $this->receive('1', '10');
        $issue = $this->issue('3');
        $before = $issue->getRawOriginal();
        $this->assertNull($issue->total_cost);
        $this->assertSame('10.0000', $issue->known_cost);
        $this->assertSame('2.0000', $issue->unvalued_quantity);
        $this->getJson('/api/v1/erp/stock/balances')->assertOk()->assertJsonPath('data.0.quantity_on_hand', '-2.0000');
        $this->receive('1', '20');
        $this->getJson('/api/v1/erp/stock/balances')->assertOk()->assertJsonPath('data.0.quantity_on_hand', '-1.0000');
        $this->receive('2', '30');
        $this->getJson('/api/v1/erp/stock/balances')->assertOk()->assertJsonPath('data.0.quantity_on_hand', '1.0000');
        $settlements = DB::table('erp_stock_cost_settlements')->orderBy('id')->get();
        $this->assertSame(['20.0000', '30.0000'], $settlements->map(fn ($row) => bcadd((string) $row->cost, '0', 4))->all());
        $this->assertSame($before, $issue->fresh()->getRawOriginal());
        $this->assertSame('30.0000', $this->issue('1')->total_cost);
    }

    public function test_partial_reversal_cancels_deficit_then_restores_exact_settled_and_original_cost(): void
    {
        $this->patchJson('/api/v1/erp/company/settings', ['stock_overselling_enabled' => true])->assertOk()->assertJsonPath('data.stock_overselling_enabled', true);
        $this->receive('1', '10');
        $issue = $this->issue('3');
        $this->receive('1', '20');
        $reverse = function (string $quantity, string $key) use ($issue) {
            return DB::transaction(fn () => app(ErpStockLedger::class)->reverse($issue, $quantity, 'Partial source return', $key, ['source_type' => 'test_return', 'source_id' => 1]));
        };
        $first = $reverse('0.5', 'partial-1');
        $this->assertSame($first->id, $reverse('0.5', 'partial-1')->id);
        $this->assertSame('0.5000', $first->unvalued_quantity);
        $second = $reverse('1', 'partial-2');
        $this->assertSame('10.0000', $second->known_cost);
        $this->assertSame('0.5000', $second->unvalued_quantity);
        $third = $reverse('1.5', 'partial-3');
        $this->assertSame('20.0000', $third->total_cost);
        $this->assertSame('2.0000', $this->getJson('/api/v1/erp/stock/balances')->json('data.0.quantity_on_hand'));
        $this->expectException(HttpException::class);
        $reverse('0.0001', 'over-return');
    }

    public function test_consumed_purchase_cannot_be_returned_using_unrelated_new_receipt(): void
    {
        $receipt = $this->receive('1', '10');
        $this->issue('1');
        $this->receive('1', '20');
        $id = $this->commercialPost('/api/v1/erp/purchase-returns', $this->fullReturnBody('purchase', $receipt->source_id, '10'))->assertCreated()->json('data.id');
        $this->commercialPost('/api/v1/erp/purchase-returns/'.$id.'/post')->assertUnprocessable();
        $this->assertDatabaseCount('erp_stock_movements', 3);
        $this->assertDatabaseHas('erp_purchases', ['id' => $receipt->source_id, 'status' => 'posted']);
    }

    public function test_http_transfer_carries_each_original_lot_and_rolls_back_on_credit_failure(): void
    {
        $this->receive('1', '10');
        $this->receive('1', '20');
        $destination = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $payload = ['from_establishment_id' => $this->establishment->id, 'to_establishment_id' => $destination->id, 'erp_product_variant_id' => $this->variant->id, 'quantity' => '2', 'source_operation_key' => 'transfer-1'];
        $this->commercialPost('/api/v1/erp/stock/transfers', $payload)->assertCreated();
        $movement = ErpStockMovement::where('source_type', 'erp_stock_transfer')->where('direction', 'out')->sole();
        $this->assertSame('30.0000', $movement->total_cost);
        $lots = DB::table('erp_stock_lots')->where('erp_establishment_id', $destination->id)->orderBy('id')->get();
        $this->assertSame(['10.0000', '20.0000'], $lots->map(fn ($lot) => bcadd((string) $lot->total_cost, '0', 4))->all());
        $this->commercialPost('/api/v1/erp/stock/transfers', $payload)->assertStatus(201);
        $this->commercialPost('/api/v1/erp/stock/transfers', [...$payload, 'quantity' => '1'])->assertConflict();
        ErpStockLot::creating(function ($lot): void {
            if ($lot->root_lot_id !== null) {
                throw new \RuntimeException('Injected transfer credit failure');
            }
        });
        try {
            $this->commercialPost('/api/v1/erp/stock/transfers', [...$payload, 'from_establishment_id' => $destination->id, 'to_establishment_id' => $this->establishment->id, 'quantity' => '1', 'source_operation_key' => 'transfer-fail'])->assertStatus(503);
        } finally {
            ErpStockLot::flushEventListeners();
        }
        $this->assertDatabaseCount('erp_stock_movements', 4);
        $this->assertDatabaseHas('erp_stock_balances', ['erp_establishment_id' => $destination->id, 'quantity_on_hand' => '2.0000']);
    }

    public function test_adjustment_creates_unvalued_stock_and_journal_replay_keeps_original_result(): void
    {
        $payload = ['erp_establishment_id' => $this->establishment->id, 'erp_product_variant_id' => $this->variant->id, 'quantity_delta' => '2', 'reason' => 'Contagem inicial'];
        $response = $this->commercialPost('/api/v1/erp/stock/adjustments', $payload, ['Idempotency-Key' => 'adjustment-ledger'])->assertCreated();
        $this->assertNull(ErpStockMovement::sole()->total_cost);
        $this->assertSame('2.0000', ErpStockMovement::sole()->unvalued_quantity);
        $this->commercialPost('/api/v1/erp/stock/adjustments', $payload, ['Idempotency-Key' => 'adjustment-ledger'])->assertStatus(201)->assertExactJson($response->json());
        $this->commercialPost('/api/v1/erp/stock/adjustments', [...$payload, 'reason' => 'Different'], ['Idempotency-Key' => 'adjustment-ledger'])->assertConflict();
        $this->getJson('/api/v1/erp/operations/lookup?operation=erp.stock.adjustments.store', ['Idempotency-Key' => 'adjustment-ledger'])->assertOk()->assertJsonPath('data.result.id', $response->json('data.id'));
        $this->assertDatabaseCount('erp_operation_receipts', 1);
        $this->assertDatabaseCount('erp_stock_movements', 1);
        $this->variant->product->update(['status' => 'archived']);
        $this->commercialPost('/api/v1/erp/stock/adjustments', $payload, ['Idempotency-Key' => 'adjustment-ledger'])->assertStatus(201)->assertExactJson($response->json());
    }

    public function test_sale_shortage_requires_both_setting_and_explicit_effective_capability(): void
    {
        $this->receive('1', '10');
        $membership = ErpCompanyMembership::where('erp_company_id', $this->company->id)->sole();
        $membership->update(['role' => 'operator', 'action_permissions' => ['erp.sales.store', 'erp.sales.post', 'erp.stock.oversell']]);
        $id = $this->commercialPost('/api/v1/erp/sales', ['erp_establishment_id' => $this->establishment->id, 'invoice_no' => 'SHORTAGE', 'lines' => [['erp_product_variant_id' => $this->variant->id, 'quantity' => '2']]])->assertCreated()->json('data.id');
        $this->commercialPost('/api/v1/erp/sales/'.$id.'/post', [], ['Idempotency-Key' => 'shortage'])->assertUnprocessable();
        $this->company->update(['operating_settings' => ['stock_overselling_enabled' => true]]);
        $membership->update(['action_permissions' => ['erp.sales.store', 'erp.sales.post']]);
        $this->commercialPost('/api/v1/erp/sales/'.$id.'/post', [], ['Idempotency-Key' => 'shortage'])->assertForbidden();
        $membership->update(['action_permissions' => ['erp.write']]);
        $this->commercialPost('/api/v1/erp/sales/'.$id.'/post', [], ['Idempotency-Key' => 'shortage'])->assertForbidden();
        $membership->update(['action_permissions' => ['erp.sales.store', 'erp.sales.post', 'erp.stock.oversell']]);
        $original = $this->commercialPost('/api/v1/erp/sales/'.$id.'/post', [], ['Idempotency-Key' => 'shortage'])->assertOk();
        $this->commercialPost('/api/v1/erp/sales/'.$id.'/post', ['unexpected' => 'changed'], ['Idempotency-Key' => 'shortage'])->assertUnprocessable();
        $this->commercialPost('/api/v1/erp/sales/'.$id.'/post', [], ['Idempotency-Key' => 'shortage'])->assertOk()->assertExactJson($original->json());
        $membership->update(['role' => 'viewer']);
        $this->commercialPost('/api/v1/erp/sales/'.$id.'/post', [], ['Idempotency-Key' => 'shortage'])->assertForbidden();
    }

    public function test_standalone_adjustment_reversal_is_bounded_and_commercial_movements_require_owning_return(): void
    {
        $payload = ['erp_establishment_id' => $this->establishment->id, 'erp_product_variant_id' => $this->variant->id, 'quantity_delta' => '2', 'reason' => 'Contagem'];
        $this->commercialPost('/api/v1/erp/stock/adjustments', $payload, ['Idempotency-Key' => 'opening'])->assertCreated();
        $movement = ErpStockMovement::sole();
        $path = '/api/v1/erp/stock/movements/'.$movement->id.'/reverse';
        $original = $this->commercialPost($path, ['quantity' => '0.5', 'reason' => 'Correção'], ['Idempotency-Key' => 'reverse-1'])->assertCreated();
        $this->commercialPost($path, ['quantity' => '0.5', 'reason' => 'Correção'], ['Idempotency-Key' => 'reverse-1'])->assertStatus(201)->assertExactJson($original->json());
        $this->commercialPost($path, ['quantity' => '0.6', 'reason' => 'Correção'], ['Idempotency-Key' => 'reverse-1'])->assertConflict();
        $this->commercialPost($path, ['quantity' => '1.6', 'reason' => 'Correção'], ['Idempotency-Key' => 'reverse-over'])->assertUnprocessable();
        $this->commercialPost($path, ['quantity' => '1.5', 'reason' => 'Correção'], ['Idempotency-Key' => 'reverse-rest'])->assertCreated();
        $this->assertDatabaseHas('erp_stock_balances', ['quantity_on_hand' => '0.0000']);
        $purchase = $this->receive('1', '10');
        $this->commercialPost('/api/v1/erp/stock/movements/'.$purchase->id.'/reverse', ['quantity' => '1', 'reason' => 'Use owning return'], ['Idempotency-Key' => 'unsafe'])->assertUnprocessable();
    }

    public function test_linked_partial_transfer_reversal_is_atomic_and_preserves_source_entitlement(): void
    {
        $this->receive('1', '10');
        $this->receive('1', '20');
        $destination = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $transfer = $this->commercialPost('/api/v1/erp/stock/transfers', ['from_establishment_id' => $this->establishment->id, 'to_establishment_id' => $destination->id, 'erp_product_variant_id' => $this->variant->id, 'quantity' => '2'], ['Idempotency-Key' => 'pair'])->assertCreated()->json('data.id');
        $path = '/api/v1/erp/stock/transfers/'.$transfer.'/reverse';
        $first = $this->commercialPost($path, ['quantity' => '0.5', 'reason' => 'Correção parcial'], ['Idempotency-Key' => 'pair-return'])->assertCreated();
        $this->commercialPost($path, ['quantity' => '0.5', 'reason' => 'Correção parcial'], ['Idempotency-Key' => 'pair-return'])->assertStatus(201)->assertExactJson($first->json());
        $this->getJson('/api/v1/erp/stock/transfers/'.$transfer)->assertOk()->assertJsonPath('data.returned_quantity', '0.5000');
        $this->assertDatabaseHas('erp_stock_balances', ['erp_establishment_id' => $destination->id, 'quantity_on_hand' => '1.5000']);
        ErpStockLot::creating(function ($lot): void {
            if ($lot->parent_allocation_id !== null) {
                throw new \RuntimeException('Injected opposite leg failure');
            }
        });
        try {
            $this->commercialPost($path, ['quantity' => '0.5', 'reason' => 'Falha'], ['Idempotency-Key' => 'pair-fail'])->assertStatus(503);
        } finally {
            ErpStockLot::flushEventListeners();
        }
        $this->assertDatabaseCount('erp_stock_movements', 6);
        $this->assertDatabaseHas('erp_stock_balances', ['erp_establishment_id' => $destination->id, 'quantity_on_hand' => '1.5000']);
        $this->commercialPost($path, ['quantity' => '1.5', 'reason' => 'Restante'], ['Idempotency-Key' => 'pair-rest'])->assertCreated();
        $this->commercialPost($path, ['quantity' => '0.0001', 'reason' => 'Excesso'], ['Idempotency-Key' => 'pair-over'])->assertUnprocessable();
        $this->assertDatabaseHas('erp_stock_balances', ['erp_establishment_id' => $this->establishment->id, 'quantity_on_hand' => '2.0000']);
    }

    public function test_transfer_correction_restores_the_surviving_source_allocation_after_lifo_consumption(): void
    {
        $this->receive('1', '10');
        $this->receive('1', '20');
        $destination = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $transfer = $this->commercialPost('/api/v1/erp/stock/transfers', ['from_establishment_id' => $this->establishment->id, 'to_establishment_id' => $destination->id, 'erp_product_variant_id' => $this->variant->id, 'quantity' => '2'], ['Idempotency-Key' => 'source-pair'])->assertCreated()->json('data.id');
        $origin = $this->establishment;
        $this->establishment = $destination;
        $this->patchJson('/api/v1/erp/company/settings', ['accounting_method' => 'lifo'])->assertOk();
        $this->assertSame('20.0000', $this->issue('1')->total_cost);
        $this->establishment = $origin;
        $path = '/api/v1/erp/stock/transfers/'.$transfer.'/reverse';
        $this->commercialPost($path, ['quantity' => '2', 'reason' => 'Unavailable source quantity'], ['Idempotency-Key' => 'unavailable'])->assertUnprocessable();
        $this->commercialPost($path, ['quantity' => '1', 'reason' => 'Surviving source'], ['Idempotency-Key' => 'surviving'])->assertCreated()->assertJsonPath('data.movements.0.total_cost', '10.0000')->assertJsonPath('data.movements.1.total_cost', '10.0000');
        $this->assertSame('10.0000', $this->issue('1')->total_cost);
    }

    public function test_mixed_cost_currencies_remain_separate_through_http_sale_transfer_and_return(): void
    {
        $this->receive('2', '10', '1', 'BRL');
        $this->receive('2', '20', '1', 'USD');
        $issue = $this->issue('3');
        $this->assertNull($issue->total_cost);
        $this->assertNull($issue->known_cost);
        $this->assertSame('mixed_currency', $issue->cost_status);
        $this->assertSame('0.0000', $issue->unvalued_quantity);
        $this->assertSame([['currency' => 'BRL', 'known_cost' => '20.0000', 'unvalued_quantity' => '0.0000'], ['currency' => 'USD', 'known_cost' => '20.0000', 'unvalued_quantity' => '0.0000']], $issue->cost_breakdown);
        $id = $this->commercialPost('/api/v1/erp/sale-returns', $this->fullReturnBody('sale', $issue->source_id, '120'))->assertCreated()->json('data.id');
        $this->commercialPost('/api/v1/erp/sale-returns/'.$id.'/post', [], ['Idempotency-Key' => 'mixed-return'])->assertOk();
        $return = ErpStockMovement::where('reversal_of_id', $issue->id)->sole();
        $this->assertSame($issue->cost_breakdown, $return->cost_breakdown);
        $this->assertNull($return->total_cost);
        $destination = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $this->commercialPost('/api/v1/erp/stock/transfers', ['from_establishment_id' => $this->establishment->id, 'to_establishment_id' => $destination->id, 'erp_product_variant_id' => $this->variant->id, 'quantity' => '4'], ['Idempotency-Key' => 'mixed-transfer'])->assertCreated();
        $legs = ErpStockMovement::where('source_type', 'erp_stock_transfer')->orderBy('id')->get();
        $this->assertSame($legs[0]->cost_breakdown, $legs[1]->cost_breakdown);
        $this->assertSame('mixed_currency', $legs[1]->cost_status);
        $this->assertNull($legs[1]->total_cost);
        $this->getJson('/api/v1/erp/stock/movements/'.$legs[1]->id)->assertOk()->assertJsonPath('data.cost_breakdown.0.currency', 'BRL')->assertJsonPath('data.cost_breakdown.1.currency', 'USD');
    }

    public function test_http_sale_uses_persisted_eight_place_factor_and_rejects_inexact_base(): void
    {
        $this->receive('20000', '1');
        $unit = ErpUnit::factory()->create(['erp_company_id' => $this->company->id, 'base_unit_id' => $this->variant->product->erp_unit_id, 'conversion_factor' => '1.00000001', 'allow_decimal' => true]);
        $payload = ['erp_establishment_id' => $this->establishment->id, 'invoice_no' => 'FACTOR-SALE', 'lines' => [['erp_unit_id' => $unit->id, 'erp_product_variant_id' => $this->variant->id, 'quantity' => '10000', 'unit_price' => '1', 'unit_conversion_factor' => '1.00000001']]];
        $id = $this->commercialPost('/api/v1/erp/sales', $payload)->assertCreated()->json('data.id');
        $this->getJson('/api/v1/erp/sales/'.$id)->assertOk()->assertJsonPath('data.lines.0.unit_conversion_factor', '1.00000001');
        $this->commercialPost('/api/v1/erp/sales/'.$id.'/post', [], ['Idempotency-Key' => 'factor-sale'])->assertOk();
        $this->assertSame('10000.0001', ErpStockMovement::where('source_type', 'erp_sale')->sole()->quantity);
        $this->commercialPost('/api/v1/erp/sales', [...$payload, 'invoice_no' => 'INEXACT', 'lines' => [[...$payload['lines'][0], 'quantity' => '1']]])->assertUnprocessable()->assertJsonValidationErrors('lines.0.quantity');
        $this->assertDatabaseCount('erp_sales', 1);
    }

    public function test_additive_upgrade_preserves_legacy_observations_and_never_guesses_cost_or_source_lines(): void
    {
        $migration = require database_path('migrations/2026_10_20_093520_add_erp_stock_lots_and_allocations.php');
        $migration->down();
        $balances = $movements = [];
        foreach ([['3.0000', '999.0000', 'in', '1.0000', '1.0000'], ['-2.0000', null, 'out', '2.0000', null], ['0.0000', '0.0000', 'in', '0.0000', '0.0000']] as $index => [$quantity, $average, $direction, $movementQuantity, $cost]) {
            $variant = $this->variant->id + 100000 + $index;
            $balances[] = DB::table('erp_stock_balances')->insertGetId(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $this->establishment->id, 'erp_product_variant_id' => $variant, 'quantity_on_hand' => $quantity, 'average_cost' => $average]);
            $movements[] = DB::table('erp_stock_movements')->insertGetId(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $this->establishment->id, 'erp_product_variant_id' => $variant, 'direction' => $direction, 'quantity' => $movementQuantity, 'unit_cost' => $cost, 'source_type' => 'erp_purchase', 'source_id' => 500 + $index, 'source_operation_key' => 'ambiguous-line-123-'.$index, 'allocation_snapshot' => json_encode(['observed' => 'preserve-exact']), 'posted_at' => '2026-01-01 12:00:00']);
        }
        $beforeBalances = DB::table('erp_stock_balances')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $beforeMovements = DB::table('erp_stock_movements')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $migration->up();
        foreach ($beforeBalances as $before) {
            $after = (array) DB::table('erp_stock_balances')->find($before['id']);
            $this->assertSame($before, array_intersect_key($after, $before));
        }
        foreach ($beforeMovements as $before) {
            $after = (array) DB::table('erp_stock_movements')->find($before['id']);
            $this->assertSame($before, array_intersect_key($after, $before));
            $this->assertNull($after['source_line_id']);
            $this->assertNull($after['total_cost']);
            $this->assertSame('legacy_unknown', $after['cost_status']);
        }
        $lot = DB::table('erp_stock_lots')->sole();
        $this->assertSame($balances[0], $lot->opening_balance_id);
        $this->assertNull($lot->total_cost);
        $this->assertNull($lot->erp_stock_movement_id);
        $this->assertSame('legacy_unknown', $lot->cost_status);
        $deficit = DB::table('erp_stock_allocations')->sole();
        $this->assertSame($balances[1], $deficit->opening_balance_id);
        $metadata = json_decode(DB::table('erp_stock_balances')->where('id', $balances[0])->value('ledger_reconciliation'), true);
        $this->assertSame($movements[0], $metadata['watermark_movement_id']);
        $this->assertTrue($metadata['needs_reconciliation']);
        $this->assertSame('1.0000', $metadata['signed_history_quantity']);
        try {
            $migration->down();
            $this->fail('Populated stock history must prevent down.');
        } catch (\RuntimeException $failure) {
            $this->assertStringContainsString('cannot be removed', $failure->getMessage());
        }
        $this->assertDatabaseCount('erp_stock_balances', 3);
        $this->assertDatabaseCount('erp_stock_movements', 3);
        $this->assertDatabaseCount('erp_stock_lots', 1);
    }

    public function test_paired_transfer_returns_carry_the_exact_destination_residual_cost(): void
    {
        $this->receive('1', '10', '3');
        $destination = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $transfer = $this->commercialPost('/api/v1/erp/stock/transfers', ['from_establishment_id' => $this->establishment->id, 'to_establishment_id' => $destination->id, 'erp_product_variant_id' => $this->variant->id, 'quantity' => '3'], ['Idempotency-Key' => 'residual-transfer'])->assertCreated()->json('data.id');
        $origin = $this->establishment;
        $this->establishment = $destination;
        $this->assertSame('3.3333', $this->issue('1')->total_cost);
        $this->establishment = $origin;
        $path = '/api/v1/erp/stock/transfers/'.$transfer.'/reverse';
        $this->commercialPost($path, ['quantity' => '1', 'reason' => 'Primeira fração'], ['Idempotency-Key' => 'residual-return-a'])->assertCreated()->assertJsonPath('data.movements.0.total_cost', '3.3333')->assertJsonPath('data.movements.1.total_cost', '3.3333');
        $this->commercialPost($path, ['quantity' => '1', 'reason' => 'Fração final'], ['Idempotency-Key' => 'residual-return-b'])->assertCreated()->assertJsonPath('data.movements.0.total_cost', '3.3334')->assertJsonPath('data.movements.1.total_cost', '3.3334');
        $this->assertSame('6.6667', $this->issue('2')->total_cost);
    }

    public function test_settled_deficit_return_records_known_and_unknown_settlement_cost_truthfully(): void
    {
        $this->company->update(['operating_settings' => ['stock_overselling_enabled' => true]]);
        $issue = $this->issue('2');
        $this->receive('1', '20');
        $this->commercialPost('/api/v1/erp/stock/adjustments', ['erp_establishment_id' => $this->establishment->id, 'erp_product_variant_id' => $this->variant->id, 'quantity_delta' => '1', 'reason' => 'Unknown settlement'], ['Idempotency-Key' => 'unknown-settlement'])->assertCreated();
        $reverse = DB::transaction(fn () => app(ErpStockLedger::class)->reverse($issue, '2', 'Settled source return', 'settled-return', ['source_type' => 'test_return', 'source_id' => 1]));
        $this->assertSame('20.0000', $reverse->known_cost);
        $this->assertSame('1.0000', $reverse->unvalued_quantity);
        $this->assertNull($reverse->total_cost);
        $components = DB::table('erp_stock_allocations')->where('erp_stock_movement_id', $reverse->id)->orderBy('ordinal')->get();
        $this->assertCount(2, $components);
        $this->assertSame('1.0000', bcadd((string) $components[0]->quantity, '0', 4));
        $this->assertNull($components[0]->cost);
        $this->assertSame('unvalued', $components[0]->cost_status);
        $this->assertSame('20.0000', bcadd((string) $components[1]->cost, '0', 4));
        $this->assertSame('BRL', $components[1]->currency);
        $this->assertSame('known', $components[1]->cost_status);
        $this->assertSame(['unvalued', 'known'], array_column($reverse->allocation_snapshot, 'cost_status'));
        $this->receive('1', '30');
        $this->issue('3');
        $another = $this->issue('1');
        $this->receive('1', '40');
        $known = DB::transaction(fn () => app(ErpStockLedger::class)->reverse($another, '1', 'Known settled return', 'known-settled-return', ['source_type' => 'test_return', 'source_id' => 2]));
        $allocation = DB::table('erp_stock_allocations')->where('erp_stock_movement_id', $known->id)->sole();
        $this->assertSame('40.0000', bcadd((string) $allocation->cost, '0', 4));
        $this->assertSame('known', $allocation->cost_status);
    }

    public function test_stock_filters_and_adjustment_links_are_scoped_before_pagination(): void
    {
        $receipt = $this->receive('1', '10');
        $other = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $this->commercialPost('/api/v1/erp/stock/adjustments', ['erp_establishment_id' => $other->id, 'erp_product_variant_id' => $this->variant->id, 'quantity_delta' => '1', 'reason' => 'Another establishment'], ['Idempotency-Key' => 'other-opening'])->assertCreated();
        $adjustment = DB::table('erp_stock_adjustments')->sole();
        $this->getJson('/api/v1/erp/stock/adjustments/'.$adjustment->id)->assertOk()->assertJsonCount(1, 'data.movement_ids')->assertJsonPath('data.posted_by_user_id', ErpCompanyMembership::sole()->user_id);
        $this->getJson('/api/v1/erp/stock/movements?erp_establishment_id='.$this->establishment->id.'&direction=in&source_type=erp_purchase&source_id='.$receipt->source_id)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $receipt->id);
        $this->getJson('/api/v1/erp/stock/lots?erp_establishment_id='.$this->establishment->id)->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/erp/stock/movements?source_type=erp_purchase')->assertUnprocessable()->assertJsonValidationErrors('source_id');
        $this->getJson('/api/v1/erp/stock/movements?date_from=2026-13-99')->assertUnprocessable();
        $foreign = ErpEstablishment::factory()->create();
        $this->getJson('/api/v1/erp/stock/movements?erp_establishment_id='.$foreign->id)->assertUnprocessable();
        $membership = ErpCompanyMembership::where('erp_company_id', $this->company->id)->sole();
        $membership->update(['role' => 'operator', 'action_permissions' => ['erp.read'], 'access_all_establishments' => false]);
        $membership->establishments()->attach($this->establishment->id, ['erp_company_id' => $this->company->id]);
        $this->getJson('/api/v1/erp/stock/lots')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/erp/stock/movements')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/erp/stock/adjustments/'.$adjustment->id)->assertNotFound();
        $this->getJson('/api/v1/erp/stock/movements?erp_establishment_id='.$other->id)->assertUnprocessable();
    }

    public function test_method_change_preserves_history_and_known_zero_is_distinct_from_unknown(): void
    {
        $this->receive('1', '0');
        $first = $this->issue('1');
        $before = $first->getRawOriginal();
        $this->assertSame('0.0000', $first->total_cost);
        $this->assertSame('known', $first->cost_status);
        $this->receive('1', '10');
        $this->receive('1', '20');
        $this->patchJson('/api/v1/erp/company/settings', ['accounting_method' => 'lifo'])->assertOk();
        $this->assertSame('20.0000', $this->issue('1')->total_cost);
        $this->assertSame($before, $first->fresh()->getRawOriginal());
        $this->getJson('/api/v1/erp/stock/movements/'.$first->id)->assertOk()->assertJsonPath('data.valuation_method', 'fifo')->assertJsonPath('data.reversal.workflow', 'sale_return');
    }

    public function test_failure_after_receipt_creation_rolls_back_every_purchase_participant_and_same_key_can_retry(): void
    {
        $id = $this->commercialPost('/api/v1/erp/purchases', ['erp_establishment_id' => $this->establishment->id, 'erp_contact_id' => $this->manualPurchaseSupplier()->id, 'lines' => [['erp_product_variant_id' => $this->variant->id, 'quantity' => '1', 'unit_cost' => '10']]])->assertCreated()->json('data.id');
        ErpOperationReceipt::created(function ($receipt): void {
            if ($receipt->operation === 'erp.purchases.post') {
                abort(422, 'Injected failure after receipt persistence');
            }
        });
        try {
            $this->commercialPost('/api/v1/erp/purchases/'.$id.'/post', [], ['Idempotency-Key' => 'after-receipt'])->assertUnprocessable();
        } finally {
            ErpOperationReceipt::flushEventListeners();
        }
        $this->assertDatabaseHas('erp_purchases', ['id' => $id, 'status' => 'draft']);
        foreach (['erp_stock_balances', 'erp_stock_lots', 'erp_stock_movements', 'erp_obligations', 'erp_operation_receipts'] as $table) {
            if ($table === 'erp_operation_receipts') {
                $this->assertSame(0, ErpOperationReceipt::where('operation', 'erp.purchases.post')->count());
            } else {
                $this->assertDatabaseCount($table, 0);
            }
        }
        $this->commercialPost('/api/v1/erp/purchases/'.$id.'/post', [], ['Idempotency-Key' => 'after-receipt'])->assertOk();
        $this->assertDatabaseCount('erp_stock_lots', 1);
        $this->assertDatabaseCount('erp_obligations', 1);
        $this->assertSame(1, ErpOperationReceipt::where('operation', 'erp.purchases.post')->count());
    }

    public function test_transfers_and_negative_adjustments_cannot_use_sale_overselling(): void
    {
        $this->company->update(['operating_settings' => ['stock_overselling_enabled' => true]]);
        $destination = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $this->commercialPost('/api/v1/erp/stock/transfers', ['from_establishment_id' => $this->establishment->id, 'to_establishment_id' => $destination->id, 'erp_product_variant_id' => $this->variant->id, 'quantity' => '1'], ['Idempotency-Key' => 'forbidden-deficit-transfer'])->assertUnprocessable();
        $this->commercialPost('/api/v1/erp/stock/adjustments', ['erp_establishment_id' => $this->establishment->id, 'erp_product_variant_id' => $this->variant->id, 'quantity_delta' => '-1', 'reason' => 'No stock'], ['Idempotency-Key' => 'forbidden-deficit-adjustment'])->assertUnprocessable();
        $this->assertDatabaseCount('erp_stock_movements', 0);
        $this->assertDatabaseCount('erp_stock_transfers', 0);
        $this->assertDatabaseCount('erp_stock_adjustments', 0);
        $this->assertDatabaseCount('erp_operation_receipts', 0);
    }

    public function test_lineage_constraints_reject_a_foreign_opening_balance_and_foreign_lot(): void
    {
        $lot = ErpStockLot::factory()->create();
        $allocation = ErpStockAllocation::factory()->create();
        $settlement = ErpStockCostSettlement::factory()->create();
        $this->assertSame($settlement->erp_company_id, ErpStockLot::findOrFail($settlement->erp_stock_lot_id)->erp_company_id);
        $this->receive('1', '10');
        $own = ErpStockLot::where('erp_company_id', $this->company->id)->sole();
        $balance = DB::table('erp_stock_balances')->where('erp_company_id', $lot->erp_company_id)->sole();
        foreach ([['opening_balance_id' => $balance->id], ['root_lot_id' => $lot->id], ['parent_allocation_id' => $allocation->id]] as $invalid) {
            try {
                DB::transaction(fn () => DB::table('erp_stock_lots')->where('id', $own->id)->update($invalid));
                $this->fail('Foreign stock lineage should violate its relational fence.');
            } catch (QueryException $failure) {
                $this->assertStringContainsString('FOREIGN KEY', $failure->getMessage());
            }
        }
    }

    public function test_partial_settled_returns_keep_currency_components_without_cross_currency_counter(): void
    {
        $this->company->update(['operating_settings' => ['stock_overselling_enabled' => true]]);
        $issue = $this->issue('2');
        $this->receive('1', '10', '1', 'BRL');
        $this->receive('1', '20', '1', 'USD');
        $reverse = function (string $key) use ($issue) {
            return DB::transaction(fn () => app(ErpStockLedger::class)->reverse($issue, '1', 'Partial settled return', $key, ['source_type' => 'test_return', 'source_id' => 7]));
        };
        $first = $reverse('usd-settled-return');
        $second = $reverse('brl-settled-return');
        $this->assertSame('USD', $first->currency);
        $this->assertSame('20.0000', $first->total_cost);
        $this->assertSame('BRL', $second->currency);
        $this->assertSame('10.0000', $second->total_cost);
        $original = DB::table('erp_stock_allocations')->where('erp_stock_movement_id', $issue->id)->sole();
        $this->assertNull($original->cost);
        $this->assertSame('0.0000', bcadd((string) $original->returned_cost, '0', 4));
        $this->assertSame('USD', $first->allocation_snapshot[0]['currency']);
        $this->assertSame('20.0000', $first->allocation_snapshot[0]['cost']);
        $this->assertSame('known', $first->allocation_snapshot[0]['cost_status']);
    }

    public function test_balance_api_exposes_safe_inconsistent_legacy_reconciliation_attention(): void
    {
        $migration = require database_path('migrations/2026_10_20_093520_add_erp_stock_lots_and_allocations.php');
        $migration->down();
        $id = DB::table('erp_stock_balances')->insertGetId(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $this->establishment->id, 'erp_product_variant_id' => $this->variant->id, 'quantity_on_hand' => '3.0000', 'average_cost' => '99.0000']);
        $migration->up();
        $this->getJson('/api/v1/erp/stock/balances?erp_establishment_id='.$this->establishment->id)->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.quantity_on_hand', '3.0000')->assertJsonPath('data.0.reconciliation.needs_reconciliation', true)->assertJsonPath('data.0.reconciliation.observed_quantity', '3.0000')->assertJsonPath('data.0.reconciliation.signed_history_quantity', '0.0000')->assertJsonPath('data.0.reconciliation.provenance', 'legacy_unknown')->assertJsonPath('data.0.reconciliation.watermark_movement_id', null)->assertJsonStructure(['data' => [['ledger_initialized_at']]]);
    }

    private int $commercialSequence = 0;

    private function commercialPost($uri, array $data = [], array $headers = [], $options = 0): TestResponse
    {
        if (! isset($headers['Idempotency-Key']) && ! isset($data['source_operation_key'])) {
            $headers['Idempotency-Key'] = 'task9-regression-'.++$this->commercialSequence;
        }
        if (preg_match('#/erp/sales/([0-9]+)/post$#', $uri, $matches) && ! isset($data['draft_revision'])) {
            $data['draft_revision'] = ErpSale::findOrFail($matches[1])->draft_revision;
        }

        return $this->postJson($uri, $data, $headers, $options);
    }

    private function fullReturnBody(string $kind, int $sourceId, string $expected): array
    {
        $class = $kind === 'sale' ? ErpSale::class : ErpPurchase::class;
        $source = $class::findOrFail($sourceId);

        return ['erp_'.$kind.'_id' => $sourceId, 'reason' => 'Regressão: devolver todas as linhas da origem', 'expected_total' => $expected, 'lines' => $source->lines()->orderBy('id')->get()->map(fn ($line): array => ['source_line_id' => $line->id, 'quantity' => $line->quantity])->all()];
    }
}
