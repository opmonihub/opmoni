<?php

namespace App\Erp\Reports;

use App\Erp\ErpCatalogScope;
use App\Erp\Finance\ErpFinanceReadService;
use App\Models\Erp\Finance\ErpCashMovement;
use App\Models\Erp\Finance\ErpObligation;
use App\Models\Erp\Finance\ErpPayment;
use App\Models\Erp\Finance\ErpPaymentAllocation;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\Erp\Sales\ErpSale;
use App\Models\Erp\Sales\ErpSaleReturn;
use App\Models\Erp\Stock\ErpPurchase;
use App\Models\Erp\Stock\ErpPurchaseReturn;
use App\Models\Erp\Stock\ErpStockAllocation;
use App\Models\Erp\Stock\ErpStockBalance;
use App\Models\Erp\Stock\ErpStockCostSettlement;
use App\Models\Erp\Stock\ErpStockLot;
use App\Models\Erp\Stock\ErpStockMovement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class ErpLedgerReportService
{
    private Carbon $generatedAt;

    /** @var array<int, int> */
    private array $paymentReferences = [];

    /** @var list<string> */
    public const CATEGORIES = ['sales', 'purchases', 'stock', 'financial', 'fiscal', 'overview'];

    public function __construct(private ErpCatalogScope $scope) {}

    /**
     * @param  array{date_from?: ?string, date_to?: ?string, erp_establishment_id?: ?int, currency?: string, status?: string, direction?: string}  $filters
     * @param  list<int>|null  $allowedEstablishmentIds
     * @return array<string, mixed>
     */
    public function report(int $companyId, string $category, array $filters, ?array $allowedEstablishmentIds, bool $includeCompanywideFinance, Request $request): array
    {
        if (! in_array($category, self::CATEGORIES, true)) {
            throw new InvalidArgumentException("Categoria de relatório inválida: {$category}");
        }

        $this->generatedAt = now();
        $this->paymentReferences = [];
        $normalized = $this->normalizeFilters($companyId, $filters, $category, $allowedEstablishmentIds, $includeCompanywideFinance);

        $report = match ($category) {
            'sales' => $this->sales($companyId, $normalized, $allowedEstablishmentIds),
            'purchases' => $this->purchases($companyId, $normalized, $allowedEstablishmentIds),
            'stock' => $this->stock($companyId, $normalized, $allowedEstablishmentIds),
            'financial' => $this->financial($companyId, $normalized, $allowedEstablishmentIds, $includeCompanywideFinance, $request),
            'fiscal' => $this->fiscal($companyId, $normalized, $allowedEstablishmentIds),
            'overview' => $this->overview($companyId, $normalized, $allowedEstablishmentIds, $includeCompanywideFinance, $request),
        };

        return $report + ['generated_at' => $this->generatedAt->toIso8601String(), 'historical_position' => 'unavailable'];
    }

    /** @return list<int> Payment dependencies include full-set totals beyond bounded detail. */
    public function paymentReferences(): array
    {
        return array_values($this->paymentReferences);
    }

    /**
     * @param  array{date_from?: ?string, date_to?: ?string, erp_establishment_id?: ?int, currency?: string, status?: string, direction?: string}  $filters
     * @param  list<int>|null  $allowedEstablishmentIds
     * @return array{date_from: string, date_to: string, erp_establishment_id: ?int, currency?: string, status?: string, direction?: string, authorized_establishment_scope: string, authorized_establishment_ids: ?list<int>}
     */
    public function normalizeFilters(int $companyId, array $filters, ?string $category, ?array $allowedEstablishmentIds, bool $includeCompanywideFinance): array
    {
        $establishmentId = isset($filters['erp_establishment_id']) ? (int) $filters['erp_establishment_id'] : null;
        if ($establishmentId !== null) {
            $this->scope->assertEstablishmentBelongsToCompany($companyId, $establishmentId);
            if ($allowedEstablishmentIds !== null && ! in_array($establishmentId, $allowedEstablishmentIds, true)) {
                throw new InvalidArgumentException('Estabelecimento não autorizado para esta associação');
            }
        } else {
            $establishmentId = null;
        }
        $effectiveEstablishmentIds = $establishmentId !== null
            ? [$establishmentId]
            : $allowedEstablishmentIds;
        $includesCompanywideFinance = $includeCompanywideFinance
            && in_array($category, ['financial', 'overview'], true)
            && $establishmentId === null;

        $dateFrom = isset($filters['date_from']) ? Carbon::parse((string) $filters['date_from'])->startOfDay() : now()->startOfMonth();
        $dateTo = isset($filters['date_to']) ? Carbon::parse((string) $filters['date_to'])->endOfDay() : now()->endOfDay();
        if ($dateFrom->greaterThan($dateTo)) {
            throw new InvalidArgumentException('date_from não pode ser posterior a date_to');
        }

        $currency = $filters['currency'] ?? 'BRL';
        if (! is_string($currency) || ! preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new InvalidArgumentException('Moeda inválida: use um código de três letras maiúsculas');
        }
        $status = $filters['status'] ?? null;
        $direction = $filters['direction'] ?? null;
        $allowedStatuses = match ($category) {
            'sales', 'purchases' => ['posted'],
            'financial' => ['open', 'settled', 'reversed'],
            'fiscal' => ['draft', 'queued', 'submitting', 'authorized', 'rejected', 'indeterminate', 'canceled', 'contingency_local'],
            default => [],
        };
        if ($status !== null && ! in_array($status, $allowedStatuses, true)) {
            throw new InvalidArgumentException('Status inválido para este relatório');
        }
        if ($direction !== null && ($category !== 'financial' || ! in_array($direction, ['receivable', 'payable'], true))) {
            throw new InvalidArgumentException('Direção inválida para este relatório');
        }
        if ($category === 'stock' && isset($filters['currency'])) {
            throw new InvalidArgumentException('O saldo de estoque não possui atribuição de moeda');
        }
        if ($category === 'fiscal' && isset($filters['currency'])) {
            throw new InvalidArgumentException('Contagens fiscais não usam filtro de moeda');
        }

        return [
            ...(! in_array($category, ['stock', 'fiscal'], true) ? ['currency' => $currency] : []),
            ...($status !== null ? ['status' => $status] : []),
            ...($direction !== null ? ['direction' => $direction] : []),
            'date_from' => $dateFrom->toIso8601String(),
            'date_to' => $dateTo->toIso8601String(),
            'erp_establishment_id' => $establishmentId,
            'authorized_establishment_scope' => $effectiveEstablishmentIds === null ? 'all' : 'selected',
            'authorized_establishment_ids' => $effectiveEstablishmentIds,
            'authorized_companywide_finance' => $includesCompanywideFinance,
        ];
    }

    /**
     * @param  array{date_from: string, date_to: string, erp_establishment_id: ?int, currency?: string, status?: string, direction?: string, authorized_establishment_scope: string, authorized_establishment_ids: ?list<int>}  $filters
     * @param  list<int>|null  $allowedEstablishmentIds
     * @return array<string, mixed>
     */
    private function sales(int $companyId, array $filters, ?array $allowedEstablishmentIds): array
    {
        return $this->commercial($companyId, 'sales', $filters, $allowedEstablishmentIds);
    }

    /**
     * @param  array{date_from: string, date_to: string, erp_establishment_id: ?int, currency?: string, status?: string, direction?: string, authorized_establishment_scope: string, authorized_establishment_ids: ?list<int>}  $filters
     * @param  list<int>|null  $allowedEstablishmentIds
     * @return array<string, mixed>
     */
    private function purchases(int $companyId, array $filters, ?array $allowedEstablishmentIds): array
    {
        return $this->commercial($companyId, 'purchases', $filters, $allowedEstablishmentIds);
    }

    private function commercial(int $companyId, string $category, array $filters, ?array $allowed): array
    {
        $sales = $category === 'sales';
        $class = $sales ? ErpSale::class : ErpPurchase::class;
        $returnsClass = $sales ? ErpSaleReturn::class : ErpPurchaseReturn::class;
        $sourceField = $sales ? 'erp_sale_id' : 'erp_purchase_id';
        $sourceType = $sales ? 'erp_sale' : 'erp_purchase';
        $documents = $this->sourceQuery($class, $companyId, $filters, $allowed)->where('status', 'posted')->where('currency', $filters['currency']);
        $returns = $this->sourceQuery($returnsClass, $companyId, $filters, $allowed)->where('status', 'posted')->where('currency', $filters['currency']);
        $originals = $this->readLedger($documents, ['id', 'erp_establishment_id', $sales ? 'invoice_no' : 'document_no', 'currency', 'status', 'total_amount', 'posted_at'],
            $filters, 'posted_activity', 'posted_at', fn (Model $row): array => $row->attributesToArray(), ['amount' => '0.0000'],
            fn (array $totals, array $row): array => ['amount' => bcadd($totals['amount'], $row['total_amount'], 4)]);
        $compensations = $this->readLedger($returns, ['id', 'erp_establishment_id', $sourceField, 'currency', 'status', 'total_amount', 'posted_at'],
            $filters, 'posted_activity', 'posted_at', fn (Model $row): array => $row->attributesToArray() + ['source_type' => $sourceType, 'source_id' => $row[$sourceField]],
            ['amount' => '0.0000'], fn (array $totals, array $row): array => ['amount' => bcadd($totals['amount'], $row['total_amount'], 4)]);
        $obligations = $this->sourceQuery(ErpObligation::class, $companyId, $filters, $allowed, false, false)->where('currency', $filters['currency'])
            ->whereIn('source_type', [$sourceType, $sourceType.'_return']);
        $position = $this->obligations($obligations, array_diff_key($filters, array_flip(['status', 'direction'])));

        return ['category' => $category, 'filters' => $filters,
            'totals' => ['document_count' => $originals['row_count'], 'posted_total' => $originals['totals']['amount']],
            'ledgers' => ['documents' => $originals + ['status_filter' => 'posted'], 'returns' => $compensations + ['status_filter' => 'posted'], 'obligations' => $position]];
    }

    /**
     * @param  array{date_from: string, date_to: string, erp_establishment_id: ?int, currency?: string, status?: string, direction?: string, authorized_establishment_scope: string, authorized_establishment_ids: ?list<int>}  $filters
     * @param  list<int>|null  $allowedEstablishmentIds
     * @return array<string, mixed>
     */
    private function stock(int $companyId, array $filters, ?array $allowedEstablishmentIds): array
    {
        $filters = array_diff_key($filters, array_flip(['currency', 'status', 'direction']));
        $balances = $this->readLedger($this->sourceQuery(ErpStockBalance::class, $companyId, $filters, $allowedEstablishmentIds, false, false),
            ['id', 'erp_establishment_id', 'erp_product_variant_id', 'quantity_on_hand', 'ledger_initialized_at', 'updated_at'],
            $filters, 'current_position', 'updated_at', fn (Model $row): array => $row->attributesToArray(), ['quantity_on_hand' => '0.0000'],
            fn (array $totals, array $row): array => ['quantity_on_hand' => bcadd($totals['quantity_on_hand'], $row['quantity_on_hand'], 4)]);
        $lots = $this->readLedger($this->sourceQuery(ErpStockLot::class, $companyId, $filters, $allowedEstablishmentIds, false, false)->where('available_quantity', '>', 0),
            ['id', 'erp_establishment_id', 'erp_product_variant_id', 'erp_stock_movement_id', 'opening_balance_id', 'root_lot_id', 'parent_lot_id', 'parent_allocation_id', 'parent_settlement_id', 'quantity', 'available_quantity', 'total_cost', 'consumed_cost', 'currency', 'cost_status', 'received_at'],
            $filters, 'current_position', 'received_at', function (Model $row): array {
                $known = $row->total_cost !== null && $row->currency !== null;

                return $row->attributesToArray() + ['remaining_cost' => $known ? bcsub($row->total_cost, $row->consumed_cost, 4) : null,
                    'unvalued_quantity' => $known ? '0.0000' : $row->available_quantity];
            }, ['known_cost_by_currency' => [], 'unvalued_quantity' => '0.0000', 'quantity' => '0.0000'],
            function (array $totals, array $row): array {
                $totals['quantity'] = bcadd($totals['quantity'], $row['available_quantity'], 4);
                $totals['unvalued_quantity'] = bcadd($totals['unvalued_quantity'], $row['unvalued_quantity'], 4);
                if ($row['remaining_cost'] !== null) {
                    $currency = $row['currency'];
                    $totals['known_cost_by_currency'][$currency] = bcadd($totals['known_cost_by_currency'][$currency] ?? '0', $row['remaining_cost'], 4);
                }

                return $totals;
            });
        $movements = $this->readLedger($this->sourceQuery(ErpStockMovement::class, $companyId, $filters, $allowedEstablishmentIds),
            ['id', 'erp_establishment_id', 'erp_product_variant_id', 'direction', 'quantity', 'currency', 'total_cost', 'known_cost', 'unvalued_quantity', 'cost_status', 'cost_breakdown', 'source_type', 'source_id', 'source_line_id', 'reversal_of_id', 'posted_at'],
            $filters, 'posted_activity', 'posted_at', function (Model $row): array {
                $parts = [];
                foreach ($row->cost_breakdown ?? [] as $part) {
                    $parts[] = array_intersect_key($part, array_flip(['currency', 'known_cost', 'unvalued_quantity']));
                }
                if ($parts === []) {
                    $parts[] = ['currency' => $row->currency, 'known_cost' => $row->known_cost ?? $row->total_cost,
                        'unvalued_quantity' => $row->currency === null ? $row->quantity : ($row->unvalued_quantity ?? ($row->total_cost === null ? $row->quantity : '0.0000'))];
                }

                return array_replace($row->attributesToArray(), ['cost_breakdown' => $parts]);
            }, ['quantity_in' => '0.0000', 'quantity_out' => '0.0000', 'net_quantity' => '0.0000', 'known_cost_by_currency' => [], 'unvalued_quantity' => '0.0000'],
            function (array $totals, array $row): array {
                $key = $row['direction'] === 'in' ? 'quantity_in' : 'quantity_out';
                $totals[$key] = bcadd($totals[$key], $row['quantity'], 4);
                $totals['net_quantity'] = bcsub($totals['quantity_in'], $totals['quantity_out'], 4);
                foreach ($row['cost_breakdown'] as $part) {
                    if ($part['currency'] !== null && $part['known_cost'] !== null) {
                        $currency = $part['currency'];
                        $signed = $row['direction'] === 'in' ? $part['known_cost'] : bcsub('0', $part['known_cost'], 4);
                        $totals['known_cost_by_currency'][$currency] = bcadd($totals['known_cost_by_currency'][$currency] ?? '0', $signed, 4);
                    }
                    $totals['unvalued_quantity'] = bcadd($totals['unvalued_quantity'], $part['unvalued_quantity'] ?? '0', 4);
                }

                return $totals;
            });
        $allocations = $this->readLedger($this->sourceQuery(ErpStockAllocation::class, $companyId, $filters, $allowedEstablishmentIds, false, false),
            ['id', 'erp_establishment_id', 'erp_product_variant_id', 'erp_stock_movement_id', 'erp_stock_lot_id', 'original_allocation_id', 'kind', 'quantity', 'cost', 'returned_quantity', 'returned_cost', 'settled_quantity', 'currency', 'cost_status', 'issued_at'],
            $filters, 'current_position', 'issued_at', fn (Model $row): array => $row->attributesToArray() + [
                'remaining_quantity' => $row->kind === 'issue' ? bcsub($row->quantity, $row->returned_quantity, 4) : null,
                'remaining_cost' => $row->kind !== 'issue' || $row->cost === null ? null : bcsub($row->cost, $row->returned_cost, 4),
            ], ['quantity' => '0.0000', 'return_compensation_quantity' => '0.0000'],
            function (array $totals, array $row): array {
                $key = $row['kind'] === 'issue' ? 'quantity' : 'return_compensation_quantity';
                $totals[$key] = bcadd($totals[$key], $row['kind'] === 'issue' ? $row['remaining_quantity'] : $row['quantity'], 4);

                return $totals;
            });
        $settlements = $this->readLedger($this->sourceQuery(ErpStockCostSettlement::class, $companyId, $filters, $allowedEstablishmentIds),
            ['id', 'erp_establishment_id', 'erp_product_variant_id', 'erp_stock_allocation_id', 'erp_stock_lot_id', 'erp_stock_movement_id', 'reversal_of_id', 'quantity', 'cost', 'currency', 'posted_at'],
            $filters, 'posted_cost_settlement', 'posted_at', fn (Model $row): array => $row->attributesToArray(), ['known_cost_by_currency' => []],
            function (array $totals, array $row): array {
                if ($row['currency'] !== null && $row['cost'] !== null) {
                    $currency = $row['currency'];
                    $signed = $row['reversal_of_id'] === null ? $row['cost'] : bcsub('0', $row['cost'], 4);
                    $totals['known_cost_by_currency'][$currency] = bcadd($totals['known_cost_by_currency'][$currency] ?? '0', $signed, 4);
                }

                return $totals;
            });

        return ['category' => 'stock', 'filters' => $filters,
            'totals' => ['balance_lines' => $balances['row_count'], 'quantity_on_hand' => $balances['totals']['quantity_on_hand'], 'inventory_value' => null],
            'ledgers' => ['balances' => $balances, 'lots' => $lots, 'allocations' => $allocations, 'movements' => $movements, 'cost_settlements' => $settlements]];
    }

    /**
     * @param  array{date_from: string, date_to: string, erp_establishment_id: ?int, currency?: string, status?: string, direction?: string, authorized_establishment_scope: string, authorized_establishment_ids: ?list<int>}  $filters
     * @param  list<int>|null  $allowedEstablishmentIds
     * @return array<string, mixed>
     */
    private function financial(int $companyId, array $filters, ?array $allowedEstablishmentIds, bool $includeCompanywideFinance, Request $request): array
    {
        $obligationQuery = $this->sourceQuery(ErpObligation::class, $companyId, $filters, $allowedEstablishmentIds, $includeCompanywideFinance, false)
            ->where('currency', $filters['currency']);
        if (isset($filters['status'])) {
            $obligationQuery->where('status', $filters['status']);
        }
        if (isset($filters['direction'])) {
            $obligationQuery->where('direction', $filters['direction']);
        }

        $position = $this->obligations(clone $obligationQuery, $filters);
        $activity = $this->obligations($obligationQuery->whereBetween('posted_at', [$filters['date_from'], $filters['date_to']]), $filters, false);
        $visiblePayments = ErpPayment::query()->select('erp_payments.id')->where('erp_company_id', $companyId);
        app(ErpFinanceReadService::class)->scopePayments($request, $visiblePayments);
        $paymentQuery = $this->sourceQuery(ErpPayment::class, $companyId, $filters, $allowedEstablishmentIds, $includeCompanywideFinance)
            ->whereIn('id', clone $visiblePayments)
            ->where('status', 'posted')->where('currency', $filters['currency']);
        $payments = $this->readLedger($paymentQuery, ['id', 'erp_establishment_id', 'currency', 'status', 'direction', 'amount', 'method', 'source_type', 'source_id', 'reversal_of_id', 'posted_at'],
            $filters, 'posted_activity', 'posted_at', fn (Model $row): array => $row->attributesToArray(), ['amount' => '0.0000', 'net_amount' => '0.0000', 'known_direction_net_amount' => '0.0000', 'unclassified_amount' => '0.0000', 'unclassified_count' => 0, 'by_direction' => []],
            function (array $totals, array $row): array {
                $totals['amount'] = bcadd($totals['amount'], $row['amount'], 4);
                $direction = $row['direction'] ?? 'unrecorded';
                $totals['by_direction'][$direction] = bcadd($totals['by_direction'][$direction] ?? '0', $row['amount'], 4);
                if ($direction !== 'unrecorded') {
                    $totals['known_direction_net_amount'] = bcadd($totals['known_direction_net_amount'], $direction === 'in' ? $row['amount'] : bcsub('0', $row['amount'], 4), 4);
                } else {
                    $totals['unclassified_amount'] = bcadd($totals['unclassified_amount'], $row['amount'], 4);
                    $totals['unclassified_count']++;
                }
                $totals['net_amount'] = $totals['unclassified_count'] === 0 ? $totals['known_direction_net_amount'] : null;

                return $totals;
            });
        $allocationQuery = ErpPaymentAllocation::query()->whereBetween('posted_at', [$filters['date_from'], $filters['date_to']])
            ->whereIn('erp_payment_id', $this->sourceQuery(ErpPayment::class, $companyId, $filters, $allowedEstablishmentIds, $includeCompanywideFinance, false)->whereIn('id', clone $visiblePayments)->where('status', 'posted')->where('currency', $filters['currency'])->select('id'))
            ->whereIn('erp_obligation_id', $this->sourceQuery(ErpObligation::class, $companyId, $filters, $allowedEstablishmentIds, $includeCompanywideFinance, false)->where('currency', $filters['currency'])->select('id'));
        $allocations = $this->readLedger($allocationQuery, ['id', 'erp_payment_id', 'erp_obligation_id', 'amount', 'reversal_of_id', 'posted_at'],
            $filters, 'posted_activity', 'posted_at', fn (Model $row): array => $row->attributesToArray(), ['amount' => '0.0000'],
            fn (array $totals, array $row): array => ['amount' => bcadd($totals['amount'], $row['amount'], 4)]);
        $cash = $this->readLedger($this->sourceQuery(ErpCashMovement::class, $companyId, $filters, $allowedEstablishmentIds)->where('currency', $filters['currency'])
            ->where(fn (Builder $query) => $query->whereNull('erp_payment_id')->orWhereIn('erp_payment_id', clone $visiblePayments)),
            ['id', 'erp_establishment_id', 'erp_cash_register_id', 'erp_cash_session_id', 'currency', 'direction', 'kind', 'amount', 'erp_payment_id', 'reversal_of_id', 'posted_at'],
            $filters, 'posted_activity', 'posted_at', fn (Model $row): array => $row->attributesToArray(), ['net_amount' => '0.0000', 'by_group' => []],
            function (array $totals, array $row): array {
                $signed = $row['direction'] === 'in' ? $row['amount'] : bcsub('0', $row['amount'], 4);
                $totals['net_amount'] = bcadd($totals['net_amount'], $signed, 4);
                $group = implode(':', [$row['currency'], $row['erp_establishment_id'], $row['erp_cash_register_id'], $row['erp_cash_session_id'], $row['direction'], $row['kind']]);
                $totals['by_group'][$group] = bcadd($totals['by_group'][$group] ?? '0', $signed, 4);

                return $totals;
            });

        return [
            'category' => 'financial',
            'filters' => $filters,
            'totals' => [
                'obligations_amount' => $position['totals']['amount'],
                'obligations_open_amount' => $position['totals']['open_amount'],
                ...(! isset($filters['direction']) && ! isset($filters['status']) ? ['payments_posted_total' => $payments['totals']['amount']] : []),
            ],
            'ledgers' => ['obligations' => $position, 'obligation_activity' => $activity,
                'payments' => $payments + ['status_filter' => 'posted', 'direction_filter' => null],
                'allocations' => $allocations + ['status_filter' => null, 'direction_filter' => null],
                'cash' => $cash + ['status_filter' => null, 'direction_filter' => null]],
        ];
    }

    private function obligations(Builder $query, array $filters, bool $current = true): array
    {
        $ledger = $this->readLedger($query,
            ['id', 'erp_establishment_id', 'currency', 'direction', 'status', 'amount', 'open_amount', 'due_on', 'return_of_id', 'reversal_of_id', 'source_type', 'source_id', 'posted_at'],
            $filters, $current ? 'current_position' : 'posted_activity', 'posted_at',
            function (Model $row) use ($current): array {
                if (! $current) {
                    return $row->only(['id', 'erp_establishment_id', 'currency', 'direction', 'status', 'amount', 'return_of_id', 'reversal_of_id', 'source_type', 'source_id', 'posted_at']);
                }
                $days = $row->due_on === null ? null : max(0, (int) $row->due_on->startOfDay()->diffInDays($this->generatedAt->copy()->startOfDay(), false));

                return $row->attributesToArray() + [
                    'aging_bucket' => $current ? ($days === null ? 'undated' : ($days > 0 ? 'overdue' : 'not_yet_due')) : null,
                    'days_overdue' => $current ? $days : null, 'needs_reconciliation' => $row->posted_at === null,
                    'signed_open_amount' => $row->direction === 'receivable' ? $row->open_amount : bcsub('0', $row->open_amount, 4),
                ];
            }, $current
                ? ['amount' => '0.0000', 'open_amount' => '0.0000', 'gross_original_due' => '0.0000', 'opposite_return_credit' => '0.0000', 'open_return_credit' => '0.0000', 'net_exposure' => '0.0000',
                    'overdue' => '0.0000', 'not_yet_due' => '0.0000', 'undated' => '0.0000', 'by_direction_status' => []]
                : ['amount' => '0.0000', 'original_principal' => '0.0000', 'opposite_return_credit' => '0.0000', 'net_posted_amount' => '0.0000'],
            function (array $totals, array $row) use ($current): array {
                $totals['amount'] = bcadd($totals['amount'], $row['amount'], 4);
                if (! $current) {
                    $key = $row['return_of_id'] === null ? 'original_principal' : 'opposite_return_credit';
                    $totals[$key] = bcadd($totals[$key], $row['amount'], 4);
                    $totals['net_posted_amount'] = bcadd($totals['net_posted_amount'], $row['direction'] === 'receivable' ? $row['amount'] : bcsub('0', $row['amount'], 4), 4);

                    return $totals;
                }
                $totals['open_amount'] = bcadd($totals['open_amount'], $row['open_amount'], 4);
                $totals['net_exposure'] = bcadd($totals['net_exposure'], $row['signed_open_amount'], 4);
                if ($row['return_of_id'] === null) {
                    $totals['gross_original_due'] = bcadd($totals['gross_original_due'], $row['open_amount'], 4);
                } else {
                    $totals['opposite_return_credit'] = bcadd($totals['opposite_return_credit'], $row['amount'], 4);
                    $totals['open_return_credit'] = bcadd($totals['open_return_credit'], $row['open_amount'], 4);
                }
                if ($current) {
                    $bucket = $row['aging_bucket'];
                    $totals[$bucket] = bcadd($totals[$bucket], $row['open_amount'], 4);
                }
                $group = $row['direction'].':'.$row['status'];
                $totals['by_direction_status'][$group] = bcadd($totals['by_direction_status'][$group] ?? '0', $row['open_amount'], 4);

                return $totals;
            });

        return $ledger + ['status_filter' => $filters['status'] ?? null, 'status_basis' => 'current_at_generation', 'status_observed_at' => $this->generatedAt->toIso8601String(),
            'direction_filter' => $filters['direction'] ?? null, 'automatic_setoff' => false, 'automatic_refund' => false];
    }

    private function sourceQuery(string $class, int $companyId, array $filters, ?array $allowed, bool $companywide = false, bool $activity = true): Builder
    {
        $query = $class::query()->where('erp_company_id', $companyId);
        if ($activity) {
            $query->whereBetween('posted_at', [$filters['date_from'], $filters['date_to']]);
        }
        $this->applyFinancialEstablishmentFilter($query, $filters, $allowed, $companywide);

        return $query;
    }

    /** Detail is a bounded prefix; totals consume the entire same cursor, never a second query. */
    private function readLedger(Builder $query, array $columns, array $filters, string $basis, string $timestamp, callable $project, array $totals, callable $aggregate): array
    {
        $rows = [];
        $count = 0;
        foreach ($query->select($columns)->orderBy('id')->cursor() as $record) {
            $row = $project($record);
            $totals = $aggregate($totals, $row);
            $paymentId = $record instanceof ErpPayment ? $record->id
                : (($record instanceof ErpPaymentAllocation || $record instanceof ErpCashMovement) ? $record->erp_payment_id : null);
            if ($paymentId !== null) {
                $this->paymentReferences[$paymentId] = $paymentId;
            }
            if ($count < 200) {
                $rows[] = $row;
            }
            $count++;
        }

        return ['basis' => $basis, 'position_at' => $basis === 'current_position' ? $this->generatedAt->toIso8601String() : null,
            'period' => $basis === 'current_position' ? null : ['date_from' => $filters['date_from'], 'date_to' => $filters['date_to']],
            'timestamp_field' => $timestamp, 'currency' => $filters['currency'] ?? null,
            'rows' => $rows, 'row_count' => $count, 'detail_limit' => 200, 'detail_truncated' => $count > 200, 'totals' => $totals];
    }

    /**
     * @param  array{date_from: string, date_to: string, erp_establishment_id: ?int, currency?: string, status?: string, direction?: string, authorized_establishment_scope: string, authorized_establishment_ids: ?list<int>}  $filters
     * @param  list<int>|null  $allowedEstablishmentIds
     * @return array<string, mixed>
     */
    private function fiscal(int $companyId, array $filters, ?array $allowedEstablishmentIds): array
    {
        $filters = array_diff_key($filters, array_flip(['currency', 'direction']));
        $query = ErpFiscalDocument::query()
            ->where('erp_company_id', $companyId)
            ->whereBetween('created_at', [$filters['date_from'], $filters['date_to']]);
        $this->applyEstablishmentFilter($query, $filters, $allowedEstablishmentIds);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $counts = array_fill_keys(array_map(fn (string $status): string => $status.'_count',
            ['authorized', 'draft', 'rejected', 'queued', 'submitting', 'indeterminate', 'canceled', 'contingency_local']), 0);
        $documents = $this->readLedger($query, ['id', 'erp_establishment_id', 'erp_sale_id', 'model', 'environment', 'series', 'number', 'status', 'created_at', 'authorized_at'],
            $filters, 'current_status_for_created_cohort', 'created_at', fn (Model $row): array => $row->attributesToArray(), $counts,
            function (array $totals, array $row): array {
                $key = $row['status'].'_count';
                if (array_key_exists($key, $totals)) {
                    $totals[$key]++;
                }

                return $totals;
            });

        return [
            'category' => 'fiscal',
            'filters' => $filters,
            'totals' => $documents['totals'],
            'ledgers' => ['documents' => $documents + ['status_filter' => $filters['status'] ?? null]],
        ];
    }

    /**
     * @param  array{date_from: string, date_to: string, erp_establishment_id: ?int, currency?: string, status?: string, direction?: string, authorized_establishment_scope: string, authorized_establishment_ids: ?list<int>}  $filters
     * @param  list<int>|null  $allowedEstablishmentIds
     * @return array<string, mixed>
     */
    private function overview(int $companyId, array $filters, ?array $allowedEstablishmentIds, bool $includeCompanywideFinance, Request $request): array
    {
        $reports = [
            'sales' => $this->sales($companyId, $filters, $allowedEstablishmentIds),
            'purchases' => $this->purchases($companyId, $filters, $allowedEstablishmentIds),
            'stock' => $this->stock($companyId, $filters, $allowedEstablishmentIds),
            'financial' => $this->financial($companyId, $filters, $allowedEstablishmentIds, $includeCompanywideFinance, $request),
            'fiscal' => $this->fiscal($companyId, $filters, $allowedEstablishmentIds),
        ];

        return [
            'category' => 'overview',
            'filters' => $filters,
            'sections' => [
                ...array_map(fn (array $report): array => $report['totals'], $reports),
            ],
            'ledgers' => array_map(fn (array $report): array => $report['ledgers'], $reports),
        ];
    }

    /**
     * @param  Builder<ErpSale|ErpPurchase|ErpStockBalance|ErpObligation|ErpPayment|ErpFiscalDocument>  $query
     * @param  array{erp_establishment_id: ?int}  $filters
     * @param  list<int>|null  $allowedEstablishmentIds
     */
    private function applyEstablishmentFilter(Builder $query, array $filters, ?array $allowedEstablishmentIds): void
    {
        if ($filters['erp_establishment_id'] !== null) {
            $query->where('erp_establishment_id', $filters['erp_establishment_id']);

            return;
        }
        if ($allowedEstablishmentIds !== null) {
            $query->whereIn('erp_establishment_id', $allowedEstablishmentIds);
        }
    }

    /**
     * @param  Builder<ErpObligation|ErpPayment>  $query
     * @param  array{erp_establishment_id: ?int}  $filters
     * @param  list<int>|null  $allowedEstablishmentIds
     */
    private function applyFinancialEstablishmentFilter(Builder $query, array $filters, ?array $allowedEstablishmentIds, bool $includeCompanywideFinance): void
    {
        if ($filters['erp_establishment_id'] !== null) {
            $query->where('erp_establishment_id', $filters['erp_establishment_id']);

            return;
        }
        if ($allowedEstablishmentIds !== null) {
            $query->whereIn('erp_establishment_id', $allowedEstablishmentIds);

            return;
        }
        if (! $includeCompanywideFinance) {
            $query->whereNotNull('erp_establishment_id');
        }
    }
}
