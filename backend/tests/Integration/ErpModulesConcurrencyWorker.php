<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/bootstrap.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (config('database.default') !== 'pgsql' || config('database.connections.pgsql.database') !== 'opmoni_test') {
    throw new RuntimeException('ERP modules worker requires opmoni_test PostgreSQL.');
}

$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$operation = (string) ($input['operation'] ?? '');
waitForBarrier($input['barrier'] ?? null);

try {
    $result = match ($operation) {
        'insert-obligation' => insertObligation($input),
        'insert-payment' => insertPayment($input),
        'insert-payment-allocation' => insertPaymentAllocation($input),
        'insert-cash-session' => insertCashSession($input),
        'close-cash-session' => closeCashSession($input),
        'insert-fiscal-document' => insertFiscalDocument($input),
        'insert-stock-movement' => insertStockMovement($input),
        'insert-stock-transfer-pair' => insertStockTransferPair($input),
        'insert-sale-invoice' => insertSaleInvoice($input),
        default => ['ok' => false, 'error' => 'unknown_operation'],
    };
} catch (Throwable $throwable) {
    $result = [
        'ok' => false,
        'error' => $throwable->getMessage(),
        'sqlstate' => $throwable instanceof PDOException ? $throwable->getCode() : null,
    ];
}

echo json_encode($result, JSON_THROW_ON_ERROR);

function waitForBarrier(?string $barrier): void
{
    if ($barrier === null || $barrier === '') {
        return;
    }

    $deadline = microtime(true) + 20;
    while (! file_exists($barrier)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Concurrency barrier timed out.');
        }
        usleep(1000);
    }
}

