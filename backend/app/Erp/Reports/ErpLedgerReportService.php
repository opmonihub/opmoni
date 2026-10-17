<?php

namespace App\Erp\Reports;

use App\Erp\ErpCatalogScope;
use App\Erp\Finance\ErpDecimal;
use App\Erp\Fiscal\ErpFiscalDocumentStateMachine;
use App\Models\Erp\Finance\ErpObligation;
use App\Models\Erp\Finance\ErpPayment;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\Erp\Sales\ErpSale;
use App\Models\Erp\Stock\ErpPurchase;
use App\Models\Erp\Stock\ErpStockBalance;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class ErpLedgerReportService
{
    /** @var list<string> */
    public const CATEGORIES = ['sales', 'purchases', 'stock', 'financial', 'fiscal', 'overview'];

    public function __construct(private ErpCatalogScope $scope) {}

    /**
     * @param  array{date_from?: ?string, date_to?: ?string, erp_establishment_id?: ?int}  $filters
     * @return array<string, mixed>
     */
    public function report(int $companyId, string $category, array $filters): array
    {
        if (! in_array($category, self::CATEGORIES, true)) {
            throw new InvalidArgumentException("Categoria de relatório inválida: {$category}");
        }

        $normalized = $this->normalizeFilters($companyId, $filters);

        return match ($category) {
            'sales' => $this->sales($companyId, $normalized),
            'purchases' => $this->purchases($companyId, $normalized),
            'stock' => $this->stock($companyId, $normalized),
            'financial' => $this->financial($companyId, $normalized),
            'fiscal' => $this->fiscal($companyId, $normalized),
            'overview' => $this->overview($companyId, $normalized),
        };
    }

    /**
     * @param  array{date_from?: ?string, date_to?: ?string, erp_establishment_id?: ?int}  $filters
     * @return array{date_from: string, date_to: string, erp_establishment_id: ?int}
     */
    public function normalizeFilters(int $companyId, array $filters): array
    {
        $establishmentId = isset($filters['erp_establishment_id']) ? (int) $filters['erp_establishment_id'] : null;
        if ($establishmentId !== null && $establishmentId !== 0) {
            $this->scope->assertEstablishmentBelongsToCompany($companyId, $establishmentId);
        } else {
            $establishmentId = null;
        }

        $dateFrom = isset($filters['date_from']) ? Carbon::parse((string) $filters['date_from'])->startOfDay() : now()->startOfMonth();
        $dateTo = isset($filters['date_to']) ? Carbon::parse((string) $filters['date_to'])->endOfDay() : now()->endOfDay();
        if ($dateFrom->greaterThan($dateTo)) {
            throw new InvalidArgumentException('date_from não pode ser posterior a date_to');
        }

        return [
            'date_from' => $dateFrom->toIso8601String(),
            'date_to' => $dateTo->toIso8601String(),
            'erp_establishment_id' => $establishmentId,
        ];
    }

    /**
     * @param  array{date_from: string, date_to: string, erp_establishment_id: ?int}  $filters
     * @return array<string, mixed>
     */
    private function sales(int $companyId, array $filters): array
    {
        $query = ErpSale::query()
            ->where('erp_company_id', $companyId)
            ->where('status', 'posted')
            ->whereBetween('posted_at', [$filters['date_from'], $filters['date_to']]);
        if ($filters['erp_establishment_id'] !== null) {
            $query->where('erp_establishment_id', $filters['erp_establishment_id']);
        }

        $total = ErpDecimal::zero();
        $count = 0;
        foreach ($query->cursor() as $sale) {
            $total = ErpDecimal::add($total, ErpDecimal::parse((string) $sale->total_amount));
            $count++;
        }

        return [
            'category' => 'sales',
            'filters' => $filters,
            'totals' => [
                'document_count' => $count,
                'posted_total' => $total,
            ],
        ];
    }

    /**
     * @param  array{date_from: string, date_to: string, erp_establishment_id: ?int}  $filters
     * @return array<string, mixed>
     */
    private function purchases(int $companyId, array $filters): array
    {
        $query = ErpPurchase::query()
            ->where('erp_company_id', $companyId)
            ->where('status', 'posted')
            ->whereBetween('posted_at', [$filters['date_from'], $filters['date_to']]);
        if ($filters['erp_establishment_id'] !== null) {
            $query->where('erp_establishment_id', $filters['erp_establishment_id']);
        }

        $total = ErpDecimal::zero();
        $count = 0;
        foreach ($query->cursor() as $purchase) {
            $total = ErpDecimal::add($total, ErpDecimal::parse((string) $purchase->total_amount));
            $count++;
        }

        return [
            'category' => 'purchases',
            'filters' => $filters,
            'totals' => [
                'document_count' => $count,
                'posted_total' => $total,
            ],
        ];
    }

    /**
     * @param  array{date_from: string, date_to: string, erp_establishment_id: ?int}  $filters
     * @return array<string, mixed>
     */
    private function stock(int $companyId, array $filters): array
    {
        $query = ErpStockBalance::query()->where('erp_company_id', $companyId);
        if ($filters['erp_establishment_id'] !== null) {
            $query->where('erp_establishment_id', $filters['erp_establishment_id']);
        }

        $quantity = ErpDecimal::zero();
        $inventoryValue = ErpDecimal::zero();
        $lines = 0;
        foreach ($query->cursor() as $balance) {
            $qty = ErpDecimal::parse((string) $balance->quantity_on_hand);
            $quantity = ErpDecimal::add($quantity, $qty);
            $inventoryValue = ErpDecimal::add(
                $inventoryValue,
                ErpDecimal::mul($qty, ErpDecimal::parse((string) $balance->average_cost)),
            );
            $lines++;
        }

        return [
            'category' => 'stock',
            'filters' => $filters,
            'totals' => [
                'balance_lines' => $lines,
                'quantity_on_hand' => $quantity,
                'inventory_value' => $inventoryValue,
            ],
        ];
    }

    /**
     * @param  array{date_from: string, date_to: string, erp_establishment_id: ?int}  $filters
     * @return array<string, mixed>
     */
    private function financial(int $companyId, array $filters): array
    {
        $obligationQuery = ErpObligation::query()
            ->where('erp_company_id', $companyId)
            ->whereBetween('created_at', [$filters['date_from'], $filters['date_to']]);
        $paymentQuery = ErpPayment::query()
            ->where('erp_company_id', $companyId)
            ->where('status', 'posted')
            ->whereBetween('posted_at', [$filters['date_from'], $filters['date_to']]);
        if ($filters['erp_establishment_id'] !== null) {
            $obligationQuery->where('erp_establishment_id', $filters['erp_establishment_id']);
            $paymentQuery->where('erp_establishment_id', $filters['erp_establishment_id']);
        }

        $obligationTotal = ErpDecimal::zero();
        $openTotal = ErpDecimal::zero();
        foreach ($obligationQuery->cursor() as $obligation) {
            $obligationTotal = ErpDecimal::add($obligationTotal, ErpDecimal::parse((string) $obligation->amount));
            $openTotal = ErpDecimal::add($openTotal, ErpDecimal::parse((string) $obligation->open_amount));
        }

        $paymentsTotal = ErpDecimal::zero();
        foreach ($paymentQuery->cursor() as $payment) {
            $paymentsTotal = ErpDecimal::add($paymentsTotal, ErpDecimal::parse((string) $payment->amount));
        }

        return [
            'category' => 'financial',
            'filters' => $filters,
            'totals' => [
                'obligations_amount' => $obligationTotal,
                'obligations_open_amount' => $openTotal,
                'payments_posted_total' => $paymentsTotal,
            ],
        ];
    }

    /**
     * @param  array{date_from: string, date_to: string, erp_establishment_id: ?int}  $filters
     * @return array<string, mixed>
     */
    private function fiscal(int $companyId, array $filters): array
    {
        $query = ErpFiscalDocument::query()
            ->where('erp_company_id', $companyId)
            ->whereBetween('authorized_at', [$filters['date_from'], $filters['date_to']]);
        if ($filters['erp_establishment_id'] !== null) {
            $query->where('erp_establishment_id', $filters['erp_establishment_id']);
        }

        $authorized = 0;
        $draft = 0;
        $rejected = 0;
        foreach ($query->cursor() as $document) {
            if ($document->status === ErpFiscalDocumentStateMachine::AUTHORIZED) {
                $authorized++;
            } elseif ($document->status === ErpFiscalDocumentStateMachine::DRAFT) {
                $draft++;
            } elseif ($document->status === ErpFiscalDocumentStateMachine::REJECTED) {
                $rejected++;
            }
        }

        return [
            'category' => 'fiscal',
            'filters' => $filters,
            'totals' => [
                'authorized_count' => $authorized,
                'draft_count' => $draft,
                'rejected_count' => $rejected,
            ],
        ];
    }

    /**
     * @param  array{date_from: string, date_to: string, erp_establishment_id: ?int}  $filters
     * @return array<string, mixed>
     */
    private function overview(int $companyId, array $filters): array
    {
        return [
            'category' => 'overview',
            'filters' => $filters,
            'sections' => [
                'sales' => $this->sales($companyId, $filters)['totals'],
                'purchases' => $this->purchases($companyId, $filters)['totals'],
                'stock' => $this->stock($companyId, $filters)['totals'],
                'financial' => $this->financial($companyId, $filters)['totals'],
                'fiscal' => $this->fiscal($companyId, $filters)['totals'],
            ],
        ];
    }
}
