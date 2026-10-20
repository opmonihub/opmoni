<?php

namespace App\Erp\Stock;

use App\Erp\ErpCatalogScope;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpProductVariant;
use App\Models\Erp\Stock\ErpStockBalance;
use App\Models\Erp\Stock\ErpStockMovement;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ErpStockParticipant
{
    /** @param list<array{line_id:int,erp_product_variant_id:?int,quantity:string,unit_conversion_factor?:string}> $lines */
    public function issueForSale(int $companyId, int $establishmentId, int $saleId, string $operationKeyPrefix, array $lines, ?int $postedByUserId, ?string $sourceCurrency = null): void
    {
        $this->assertInCallerTransaction();
        $company = ErpCompany::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
        usort($lines, fn (array $a, array $b): int => [$a['erp_product_variant_id'], $a['line_id']] <=> [$b['erp_product_variant_id'], $b['line_id']]);
        foreach ($lines as $line) {
            if ($line['erp_product_variant_id'] === null) {
                continue;
            }
            app(ErpCatalogScope::class)->assertVariantBelongsToCompany($companyId, $line['erp_product_variant_id']);
            $variant = ErpProductVariant::query()->findOrFail($line['erp_product_variant_id']);
            $profile = $line['product_snapshot']['product'] ?? ['kind' => $variant->product->kind, 'track_stock' => $variant->product->track_stock];
            $physical = self::physicalProfile($profile);
            abort_if($physical === null, response()->json(['code' => 'legacy_operation_requires_reconciliation'], 409));
            if (! $physical) {
                continue;
            }
            $quantity = ErpStockDecimal::base($line['quantity'], $line['unit_conversion_factor'] ?? '1.00000000', 'quantity');
            $available = ErpStockBalance::query()->where('erp_company_id', $companyId)->where('erp_establishment_id', $establishmentId)->where('erp_product_variant_id', $variant->id)->value('quantity_on_hand') ?? '0';
            $allow = false;
            if (bccomp((string) $available, $quantity, 4) < 0 && ($company->operating_settings['stock_overselling_enabled'] ?? false) === true) {
                $context = CurrentCompany::fromRequest();
                $permissions = $context->accessKind === 'delegated' ? ($context->delegation?->effectivePermissions() ?? []) : $context->permissions();
                abort_unless(in_array('erp.stock.oversell', $permissions, true) || in_array('*', $permissions, true), 403, 'Permissão explícita de venda sem estoque necessária.');
                $context->assertPermission('erp.stock.oversell', true);
                $allow = true;
            }
            app(ErpStockLedger::class)->issue($companyId, $establishmentId, $variant->id, $quantity, ['source_type' => 'erp_sale', 'source_id' => $saleId, 'source_line_id' => $line['line_id'], 'posted_by_user_id' => $postedByUserId, 'source_currency' => $sourceCurrency], $operationKeyPrefix.'-line-'.$line['line_id'], $allow);
        }
    }

    /** @param iterable<ErpStockMovement> $originalMovements */
    public function reverseSaleIssues(int $companyId, int $establishmentId, int $saleReturnId, string $operationKeyPrefix, iterable $originalMovements, ?int $postedByUserId): void
    {
        $this->assertInCallerTransaction();
        ErpCompany::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
        foreach ($originalMovements as $movement) {
            abort_unless($movement->erp_company_id === $companyId && $movement->erp_establishment_id === $establishmentId, 422);
            app(ErpStockLedger::class)->reverse($movement, (string) $movement->quantity, 'Devolução de venda', $operationKeyPrefix.'-reverse-'.$movement->id, ['source_type' => 'erp_sale_return', 'source_id' => $saleReturnId, 'source_line_id' => $movement->source_line_id, 'posted_by_user_id' => $postedByUserId]);
        }
    }

    /** Null means that saved physical semantics are not proved. */
    public static function physicalProfile(?array $profile): ?bool
    {
        if (($profile['kind'] ?? null) === 'service') {
            return false;
        }
        if (($profile['kind'] ?? null) === 'goods' && is_bool($profile['track_stock'] ?? null)) {
            return $profile['track_stock'];
        }

        return null;
    }

    private function assertInCallerTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new InvalidArgumentException('Stock participant must join the caller transaction.');
        }
    }
}
