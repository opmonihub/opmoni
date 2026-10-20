<?php

namespace Tests\Feature;

use App\Erp\Finance\ErpFinanceParticipant;
use App\Erp\Finance\ErpPaymentAllocationService;
use App\Erp\Stock\ErpStockParticipant;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpProduct;
use App\Models\Erp\ErpProductVariant;
use App\Models\Erp\ErpUnit;
use App\Models\Erp\Finance\ErpObligation;
use App\Models\Erp\Finance\ErpPayment;
use App\Models\Erp\Stock\ErpStockBalance;
use App\Models\Erp\Stock\ErpStockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ErpPostingParticipantContractTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function fixture(string $name): array
    {
        $path = base_path('tests/Fixtures/erp/'.$name);

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_published_stock_issue_fixture_documents_sale_consumer_contract(): void
    {
        $fixture = $this->fixture('stock-sale-issue-participant.json');

        $this->assertTrue($fixture['requires_caller_transaction']);
        $this->assertFalse($fixture['acquires_source_document_lock']);
        $this->assertSame('erp_sale', $fixture['source_type']);
        $this->assertSame('out', $fixture['movement_direction']);
        $this->assertSame(
            ['erp_company', 'erp_sale', 'erp_stock_balances_sorted', 'erp_stock_lots_sorted', 'erp_finance_obligations'],
            $fixture['lock_order'],
        );
    }

    public function test_published_stock_reversal_fixture_documents_sale_return_consumer(): void
    {
        $fixture = $this->fixture('stock-sale-reversal-participant.json');

        $this->assertTrue($fixture['requires_caller_transaction']);
        $this->assertSame('erp_sale_return', $fixture['source_type']);
        $this->assertSame('in', $fixture['movement_direction']);
        $this->assertArrayHasKey('reversal_of', $fixture);
    }

    public function test_sale_post_participant_order_rolls_back_stock_when_finance_fails(): void
    {
        $company = ErpCompany::factory()->create();
        $establishment = ErpEstablishment::factory()->create(['erp_company_id' => $company->id]);
        $unit = ErpUnit::factory()->create(['erp_company_id' => $company->id]);
        $product = ErpProduct::factory()->create(['erp_company_id' => $company->id, 'erp_unit_id' => $unit->id]);
        $variant = ErpProductVariant::factory()->create(['erp_product_id' => $product->id]);
        ErpStockBalance::query()->create([
            'erp_company_id' => $company->id,
            'erp_establishment_id' => $establishment->id,
            'erp_product_variant_id' => $variant->id,
            'quantity_on_hand' => '5.0000',
        ]);

        $stock = new ErpStockParticipant;
        $finance = new ErpFinanceParticipant;

        try {
            DB::transaction(function () use ($stock, $finance, $company, $establishment, $variant): void {
                $stock->issueForSale(
                    $company->id,
                    $establishment->id,
                    99,
                    'sale-post-99',
                    [[
                        'line_id' => 1,
                        'erp_product_variant_id' => $variant->id,
                        'quantity' => '2.0000',
                    ]],
                    null,
                );

                $finance->postReceivableForSale(
                    $company->id,
                    $establishment->id,
                    99,
                    '10',
                    'BRL',
                    'sale-post-99-obligation',
                    null,
                );

                throw new \RuntimeException('Simulated finance failure after stock issue.');
            });
        } catch (\RuntimeException) {
            // expected injected failure after participants run in the same transaction
        }

        $this->assertSame('5.0000', (string) ErpStockBalance::query()->value('quantity_on_hand'));
        $this->assertSame(0, ErpStockMovement::query()->count());
        $this->assertSame(0, ErpObligation::query()->count());
    }

    public function test_finance_allocation_participant_is_idempotent_for_equal_replay(): void
    {
        $company = ErpCompany::factory()->create();
        $obligation = ErpObligation::query()->create([
            'erp_company_id' => $company->id,
            'direction' => 'receivable',
            'category' => 'sale',
            'amount' => '50.0000',
            'open_amount' => '50.0000',
            'source_type' => 'erp_sale',
            'source_id' => 1,
            'source_operation_key' => 'obligation-1',
        ]);
        $payment = ErpPayment::query()->create([
            'erp_company_id' => $company->id,
            'amount' => '50.0000',
            'method' => 'cash',
            'source_type' => 'manual',
            'source_id' => 0,
            'source_operation_key' => 'payment-1',
        ]);

        $participant = new ErpFinanceParticipant;
        $allocator = new ErpPaymentAllocationService;

        DB::transaction(function () use ($participant, $allocator, $company, $payment, $obligation): void {
            $participant->allocatePayment(
                $company->id,
                $payment->id,
                $obligation->id,
                '25.0000',
                'alloc-1',
                null,
                $allocator,
            );
            $participant->allocatePayment(
                $company->id,
                $payment->id,
                $obligation->id,
                '25.0000',
                'alloc-1',
                null,
                $allocator,
            );
        });

        $this->assertSame(1, DB::table('erp_payment_allocations')->count());
        $this->assertSame('25.0000', (string) $obligation->fresh()->open_amount);
    }
}