/** @param array<string, mixed> $input */
function insertObligation(array $input): array
{
    DB::transaction(function () use ($input): void {
        DB::table('erp_companies')->where('id', $input['company_id'])->lockForUpdate()->first();
        DB::table('erp_obligations')->insert([
            'erp_company_id' => $input['company_id'],
            'direction' => 'payable',
            'category' => 'purchase',
            'amount' => $input['amount'],
            'open_amount' => $input['amount'],
            'source_type' => $input['source_type'],
            'source_id' => $input['source_id'],
            'source_operation_key' => $input['source_operation_key'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    return ['ok' => true];
}

/** @param array<string, mixed> $input */
function insertPayment(array $input): array
{
    DB::transaction(function () use ($input): void {
        DB::table('erp_companies')->where('id', $input['company_id'])->lockForUpdate()->first();
        DB::table('erp_payments')->insert([
            'erp_company_id' => $input['company_id'],
            'amount' => $input['amount'],
            'method' => 'cash',
            'source_type' => $input['source_type'],
            'source_id' => $input['source_id'],
            'source_operation_key' => $input['source_operation_key'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    return ['ok' => true];
}

/** @param array<string, mixed> $input */
function insertCashSession(array $input): array
{
    DB::transaction(function () use ($input): void {
        DB::table('erp_cash_registers')->where('id', $input['register_id'])->lockForUpdate()->first();
        DB::table('erp_cash_sessions')->insert([
            'erp_cash_register_id' => $input['register_id'],
            'erp_company_id' => $input['company_id'],
            'erp_establishment_id' => $input['establishment_id'],
            'operator_user_id' => $input['user_id'],
            'opening_amount' => $input['opening_amount'],
            'status' => 'open',
            'opened_by_user_id' => $input['user_id'],
            'opened_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    return ['ok' => true];
}

/** @param array<string, mixed> $input */
function insertFiscalDocument(array $input): array
{
    DB::transaction(function () use ($input): void {
        DB::table('erp_companies')->where('id', $input['company_id'])->lockForUpdate()->first();
        DB::table('erp_fiscal_documents')->insert([
            'erp_company_id' => $input['company_id'],
            'erp_establishment_id' => $input['establishment_id'],
            'model' => $input['model'],
            'number' => $input['number'],
            'idempotency_key' => $input['idempotency_key'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    return ['ok' => true];
}

/** @param array<string, mixed> $input */
function insertStockMovement(array $input): array
{
    DB::transaction(function () use ($input): void {
        DB::table('erp_companies')->where('id', $input['company_id'])->lockForUpdate()->first();
        DB::table('erp_stock_movements')->insert([
            'erp_company_id' => $input['company_id'],
            'erp_establishment_id' => $input['establishment_id'],
            'erp_product_variant_id' => $input['variant_id'],
            'direction' => $input['direction'] ?? 'in',
            'quantity' => $input['quantity'],
            'source_type' => $input['source_type'],
            'source_id' => $input['source_id'],
            'source_operation_key' => $input['source_operation_key'],
            'posted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    return ['ok' => true];
}

/** @param array<string, mixed> $input */
function insertPaymentAllocation(array $input): array
{
    DB::transaction(function () use ($input): void {
        DB::table('erp_companies')->where('id', $input['company_id'])->lockForUpdate()->first();
        DB::table('erp_payment_allocations')->insert([
            'erp_payment_id' => $input['payment_id'],
            'erp_obligation_id' => $input['obligation_id'],
            'amount' => $input['amount'],
            'source_operation_key' => $input['source_operation_key'],
            'posted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    return ['ok' => true];
}

/** @param array<string, mixed> $input */
function closeCashSession(array $input): array
{
    DB::transaction(function () use ($input): void {
        $session = DB::table('erp_cash_sessions')->where('id', $input['session_id'])->lockForUpdate()->first();
        if ($session === null) {
            throw new RuntimeException('Cash session not found.');
        }
        if ($session->status === 'closed') {
            if (($input['close_operation_key'] ?? null) === $session->close_operation_key) {
                return;
            }

            throw new RuntimeException('Cash session already closed.');
        }

        $updated = DB::table('erp_cash_sessions')
            ->where('id', $input['session_id'])
            ->where('status', 'open')
            ->update([
                'status' => 'closed',
                'expected_closing_amount' => $input['expected_closing_amount'],
                'declared_closing_amount' => $input['declared_closing_amount'],
                'closing_difference' => $input['closing_difference'],
                'close_operation_key' => $input['close_operation_key'],
                'closed_by_user_id' => $input['user_id'],
                'closed_at' => now(),
                'updated_at' => now(),
            ]);
        if ($updated === 0) {
            $session = DB::table('erp_cash_sessions')->where('id', $input['session_id'])->first();
            if ($session !== null
                && $session->status === 'closed'
                && ($input['close_operation_key'] ?? null) === $session->close_operation_key) {
                return;
            }

            throw new RuntimeException('Cash session close lost race.');
        }
    });

    return ['ok' => true];
}

/** @param array<string, mixed> $input */
function insertStockTransferPair(array $input): array
{
    DB::transaction(function () use ($input): void {
        DB::table('erp_companies')->where('id', $input['company_id'])->lockForUpdate()->first();
        $balance = DB::table('erp_stock_balances')
            ->where('erp_establishment_id', $input['from_establishment_id'])
            ->where('erp_product_variant_id', $input['variant_id'])
            ->lockForUpdate()
            ->first();
        if ($balance === null || bccomp((string) $balance->quantity_on_hand, (string) $input['quantity'], 4) === -1) {
            throw new RuntimeException('Insufficient stock for transfer.');
        }

        $remaining = bcsub((string) $balance->quantity_on_hand, (string) $input['quantity'], 4);
        DB::table('erp_stock_balances')->where('id', $balance->id)->update([
            'quantity_on_hand' => $remaining,
            'updated_at' => now(),
        ]);

        DB::table('erp_stock_movements')->insert([
            [
                'erp_company_id' => $input['company_id'],
                'erp_establishment_id' => $input['from_establishment_id'],
                'erp_product_variant_id' => $input['variant_id'],
                'direction' => 'out',
                'quantity' => $input['quantity'],
                'source_type' => 'erp_stock_transfer',
                'source_id' => 0,
                'source_operation_key' => $input['source_operation_key'].'-out',
                'posted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'erp_company_id' => $input['company_id'],
                'erp_establishment_id' => $input['to_establishment_id'],
                'erp_product_variant_id' => $input['variant_id'],
                'direction' => 'in',
                'quantity' => $input['quantity'],
                'source_type' => 'erp_stock_transfer',
                'source_id' => 0,
                'source_operation_key' => $input['source_operation_key'].'-in',
                'posted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    });

    return ['ok' => true];
}

/** @param array<string, mixed> $input */
function insertSaleInvoice(array $input): array
{
    DB::transaction(function () use ($input): void {
        DB::table('erp_companies')->where('id', $input['company_id'])->lockForUpdate()->first();
        DB::table('erp_sales')->insert([
            'erp_company_id' => $input['company_id'],
            'erp_establishment_id' => $input['establishment_id'],
            'invoice_no' => $input['invoice_no'],
            'status' => 'draft',
            'fiscal_status' => 'none',
            'subtotal_amount' => $input['total_amount'],
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => $input['total_amount'],
            'currency' => 'BRL',
            'source_operation_key' => $input['source_operation_key'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    return ['ok' => true];
}
