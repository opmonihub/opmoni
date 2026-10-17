<?php

namespace App\Erp\Stock;

use App\Erp\Finance\ErpDecimal;
use App\Models\Erp\Stock\ErpStockBalance;
use App\Models\Erp\Stock\ErpStockMovement;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ErpStockParticipant
{
    /**
     * @param  list<array{line_id: int, erp_product_variant_id: int|null, quantity: string}>  $lines
     */
    public function issueForSale(
        int $companyId,
        int $establishmentId,
        int $saleId,
        string $operationKeyPrefix,
        array $lines,
        ?int $postedByUserId,
    ): void {
        $this->assertInCallerTransaction();

        $issueLines = array_values(array_filter(
            $lines,
            fn (array $line): bool => $line['erp_product_variant_id'] !== null,
        ));
        usort(
            $issueLines,
            fn (array $left, array $right): int => $left['erp_product_variant_id'] <=> $right['erp_product_variant_id'],
        );

        DB::table('erp_companies')->where('id', $companyId)->lockForUpdate()->first();

        foreach ($issueLines as $line) {
            $variantId = (int) $line['erp_product_variant_id'];
            $needed = ErpDecimal::parse($line['quantity']);

            $balance = ErpStockBalance::query()
                ->where('erp_establishment_id', $establishmentId)
                ->where('erp_product_variant_id', $variantId)
                ->lockForUpdate()
                ->first();

            $available = $balance ? ErpDecimal::parse((string) $balance->quantity_on_hand) : ErpDecimal::zero();
            if (ErpDecimal::compare($available, $needed) === -1) {
                throw new InvalidArgumentException('Estoque insuficiente');
            }

            if ($balance) {
                $balance->quantity_on_hand = ErpDecimal::sub($available, $needed);
                $balance->save();
            }

            ErpStockMovement::query()->create([
                'erp_company_id' => $companyId,
                'erp_establishment_id' => $establishmentId,
                'erp_product_variant_id' => $variantId,
                'direction' => 'out',
                'quantity' => $needed,
                'unit_cost' => $balance?->average_cost,
                'source_type' => 'erp_sale',
                'source_id' => $saleId,
                'source_operation_key' => $operationKeyPrefix.'-line-'.$line['line_id'],
                'posted_by_user_id' => $postedByUserId,
                'posted_at' => now(),
            ]);
        }
    }

    /**
     * @param  list<ErpStockMovement>  $originalMovements
     */
    public function reverseSaleIssues(
        int $companyId,
        int $establishmentId,
        int $saleReturnId,
        string $operationKeyPrefix,
        iterable $originalMovements,
        ?int $postedByUserId,
    ): void {
        $this->assertInCallerTransaction();

        DB::table('erp_companies')->where('id', $companyId)->lockForUpdate()->first();

        $movements = [];
        foreach ($originalMovements as $movement) {
            $movements[] = $movement;
        }
        usort(
            $movements,
            fn (ErpStockMovement $left, ErpStockMovement $right): int => $left->erp_product_variant_id <=> $right->erp_product_variant_id,
        );

        foreach ($movements as $movement) {
            if ($movement->direction !== 'out' || $movement->erp_product_variant_id === null) {
                continue;
            }

            $quantity = ErpDecimal::parse((string) $movement->quantity);
            $balance = ErpStockBalance::query()->firstOrCreate(
                [
                    'erp_establishment_id' => $establishmentId,
                    'erp_product_variant_id' => $movement->erp_product_variant_id,
                ],
                [
                    'erp_company_id' => $companyId,
                    'quantity_on_hand' => ErpDecimal::zero(),
                ],
            );
            $balance->quantity_on_hand = ErpDecimal::add(
                ErpDecimal::parse((string) $balance->quantity_on_hand),
                $quantity,
            );
            $balance->save();

            ErpStockMovement::query()->create([
                'erp_company_id' => $companyId,
                'erp_establishment_id' => $establishmentId,
                'erp_product_variant_id' => $movement->erp_product_variant_id,
                'direction' => 'in',
                'quantity' => $quantity,
                'unit_cost' => $movement->unit_cost,
                'source_type' => 'erp_sale_return',
                'source_id' => $saleReturnId,
                'source_operation_key' => $operationKeyPrefix.'-reverse-'.$movement->id,
                'reversal_of_id' => $movement->id,
                'posted_by_user_id' => $postedByUserId,
                'posted_at' => now(),
            ]);
        }
    }

    private function assertInCallerTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new InvalidArgumentException('Stock participant must join the caller transaction.');
        }
    }
}
