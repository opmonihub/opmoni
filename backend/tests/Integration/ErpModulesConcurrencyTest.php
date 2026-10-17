<?php

namespace Tests\Integration;

use App\Erp\Finance\ErpDecimal;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpEstablishment;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ErpModulesConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('pgsql', config('database.default'));
        $this->assertSame('opmoni_test', config('database.connections.pgsql.database'));
        DB::statement('SELECT pg_advisory_lock(42424242)');
        try {
            Artisan::call('migrate:fresh', ['--force' => true]);
        } finally {
            DB::statement('SELECT pg_advisory_unlock(42424242)');
        }
        Redis::connection('cache')->flushdb();
        Redis::connection('sessions')->flushdb();
    }

    /** @param array<string, mixed> $input */
    private function worker(array $input): Process
    {
        $process = new Process([PHP_BINARY, base_path('tests/Integration/ErpModulesConcurrencyWorker.php')], base_path());
        $process->setInput(json_encode($input, JSON_THROW_ON_ERROR));
        $process->setTimeout(30);

        return $process;
    }

    /** @return array<string, mixed> */
    private function response(Process $process): array
    {
        $process->wait();

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array<int, array<string, mixed>> $inputs */
    private function concurrent(array $inputs): array
    {
        $barrier = sys_get_temp_dir().'/opmoni-erp-modules-'.bin2hex(random_bytes(8));
        $workers = array_map(function (array $input) use ($barrier): Process {
            $worker = $this->worker($input + ['barrier' => $barrier]);
            $worker->start();

            return $worker;
        }, $inputs);

        usleep(50_000);
        touch($barrier);

        try {
            return array_map(fn (Process $worker) => $this->response($worker), $workers);
        } finally {
            @unlink($barrier);
        }
    }

    /** @return array{company_id: int, establishment_id: int, user_id: int, register_id: int} */
    private function seedCompanyGraph(): array
    {
        $user = User::factory()->create();
        $company = ErpCompany::factory()->create(['cnpj' => '30288513000100']);
        $establishment = ErpEstablishment::factory()->create(['erp_company_id' => $company->id]);
        $registerId = (int) DB::table('erp_cash_registers')->insertGetId([
            'erp_company_id' => $company->id,
            'erp_establishment_id' => $establishment->id,
            'name' => 'Caixa 1',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'company_id' => $company->id,
            'establishment_id' => $establishment->id,
            'user_id' => $user->id,
            'register_id' => $registerId,
        ];
    }

    public function test_concurrent_source_operation_keys_commit_single_finance_row(): void
    {
        $graph = $this->seedCompanyGraph();
        $payload = [
            'operation' => 'insert-obligation',
            'company_id' => $graph['company_id'],
            'amount' => ErpDecimal::parse('10,50'),
            'source_type' => 'erp_purchase',
            'source_id' => 42,
            'source_operation_key' => 'purchase-post-42',
        ];

        $results = $this->concurrent([$payload, $payload]);
        $successes = count(array_filter($results, fn (array $row): bool => ($row['ok'] ?? false) === true));
        $this->assertSame(1, $successes);
        $this->assertSame(1, DB::table('erp_obligations')->count());
    }

    public function test_concurrent_payment_and_stock_keys_remain_idempotent_per_source(): void
    {
        $graph = $this->seedCompanyGraph();
        $paymentResults = $this->concurrent([
            [
                'operation' => 'insert-payment',
                'company_id' => $graph['company_id'],
                'amount' => ErpDecimal::parse('25'),
                'source_type' => 'erp_sale',
                'source_id' => 7,
                'source_operation_key' => 'sale-post-7-payment',
            ],
            [
                'operation' => 'insert-payment',
                'company_id' => $graph['company_id'],
                'amount' => ErpDecimal::parse('25'),
                'source_type' => 'erp_sale',
                'source_id' => 7,
                'source_operation_key' => 'sale-post-7-payment',
            ],
        ]);
        $this->assertSame(1, count(array_filter($paymentResults, fn (array $row): bool => ($row['ok'] ?? false) === true)));

        $stockResults = $this->concurrent([
            [
                'operation' => 'insert-stock-movement',
                'company_id' => $graph['company_id'],
                'establishment_id' => $graph['establishment_id'],
                'variant_id' => 501,
                'quantity' => ErpDecimal::parse('3,0000'),
                'source_type' => 'erp_sale',
                'source_id' => 7,
                'source_operation_key' => 'sale-post-7-line-1',
            ],
            [
                'operation' => 'insert-stock-movement',
                'company_id' => $graph['company_id'],
                'establishment_id' => $graph['establishment_id'],
                'variant_id' => 501,
                'quantity' => ErpDecimal::parse('3,0000'),
                'source_type' => 'erp_sale',
                'source_id' => 7,
                'source_operation_key' => 'sale-post-7-line-1',
            ],
        ]);
        $this->assertSame(1, count(array_filter($stockResults, fn (array $row): bool => ($row['ok'] ?? false) === true)));
        $this->assertSame('3.0000', (string) DB::table('erp_stock_movements')->value('quantity'));
    }

    public function test_concurrent_cash_session_opening_keeps_single_active_session(): void
    {
        $graph = $this->seedCompanyGraph();
        $payload = [
            'operation' => 'insert-cash-session',
            'register_id' => $graph['register_id'],
            'company_id' => $graph['company_id'],
            'establishment_id' => $graph['establishment_id'],
            'user_id' => $graph['user_id'],
            'opening_amount' => ErpDecimal::parse('100'),
        ];

        $results = $this->concurrent([$payload, $payload]);
        $successes = count(array_filter($results, fn (array $row): bool => ($row['ok'] ?? false) === true));
        $this->assertSame(1, $successes);
        $this->assertSame(1, DB::table('erp_cash_sessions')->where('status', 'open')->count());
    }

    public function test_concurrent_fiscal_document_idempotency_key_is_unique(): void
    {
        $graph = $this->seedCompanyGraph();
        $payload = [
            'operation' => 'insert-fiscal-document',
            'company_id' => $graph['company_id'],
            'establishment_id' => $graph['establishment_id'],
            'model' => '55',
            'number' => 9001,
            'idempotency_key' => 'fiscal-doc-race-1',
        ];

        $results = $this->concurrent([$payload, $payload]);
        $successes = count(array_filter($results, fn (array $row): bool => ($row['ok'] ?? false) === true));
        $this->assertSame(1, $successes);
        $this->assertSame(1, DB::table('erp_fiscal_documents')->count());
    }

    public function test_finance_stock_fiscal_workers_share_company_lock_order_without_deadlock(): void
    {
        $graph = $this->seedCompanyGraph();
        $results = $this->concurrent([
            [
                'operation' => 'insert-obligation',
                'company_id' => $graph['company_id'],
                'amount' => ErpDecimal::parse('11'),
                'source_type' => 'erp_purchase',
                'source_id' => 1,
                'source_operation_key' => 'obligation-order-1',
            ],
            [
                'operation' => 'insert-stock-movement',
                'company_id' => $graph['company_id'],
                'establishment_id' => $graph['establishment_id'],
                'variant_id' => 77,
                'quantity' => ErpDecimal::parse('1'),
                'source_type' => 'erp_purchase',
                'source_id' => 1,
                'source_operation_key' => 'stock-order-1',
            ],
            [
                'operation' => 'insert-fiscal-document',
                'company_id' => $graph['company_id'],
                'establishment_id' => $graph['establishment_id'],
                'model' => '55',
                'number' => 77,
                'idempotency_key' => 'fiscal-order-1',
            ],
        ]);

        foreach ($results as $result) {
            $this->assertTrue($result['ok'] ?? false, $result['error'] ?? 'worker failed');
        }
    }

    public function test_concurrent_payment_allocations_commit_single_row_per_operation_key(): void
    {
        $graph = $this->seedCompanyGraph();
        $obligationId = (int) DB::table('erp_obligations')->insertGetId([
            'erp_company_id' => $graph['company_id'],
            'direction' => 'receivable',
            'category' => 'sale',
            'amount' => ErpDecimal::parse('40'),
            'open_amount' => ErpDecimal::parse('40'),
            'source_type' => 'erp_sale',
            'source_id' => 1,
            'source_operation_key' => 'obligation-for-alloc',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $paymentId = (int) DB::table('erp_payments')->insertGetId([
            'erp_company_id' => $graph['company_id'],
            'amount' => ErpDecimal::parse('40'),
            'method' => 'cash',
            'source_type' => 'manual',
            'source_id' => 0,
            'source_operation_key' => 'payment-for-alloc',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $payload = [
            'operation' => 'insert-payment-allocation',
            'company_id' => $graph['company_id'],
            'payment_id' => $paymentId,
            'obligation_id' => $obligationId,
            'amount' => ErpDecimal::parse('15'),
            'source_operation_key' => 'alloc-race-1',
        ];

        $results = $this->concurrent([$payload, $payload]);
        $successes = count(array_filter($results, fn (array $row): bool => ($row['ok'] ?? false) === true));
        $this->assertSame(1, $successes);
        $this->assertSame(1, DB::table('erp_payment_allocations')->count());
    }

    public function test_concurrent_cash_session_close_keeps_single_closed_state(): void
    {
        $graph = $this->seedCompanyGraph();
        $sessionId = (int) DB::table('erp_cash_sessions')->insertGetId([
            'erp_cash_register_id' => $graph['register_id'],
            'erp_company_id' => $graph['company_id'],
            'erp_establishment_id' => $graph['establishment_id'],
            'operator_user_id' => $graph['user_id'],
            'opening_amount' => ErpDecimal::parse('100'),
            'status' => 'open',
            'opened_by_user_id' => $graph['user_id'],
            'opened_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $payload = [
            'operation' => 'close-cash-session',
            'session_id' => $sessionId,
            'user_id' => $graph['user_id'],
            'expected_closing_amount' => ErpDecimal::parse('100'),
            'declared_closing_amount' => ErpDecimal::parse('100'),
            'closing_difference' => ErpDecimal::parse('0'),
            'close_operation_key' => 'close-race-1',
        ];

        $results = $this->concurrent([$payload, $payload]);
        $successes = count(array_filter($results, fn (array $row): bool => ($row['ok'] ?? false) === true));
        $this->assertGreaterThanOrEqual(1, $successes);
        $this->assertSame(1, DB::table('erp_cash_sessions')->where('status', 'closed')->count());
    }

    public function test_concurrent_stock_transfer_pair_conserves_quantity_without_partial_writes(): void
    {
        $graph = $this->seedCompanyGraph();
        $toEstablishmentId = (int) DB::table('erp_establishments')->insertGetId([
            'erp_company_id' => $graph['company_id'],
            'name' => 'Filial B',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('erp_stock_balances')->insert([
            'erp_company_id' => $graph['company_id'],
            'erp_establishment_id' => $graph['establishment_id'],
            'erp_product_variant_id' => 900,
            'quantity_on_hand' => ErpDecimal::parse('5'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $payload = [
            'operation' => 'insert-stock-transfer-pair',
            'company_id' => $graph['company_id'],
            'from_establishment_id' => $graph['establishment_id'],
            'to_establishment_id' => $toEstablishmentId,
            'variant_id' => 900,
            'quantity' => ErpDecimal::parse('4'),
            'source_operation_key' => 'transfer-race-1',
        ];

        $results = $this->concurrent([$payload, $payload]);
        $successes = count(array_filter($results, fn (array $row): bool => ($row['ok'] ?? false) === true));
        $this->assertSame(1, $successes);
        $this->assertSame(2, DB::table('erp_stock_movements')->count());
        $this->assertSame('1.0000', (string) DB::table('erp_stock_balances')->value('quantity_on_hand'));
    }

    public function test_concurrent_purchase_stock_keys_remain_idempotent_per_source(): void
    {
        $graph = $this->seedCompanyGraph();
        $payload = [
            'operation' => 'insert-stock-movement',
            'company_id' => $graph['company_id'],
            'establishment_id' => $graph['establishment_id'],
            'variant_id' => 808,
            'quantity' => ErpDecimal::parse('2'),
            'direction' => 'in',
            'source_type' => 'erp_purchase',
            'source_id' => 9,
            'source_operation_key' => 'purchase-post-9-line-1',
        ];

        $results = $this->concurrent([$payload, $payload]);
        $successes = count(array_filter($results, fn (array $row): bool => ($row['ok'] ?? false) === true));
        $this->assertSame(1, $successes);
        $this->assertSame(1, DB::table('erp_stock_movements')->where('source_type', 'erp_purchase')->count());
    }

    public function test_concurrent_sale_invoice_numbers_commit_single_row(): void
    {
        $graph = $this->seedCompanyGraph();
        $payload = [
            'operation' => 'insert-sale-invoice',
            'company_id' => $graph['company_id'],
            'establishment_id' => $graph['establishment_id'],
            'invoice_no' => 'PDV-9001',
            'total_amount' => ErpDecimal::parse('99'),
            'source_operation_key' => 'sale-draft-9001',
        ];

        $results = $this->concurrent([$payload, $payload]);
        $successes = count(array_filter($results, fn (array $row): bool => ($row['ok'] ?? false) === true));
        $this->assertSame(1, $successes);
        $this->assertSame(1, DB::table('erp_sales')->where('invoice_no', 'PDV-9001')->count());
    }
}
