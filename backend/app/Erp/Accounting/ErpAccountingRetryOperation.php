<?php

namespace App\Erp\Accounting;

use App\Enums\Erp\ErpEstablishmentResource;
use App\Erp\ErpEstablishmentAccess;
use App\Erp\ErpReleaseGate;
use App\Models\Erp\Accounting\ErpAccountingDelivery;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;

class ErpAccountingRetryOperation
{
    public const NAME = 'erp.accounting-deliveries.retry';

    public function canonical(Request $request, int $deliveryId): array
    {
        abort_unless($request->isJson() && count($request->all()) === 0, 422, 'A solicitação de repetição não aceita conteúdo mutável.');

        return ['delivery_id' => $deliveryId, 'operation' => self::NAME, 'resource_type' => 'erp_accounting_delivery'];
    }

    /** @param array<string, mixed> $references */
    public function assertAuthorized(Request $request, array $references): void
    {
        $context = CurrentCompany::fromRequest($request);
        abort_unless(($references['operation'] ?? null) === self::NAME
            && ($references['resource_type'] ?? null) === 'erp_accounting_delivery'
            && (int) ($references['erp_company_id'] ?? $context->company->id) === (int) $context->company->id, 404);
        app(ErpReleaseGate::class)->assertReleased((int) $context->company->id, $request->user()?->id);
        $query = ErpAccountingDelivery::query()
            ->where('erp_company_id', $context->company->id)
            ->whereKey((int) ($references['delivery_id'] ?? 0));
        app(ErpEstablishmentAccess::class)->apply($query, $context, ErpEstablishmentResource::AccountingDelivery)->firstOrFail();
        $context->assertPermission(self::NAME, true);
    }
}
