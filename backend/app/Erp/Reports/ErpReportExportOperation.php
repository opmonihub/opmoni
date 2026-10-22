<?php

namespace App\Erp\Reports;

use App\Erp\ErpEstablishmentAccess;
use App\Erp\ErpReleaseGate;
use App\Erp\Finance\ErpFinanceReadService;
use App\Models\Erp\Finance\ErpPayment;
use App\Models\Erp\Reports\ErpReportExport;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;

class ErpReportExportOperation
{
    public const NAME = 'erp.reports.exports.store';

    /** Defaults are evaluated only by publication, never again by identity/replay. */
    public function canonical(string $category, array $filters): array
    {
        return ['category' => $category, 'filters' => array_intersect_key($filters, array_flip([
            'date_from', 'date_to', 'erp_establishment_id', 'currency', 'status', 'direction',
        ]))];
    }

    public function resourceReferences(int $exportId): array
    {
        $export = ErpReportExport::query()->findOrFail($exportId);

        return ['export_id' => $exportId, ...(
            in_array($export->report_type, ['financial', 'overview'], true)
                ? ['payment_ids' => $export->filters['authorized_payment_ids'] ?? null]
                : []
        )];
    }

    public function paymentReferencesAllow(Request $request, ErpReportExport $export, array $references = []): bool
    {
        if (! in_array($export->report_type, ['financial', 'overview'], true)) {
            return true;
        }
        $ids = $export->filters['authorized_payment_ids'] ?? null;
        if (! is_array($ids) || (array_key_exists('payment_ids', $references) && $references['payment_ids'] !== $ids)) {
            return false;
        }
        foreach (array_chunk($ids, 500) as $batch) {
            $query = ErpPayment::query()->where('erp_company_id', CurrentCompany::fromRequest($request)->company->id)->whereKey($batch);
            app(ErpFinanceReadService::class)->scopePayments($request, $query);
            if ($query->count() !== count($batch)) {
                return false;
            }
        }

        return true;
    }

    /** Authorizes metadata only; artifact expiry/integrity remain owned by protected GET. */
    public function assertAuthorized(Request $request, array $references = [], bool $creating = false): void
    {
        $context = CurrentCompany::fromRequest($request);
        app(ErpReleaseGate::class)->assertReleased($context->company->id, $request->user()?->id);
        $context->assertPermission($creating ? self::NAME : 'erp.reports.exports.show', $creating);
        if (! isset($references['export_id'])) {
            return;
        }
        $export = ErpReportExport::query()->where('erp_company_id', $context->company->id)
            ->whereKey($references['export_id'])->firstOrFail();
        abort_unless($export->user_id === $request->user()?->id, 403);
        $access = app(ErpEstablishmentAccess::class);
        $ids = $access->allowedIds($context);
        $companywide = $access->allowsCompanywideNullableRecords($context);
        $scopeAllowed = $export->authorized_establishment_scope === 'selected'
            ? ($ids === null || array_diff($export->authorized_establishment_ids ?? [], $ids) === [])
            : $ids === null;
        abort_unless($scopeAllowed && ($export->authorized_companywide_finance === false || $companywide)
            && $this->paymentReferencesAllow($request, $export, $references), 409, 'Esta exportação precisa ser gerada novamente.');
    }
}
