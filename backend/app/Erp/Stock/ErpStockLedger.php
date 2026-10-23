<?php

namespace App\Erp\Stock;

use App\Erp\ErpCatalogScope;
use App\Erp\Finance\ErpDecimal;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\Stock\ErpStockAllocation;
use App\Models\Erp\Stock\ErpStockBalance;
use App\Models\Erp\Stock\ErpStockCostSettlement;
use App\Models\Erp\Stock\ErpStockLot;
use App\Models\Erp\Stock\ErpStockMovement;
use App\Models\Erp\Stock\ErpStockTransfer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class ErpStockLedger
{
    /** @param array<string,mixed> $source */
    public function receive(int $company, int $establishment, int $variant, string $quantity, ?string $unitCost, array $source, string $operation, ?string $totalCost = null, ?string $currency = null): ErpStockMovement
    {
        $this->lockCompany($company);
        $quantity = $this->positive($quantity);
        $totalCost ??= $unitCost === null ? null : ErpDecimal::mul($quantity, $unitCost);
        if ($totalCost !== null) {
            $totalCost = ErpDecimal::parse($totalCost);
            abort_if(bccomp($totalCost, '0', 4) < 0, 422, 'Custo não pode ser negativo.');
        }
        if ($existing = $this->existing($company, $establishment, $variant, 'in', $quantity, $source, $operation)) {
            abort_unless($existing->total_cost === $totalCost && $existing->currency === $currency, 409, 'Custo de operação divergente.');

            return $existing;
        }
        $this->assertReferences($company, $establishment, $variant);
        $balance = $this->balance($company, $establishment, $variant);
        $movement = $this->movement($company, $establishment, $variant, 'in', $quantity, $source, $operation, $currency);
        $lot = $this->lot($movement, $quantity, $totalCost, $currency);
        $this->settle($lot, $movement);
        $balance->quantity_on_hand = ErpDecimal::add((string) $balance->quantity_on_hand, $quantity);
        $balance->save();
        $this->summarize($movement);
        $this->displayAverage($balance);

        return $movement;
    }

    /** @param array<string,mixed> $source */
    public function issue(int $company, int $establishment, int $variant, string $quantity, array $source, string $operation, bool $allowDeficit = false): ErpStockMovement
    {
        $model = $this->lockCompany($company);
        $quantity = $this->positive($quantity);
        if ($existing = $this->existing($company, $establishment, $variant, 'out', $quantity, $source, $operation)) {
            return $existing;
        }
        $allowDeficit = $allowDeficit && ($model->operating_settings['stock_overselling_enabled'] ?? false) === true;
        $this->assertReferences($company, $establishment, $variant);
        $balance = $this->balance($company, $establishment, $variant);
        if (! $allowDeficit && bccomp((string) $balance->quantity_on_hand, $quantity, 4) < 0) {
            throw ValidationException::withMessages(['quantity' => 'Estoque insuficiente.']);
        }
        $method = $model->operating_settings['accounting_method'] ?? 'fifo';
        abort_unless(in_array($method, ['fifo', 'lifo'], true), 422, 'Método de estoque inválido.');
        $movement = $this->movement($company, $establishment, $variant, 'out', $quantity, $source, $operation, $source['currency'] ?? null, $method);
        $lots = $this->position(ErpStockLot::query(), $company, $establishment, $variant)->where('available_quantity', '>', 0)
            ->orderBy('received_at', $method === 'fifo' ? 'asc' : 'desc')->orderByRaw('COALESCE(root_lot_id, id) '.($method === 'fifo' ? 'asc' : 'desc'))->orderBy('id', $method === 'fifo' ? 'asc' : 'desc')->lockForUpdate()->get();
        $needed = $quantity;
        $ordinal = 0;
        foreach ($lots as $lot) {
            if (bccomp($needed, '0', 4) === 0) {
                break;
            }
            $take = ErpStockDecimal::min($needed, (string) $lot->available_quantity);
            $cost = $this->consume($lot, $take);
            $this->allocation($movement, $lot, $take, $cost, $ordinal++);
            $needed = bcsub($needed, $take, 4);
        }
        if (bccomp($needed, '0', 4) > 0) {
            abort_unless($allowDeficit && ($source['source_type'] ?? null) === 'erp_sale', 422, 'Estoque sem camadas conciliadas.');
            $this->allocation($movement, null, $needed, null, $ordinal);
        }
        $balance->quantity_on_hand = bcsub((string) $balance->quantity_on_hand, $quantity, 4);
        $balance->save();
        $this->summarize($movement);
        $this->displayAverage($balance);

        return $movement;
    }

    /** @param array<string,mixed> $source */
    public function reverse(ErpStockMovement $original, string $quantity, string $reason, string $operation, array $source, ?array $allocationQuantities = null): ErpStockMovement
    {
        $this->lockCompany($original->erp_company_id);
        $original = ErpStockMovement::query()->whereKey($original->id)->lockForUpdate()->firstOrFail();
        $quantity = $this->positive($quantity);
        $source['reversal_of_id'] = $original->id;
        $source['reason'] = $reason;
        if ($existing = $this->existing($original->erp_company_id, $original->erp_establishment_id, $original->erp_product_variant_id, $original->direction === 'out' ? 'in' : 'out', $quantity, $source, $operation)) {
            return $existing;
        }
        $returned = '0.0000';
        foreach (ErpStockMovement::query()->where('reversal_of_id', $original->id)->pluck('quantity') as $returnedQuantity) {
            $returned = bcadd($returned, (string) $returnedQuantity, 4);
        }
        abort_if(bccomp(bcadd((string) $returned, $quantity, 4), (string) $original->quantity, 4) > 0, 422, 'Quantidade já devolvida.');
        $this->assertReferences($original->erp_company_id, $original->erp_establishment_id, $original->erp_product_variant_id);
        $balance = $this->balance($original->erp_company_id, $original->erp_establishment_id, $original->erp_product_variant_id);
        $movement = $this->movement($original->erp_company_id, $original->erp_establishment_id, $original->erp_product_variant_id, $original->direction === 'out' ? 'in' : 'out', $quantity, $source, $operation, $original->currency, $original->valuation_method);
        $needed = $quantity;
        $ordinal = 0;
        if ($original->direction === 'out') {
            $allocations = ErpStockAllocation::query()->where('erp_stock_movement_id', $original->id)->where('kind', 'issue')->orderByDesc('ordinal')->lockForUpdate()->get();
            abort_if($allocations->isEmpty(), 409, 'Histórico de estoque exige conciliação.');
            foreach ($allocations as $allocation) {
                $available = bcsub((string) $allocation->quantity, (string) $allocation->returned_quantity, 4);
                $selection = $allocationQuantities[$allocation->id] ?? null;
                $take = $allocationQuantities === null ? ErpStockDecimal::min($needed, $available) : ($selection['quantity'] ?? '0.0000');
                abort_if(bccomp($take, $available, 4) > 0, 422, 'Alocação de origem já devolvida.');
                if (bccomp($take, '0', 4) <= 0) {
                    continue;
                }
                if ($allocation->erp_stock_lot_id === null) {
                    $this->reverseDeficit($allocation, $take, $movement, $ordinal);
                } else {
                    $cost = $selection !== null ? $selection['cost'] : ($allocation->cost === null ? null : ErpStockDecimal::slice((string) $allocation->cost, (string) $allocation->quantity, bcadd((string) $allocation->returned_quantity, $take, 4), (string) $allocation->returned_cost));
                    abort_if($cost !== null && (bccomp($cost, '0', 4) < 0 || ($allocation->cost !== null && bccomp(bcadd((string) $allocation->returned_cost, $cost, 4), (string) $allocation->cost, 4) > 0)), 422, 'Custo de origem indisponível.');
                    $parent = ErpStockLot::query()->whereKey($allocation->erp_stock_lot_id)->lockForUpdate()->firstOrFail();
                    $this->lot($movement, $take, $cost, $allocation->currency, $parent, $allocation->id);
                    $this->allocation($movement, null, $take, $cost, $ordinal++, 'return', $allocation->id, $allocation->currency);
                    if ($allocation->cost !== null) {
                        $allocation->returned_cost = bcadd((string) $allocation->returned_cost, $cost ?? '0', 4);
                    }
                }
                $allocation->returned_quantity = bcadd((string) $allocation->returned_quantity, $take, 4);
                $allocation->save();
                $needed = bcsub($needed, $take, 4);
                if (bccomp($needed, '0', 4) === 0) {
                    break;
                }
            }
        } else {
            $eligible = ErpStockLot::query()->where('erp_stock_movement_id', $original->id)->pluck('id')->all();
            abort_if($eligible === [], 409, 'Histórico de estoque exige conciliação.');
            $all = ErpStockLot::query()->where('erp_company_id', $original->erp_company_id)->where('erp_product_variant_id', $original->erp_product_variant_id)->orderBy('id')->get();
            foreach ($all as $candidate) {
                if ($candidate->parent_lot_id !== null && in_array($candidate->parent_lot_id, $eligible, true)) {
                    $eligible[] = $candidate->id;
                }
            }
            $lots = $this->position(ErpStockLot::query(), $original->erp_company_id, $original->erp_establishment_id, $original->erp_product_variant_id)->whereIn('id', $eligible)->where('available_quantity', '>', 0)->orderByDesc('id')->lockForUpdate()->get();
            foreach ($lots as $lot) {
                $take = ErpStockDecimal::min($needed, (string) $lot->available_quantity);
                $cost = $this->consume($lot, $take);
                $this->allocation($movement, $lot, $take, $cost, $ordinal++);
                $needed = bcsub($needed, $take, 4);
                if (bccomp($needed, '0', 4) === 0) {
                    break;
                }
            }
        }
        abort_unless(bccomp($needed, '0', 4) === 0, 422, 'Estoque da origem indisponível para devolução.');
        $balance->quantity_on_hand = $original->direction === 'out' ? bcadd((string) $balance->quantity_on_hand, $quantity, 4) : bcsub((string) $balance->quantity_on_hand, $quantity, 4);
        $balance->save();
        $this->summarize($movement);
        $this->displayAverage($balance);

        return $movement;
    }

    /** @param array<string,mixed> $source
     * @return array{ErpStockMovement,ErpStockMovement}
     */
    public function transfer(int $company, int $from, int $to, int $variant, string $quantity, array $source, string $operation): array
    {
        $this->lockCompany($company);
        abort_if($from === $to, 422, 'Estabelecimentos devem ser diferentes.');
        $this->assertReferences($company, $from, $variant);
        $this->assertReferences($company, $to, $variant);
        foreach (collect([$from, $to])->sort() as $establishment) {
            $this->balance($company, $establishment, $variant);
        }
        $debit = $this->issue($company, $from, $variant, $quantity, $source, $operation.'-out');
        $credit = $this->movement($company, $to, $variant, 'in', $quantity, $source, $operation.'-in', null, $debit->valuation_method);
        foreach (ErpStockAllocation::query()->where('erp_stock_movement_id', $debit->id)->orderBy('ordinal')->get() as $allocation) {
            $parent = ErpStockLot::query()->findOrFail($allocation->erp_stock_lot_id);
            $lot = $this->lot($credit, (string) $allocation->quantity, $allocation->cost, $allocation->currency, $parent, $allocation->id);
            $this->settle($lot, $credit);
        }
        $destination = $this->balance($company, $to, $variant);
        $destination->quantity_on_hand = bcadd((string) $destination->quantity_on_hand, $quantity, 4);
        $destination->save();
        $this->summarize($credit);
        $this->displayAverage($destination);

        return [$debit, $credit];
    }

    /** @return array{ErpStockMovement,ErpStockMovement} */
    public function reverseTransfer(ErpStockTransfer $transfer, string $quantity, string $reason, string $operation, ?int $actor): array
    {
        $this->lockCompany($transfer->erp_company_id);
        $transfer = ErpStockTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
        $legs = ErpStockMovement::query()->where('erp_company_id', $transfer->erp_company_id)->where('source_type', 'erp_stock_transfer')->where('source_id', $transfer->id)->lockForUpdate()->get()->keyBy('direction');
        abort_unless($legs->count() === 2, 409, 'Transferência antiga exige conciliação.');
        foreach (collect([$transfer->from_establishment_id, $transfer->to_establishment_id])->sort() as $establishment) {
            $this->assertReferences($transfer->erp_company_id, $establishment, $transfer->erp_product_variant_id);
            $this->balance($transfer->erp_company_id, $establishment, $transfer->erp_product_variant_id);
        }
        $source = ['source_type' => 'erp_stock_transfer_return', 'source_id' => $transfer->id, 'posted_by_user_id' => $actor];
        $debit = $this->reverse($legs['in'], $quantity, $reason, $operation.'-out', $source);
        $selected = [];
        foreach (ErpStockAllocation::query()->where('erp_stock_movement_id', $debit->id)->orderBy('ordinal')->get() as $allocation) {
            $lot = ErpStockLot::query()->findOrFail($allocation->erp_stock_lot_id);
            while ($lot->erp_stock_movement_id !== $legs['in']->id && $lot->parent_lot_id !== null) {
                $lot = ErpStockLot::query()->findOrFail($lot->parent_lot_id);
            }
            abort_unless($lot->erp_stock_movement_id === $legs['in']->id && $lot->parent_allocation_id !== null, 422, 'Camada sem vínculo à transferência.');
            $id = $lot->parent_allocation_id;
            $selected[$id] ??= ['quantity' => '0.0000', 'cost' => $allocation->cost === null ? null : '0.0000'];
            $selected[$id]['quantity'] = bcadd($selected[$id]['quantity'], (string) $allocation->quantity, 4);
            $selected[$id]['cost'] = $selected[$id]['cost'] === null || $allocation->cost === null ? null : bcadd($selected[$id]['cost'], (string) $allocation->cost, 4);
        }
        $credit = $this->reverse($legs['out'], $quantity, $reason, $operation.'-in', $source, $selected);

        return [$debit, $credit];
    }

    private function reverseDeficit(ErpStockAllocation $allocation, string $quantity, ErpStockMovement $movement, int &$ordinal): void
    {
        $settledReturns = '0.0000';
        foreach (ErpStockCostSettlement::query()->where('erp_stock_allocation_id', $allocation->id)->whereNull('reversal_of_id')->pluck('returned_quantity') as $returned) {
            $settledReturns = bcadd($settledReturns, (string) $returned, 4);
        }
        $canceled = bcsub((string) $allocation->returned_quantity, $settledReturns, 4);
        $unsettled = bcsub(bcsub((string) $allocation->quantity, (string) $allocation->settled_quantity, 4), $canceled, 4);
        $cancel = ErpStockDecimal::min($quantity, bccomp($unsettled, '0', 4) > 0 ? $unsettled : '0.0000');
        $needed = bcsub($quantity, $cancel, 4);
        if (bccomp($cancel, '0', 4) > 0) {
            $this->allocation($movement, null, $cancel, null, $ordinal++, 'return', $allocation->id);
        }
        foreach (ErpStockCostSettlement::query()->where('erp_stock_allocation_id', $allocation->id)->whereNull('reversal_of_id')->orderByDesc('id')->lockForUpdate()->get() as $settlement) {
            if (bccomp($needed, '0', 4) === 0) {
                break;
            }
            $take = ErpStockDecimal::min($needed, bcsub((string) $settlement->quantity, (string) $settlement->returned_quantity, 4));
            if (bccomp($take, '0', 4) <= 0) {
                continue;
            }
            $cost = $settlement->cost === null ? null : ErpStockDecimal::slice((string) $settlement->cost, (string) $settlement->quantity, bcadd((string) $settlement->returned_quantity, $take, 4), (string) $settlement->returned_cost);
            $parent = ErpStockLot::query()->whereKey($settlement->erp_stock_lot_id)->lockForUpdate()->firstOrFail();
            $this->lot($movement, $take, $cost, $settlement->currency, $parent, $allocation->id, $settlement->id);
            ErpStockCostSettlement::query()->create(['erp_company_id' => $allocation->erp_company_id, 'erp_establishment_id' => $allocation->erp_establishment_id, 'erp_product_variant_id' => $allocation->erp_product_variant_id, 'erp_stock_allocation_id' => $allocation->id, 'erp_stock_lot_id' => $parent->id, 'erp_stock_movement_id' => $movement->id, 'reversal_of_id' => $settlement->id, 'quantity' => $take, 'cost' => $cost, 'currency' => $settlement->currency, 'posted_at' => now()]);
            $settlement->returned_quantity = bcadd((string) $settlement->returned_quantity, $take, 4);
            $settlement->returned_cost = bcadd((string) $settlement->returned_cost, $cost ?? '0', 4);
            $settlement->save();
            $this->allocation($movement, $parent, $take, $cost, $ordinal++, 'return', $allocation->id, $settlement->currency);
            $needed = bcsub($needed, $take, 4);
        }
        abort_unless(bccomp($needed, '0', 4) === 0, 422, 'Déficit sem conciliação de origem.');

    }

    private function settle(ErpStockLot $lot, ErpStockMovement $movement): void
    {
        $deficits = $this->position(ErpStockAllocation::query(), $lot->erp_company_id, $lot->erp_establishment_id, $lot->erp_product_variant_id)->whereNull('erp_stock_lot_id')->whereNull('original_allocation_id')->where('kind', 'issue')->orderBy('issued_at')->orderBy('erp_stock_movement_id')->orderBy('ordinal')->orderBy('id')->lockForUpdate()->get();
        foreach ($deficits as $deficit) {
            if (bccomp((string) $lot->available_quantity, '0', 4) === 0) {
                break;
            }
            $settledReturns = '0.0000';
            foreach (ErpStockCostSettlement::query()->where('erp_stock_allocation_id', $deficit->id)->whereNull('reversal_of_id')->pluck('returned_quantity') as $returned) {
                $settledReturns = bcadd($settledReturns, (string) $returned, 4);
            }
            $canceled = bcsub((string) $deficit->returned_quantity, $settledReturns, 4);
            $needed = bcsub(bcsub((string) $deficit->quantity, (string) $deficit->settled_quantity, 4), $canceled, 4);
            if (bccomp($needed, '0', 4) <= 0) {
                continue;
            }
            $take = ErpStockDecimal::min($needed, (string) $lot->available_quantity);
            $cost = $this->consume($lot, $take);
            ErpStockCostSettlement::query()->create(['erp_company_id' => $lot->erp_company_id, 'erp_establishment_id' => $lot->erp_establishment_id, 'erp_product_variant_id' => $lot->erp_product_variant_id, 'erp_stock_allocation_id' => $deficit->id, 'erp_stock_lot_id' => $lot->id, 'erp_stock_movement_id' => $movement->id, 'quantity' => $take, 'cost' => $cost, 'currency' => $lot->currency, 'posted_at' => now()]);
            $deficit->settled_quantity = bcadd((string) $deficit->settled_quantity, $take, 4);
            $deficit->save();
        }
    }

    private function lockCompany(int $id): ErpCompany
    {
        if (DB::transactionLevel() === 0) {
            throw new InvalidArgumentException('Stock ledger must join the caller transaction.');
        }

        return ErpCompany::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    private function positive(string $quantity): string
    {
        $quantity = ErpDecimal::parse($quantity);
        if (! ErpDecimal::isPositive($quantity)) {
            throw ValidationException::withMessages(['quantity' => 'Quantidade deve ser positiva.']);
        }

        return $quantity;
    }

    private function assertReferences(int $company, int $establishment, int $variant): void
    {
        $scope = app(ErpCatalogScope::class);
        $scope->assertActiveEstablishment($company, $establishment);
        $scope->assertVariantBelongsToCompany($company, $variant);
    }

    private function position(Builder $query, int $company, int $establishment, int $variant): Builder
    {
        return $query->where('erp_company_id', $company)->where('erp_establishment_id', $establishment)->where('erp_product_variant_id', $variant);
    }

    private function balance(int $company, int $establishment, int $variant): ErpStockBalance
    {
        $balance = ErpStockBalance::query()->firstOrCreate(['erp_company_id' => $company, 'erp_establishment_id' => $establishment, 'erp_product_variant_id' => $variant], ['quantity_on_hand' => '0.0000', 'ledger_initialized_at' => now()]);
        $balance = ErpStockBalance::query()->whereKey($balance->id)->lockForUpdate()->firstOrFail();
        if ($balance->ledger_initialized_at === null) {
            $quantity = (string) $balance->quantity_on_hand;
            $base = ['erp_company_id' => $company, 'erp_establishment_id' => $establishment, 'erp_product_variant_id' => $variant, 'opening_balance_id' => $balance->id, 'cost_status' => 'legacy_unknown'];
            if (bccomp($quantity, '0', 4) > 0) {
                ErpStockLot::query()->create([...$base, 'quantity' => $quantity, 'available_quantity' => $quantity, 'received_at' => now()]);
            } elseif (bccomp($quantity, '0', 4) < 0) {
                ErpStockAllocation::query()->create([...$base, 'quantity' => bcsub('0', $quantity, 4), 'ordinal' => 0, 'issued_at' => now()]);
            }
            $balance->ledger_initialized_at = now();
            $balance->ledger_reconciliation = ['provenance' => 'legacy_unknown', 'needs_reconciliation' => true];
            $balance->save();
        }

        return $balance;
    }

    /** @param array<string,mixed> $source */
    private function movement(int $company, int $establishment, int $variant, string $direction, string $quantity, array $source, string $key, ?string $currency, string $method = 'fifo'): ErpStockMovement
    {
        return ErpStockMovement::query()->create(['erp_company_id' => $company, 'erp_establishment_id' => $establishment, 'erp_product_variant_id' => $variant, 'direction' => $direction, 'quantity' => $quantity, 'valuation_method' => $method, 'currency' => $currency, 'source_currency' => $source['source_currency'] ?? $currency, ...array_intersect_key($source, array_flip(['source_type', 'source_id', 'source_line_id', 'posted_by_user_id', 'reversal_of_id', 'reason'])), 'source_operation_key' => $key, 'posted_at' => now()]);
    }

    private function lot(ErpStockMovement $movement, string $quantity, ?string $cost, ?string $currency, ?ErpStockLot $parent = null, ?int $allocation = null, ?int $settlement = null): ErpStockLot
    {
        return ErpStockLot::query()->create(['erp_company_id' => $movement->erp_company_id, 'erp_establishment_id' => $movement->erp_establishment_id, 'erp_product_variant_id' => $movement->erp_product_variant_id, 'erp_stock_movement_id' => $movement->id, 'root_lot_id' => $parent?->root_lot_id ?? $parent?->id, 'parent_lot_id' => $parent?->id, 'parent_allocation_id' => $allocation, 'parent_settlement_id' => $settlement, 'quantity' => $quantity, 'available_quantity' => $quantity, 'total_cost' => $cost, 'currency' => $currency, 'cost_status' => $cost === null ? 'unvalued' : 'known', 'received_at' => $parent?->received_at ?? now()]);
    }

    private function consume(ErpStockLot $lot, string $quantity): ?string
    {
        $cumulative = bcadd((string) $lot->consumed_quantity, $quantity, 4);
        $cost = $lot->total_cost === null ? null : ErpStockDecimal::slice((string) $lot->total_cost, (string) $lot->quantity, $cumulative, (string) $lot->consumed_cost);
        $lot->consumed_quantity = $cumulative;
        $lot->consumed_cost = bcadd((string) $lot->consumed_cost, $cost ?? '0', 4);
        $lot->available_quantity = bcsub((string) $lot->available_quantity, $quantity, 4);
        $lot->save();

        return $cost;
    }

    private function allocation(ErpStockMovement $movement, ?ErpStockLot $lot, string $quantity, ?string $cost, int $ordinal, string $kind = 'issue', ?int $original = null, ?string $currency = null): ErpStockAllocation
    {
        return ErpStockAllocation::query()->create(['erp_company_id' => $movement->erp_company_id, 'erp_establishment_id' => $movement->erp_establishment_id, 'erp_product_variant_id' => $movement->erp_product_variant_id, 'erp_stock_movement_id' => $movement->id, 'erp_stock_lot_id' => $lot?->id, 'original_allocation_id' => $original, 'ordinal' => $ordinal, 'kind' => $kind, 'quantity' => $quantity, 'cost' => $cost, 'currency' => $lot?->currency ?? $currency, 'cost_status' => $cost === null ? ($lot?->cost_status ?? 'unvalued') : 'known', 'issued_at' => now()]);
    }

    /** @param array<string,mixed> $source */
    private function existing(int $company, int $establishment, int $variant, string $direction, string $quantity, array $source, string $operation): ?ErpStockMovement
    {
        $existing = ErpStockMovement::query()->where('erp_company_id', $company)->where('source_type', $source['source_type'])->where('source_id', $source['source_id'])->where('source_operation_key', $operation)->first();
        if ($existing !== null) {
            abort_unless($existing->erp_establishment_id === $establishment && $existing->erp_product_variant_id === $variant && $existing->direction === $direction && bccomp((string) $existing->quantity, $quantity, 4) === 0, 409, 'Chave de estoque reutilizada com conteúdo diferente.');
            foreach (['reversal_of_id', 'reason', 'source_line_id', 'posted_by_user_id'] as $field) {
                abort_unless($existing->{$field} === ($source[$field] ?? null), 409, 'Referência de estoque divergente.');
            }
        }

        return $existing;
    }

    private function summarize(ErpStockMovement $movement): void
    {
        $allocations = ErpStockAllocation::query()->where('erp_stock_movement_id', $movement->id)->orderBy('ordinal')->get();
        $parts = $movement->direction === 'in'
            ? ErpStockLot::query()->where('erp_stock_movement_id', $movement->id)->get()->map(fn ($lot): array => ['quantity' => $lot->quantity, 'cost' => $lot->total_cost, 'currency' => $lot->currency])
            : $allocations->map(fn ($row): array => ['quantity' => $row->quantity, 'cost' => $row->cost, 'currency' => $row->currency]);
        $breakdown = [];
        $valuedCurrencies = [];
        $physical = $unknown = '0.0000';
        foreach ($parts as $part) {
            $key = $part['currency'] ?? '';
            $breakdown[$key] ??= ['currency' => $part['currency'], 'known_cost' => '0.0000', 'unvalued_quantity' => '0.0000'];
            $physical = bcadd($physical, $part['quantity'], 4);
            if ($part['cost'] === null) {
                $breakdown[$key]['unvalued_quantity'] = bcadd($breakdown[$key]['unvalued_quantity'], $part['quantity'], 4);
                $unknown = bcadd($unknown, $part['quantity'], 4);
            } else {
                $breakdown[$key]['known_cost'] = bcadd($breakdown[$key]['known_cost'], $part['cost'], 4);
                $valuedCurrencies[$key] = true;
            }
        }
        $missing = bcsub((string) $movement->quantity, $physical, 4);
        if (bccomp($missing, '0', 4) > 0) {
            $breakdown[''] ??= ['currency' => null, 'known_cost' => '0.0000', 'unvalued_quantity' => '0.0000'];
            $breakdown['']['unvalued_quantity'] = bcadd($breakdown['']['unvalued_quantity'], $missing, 4);
            $unknown = bcadd($unknown, $missing, 4);
        }
        ksort($breakdown, SORT_STRING);
        $proved = count($valuedCurrencies) === 1 && ! isset($valuedCurrencies['']);
        $mixed = count($valuedCurrencies) > 1;
        $known = $proved ? $breakdown[(string) array_key_first($valuedCurrencies)]['known_cost'] : null;
        $snapshot = $allocations->map(fn (ErpStockAllocation $row): array => ['id' => $row->id, 'lot_id' => $row->erp_stock_lot_id, 'quantity' => $row->quantity, 'cost' => $row->cost, 'currency' => $row->currency, 'cost_status' => $row->cost_status, 'unvalued_quantity' => $row->cost === null ? $row->quantity : '0.0000', 'original_allocation_id' => $row->original_allocation_id])->all();
        $movement->forceFill(['currency' => $proved ? (string) array_key_first($valuedCurrencies) : null, 'total_cost' => $proved && bccomp($unknown, '0', 4) === 0 ? $known : null, 'known_cost' => $proved ? $known : null, 'unvalued_quantity' => $unknown, 'cost_status' => $mixed ? 'mixed_currency' : ($proved && bccomp($unknown, '0', 4) === 0 ? 'known' : 'unvalued'), 'unit_cost' => $proved && bccomp($unknown, '0', 4) === 0 ? bcdiv($known, (string) $movement->quantity, 4) : null, 'allocation_snapshot' => $snapshot, 'cost_breakdown' => array_values($breakdown)])->save();
    }

    private function displayAverage(ErpStockBalance $balance): void
    {
        $lots = $this->position(ErpStockLot::query(), $balance->erp_company_id, $balance->erp_establishment_id, $balance->erp_product_variant_id)->where('available_quantity', '>', 0)->get();
        $known = '0.0000';
        $unknown = false;
        $currencies = [];
        $costs = [];
        foreach ($lots as $lot) {
            $unknown = $unknown || $lot->total_cost === null || $lot->currency === null;
            $currencies[$lot->currency ?? ''] = true;
            if ($lot->total_cost !== null) {
                $key = $lot->currency ?? '';
                $costs[$key] = bcadd($costs[$key] ?? '0.0000', bcsub((string) $lot->total_cost, (string) $lot->consumed_cost, 4), 4);
            }
        }
        $balance->average_cost = ! $unknown && count($currencies) === 1 && bccomp((string) $balance->quantity_on_hand, '0', 4) > 0 ? bcdiv($costs[(string) array_key_first($currencies)], (string) $balance->quantity_on_hand, 4) : null;
        $balance->save();
    }
}
