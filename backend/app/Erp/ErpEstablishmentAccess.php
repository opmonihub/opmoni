<?php

namespace App\Erp;

use App\Enums\Erp\ErpEstablishmentResource;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpOfficeDelegation;
use App\Models\Erp\Fiscal\ErpFiscalEvent;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class ErpEstablishmentAccess
{
    /** @return list<int>|null Null means company-wide access. */
    public function allowedIds(CurrentCompany $context): ?array
    {
        if ($context->accessKind === 'delegated') {
            $delegation = $context->delegation;
            abort_unless(
                $delegation instanceof ErpOfficeDelegation
                    && (int) $delegation->erp_company_id === (int) $context->company->id
                    && (int) $delegation->account_id === $context->delegatedOfficeId
                    && $delegation->status === 'active'
                    && $context->delegatedOfficeId !== null
                    && ErpOfficeDelegation::query()
                        ->whereKey($delegation->id)
                        ->where('erp_company_id', $context->company->id)
                        ->where('account_id', $context->delegatedOfficeId)
                        ->where('status', 'active')
                        ->exists(),
                403,
                'Contexto delegado inválido',
            );

            return null;
        }
        $membership = $this->directMembership($context);
        if ($membership->hasAllEstablishmentAccess()) {
            return null;
        }

        /** @var list<int> $ids */
        $ids = $membership->establishments()->pluck('erp_establishments.id')->map(fn ($id): int => (int) $id)->all();

        return $ids;
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function apply(Builder $query, CurrentCompany $context, ErpEstablishmentResource $resource): Builder
    {
        $ids = $this->allowedIds($context);
        if ($ids === null) {
            if ($this->allowsCompanywideNullableRecords($context)) {
                return $query;
            }

            return match ($resource) {
                ErpEstablishmentResource::FinancialAccount => $query->whereNotNull('erp_financial_accounts.erp_establishment_id'),
                ErpEstablishmentResource::Obligation => $query->whereNotNull('erp_obligations.erp_establishment_id'),
                ErpEstablishmentResource::Payment => $query->whereNotNull('erp_payments.erp_establishment_id'),
                ErpEstablishmentResource::FiscalCertificate => $query->whereNotNull('erp_fiscal_certificates.erp_establishment_id'),
                default => $query,
            };
        }

        return match ($resource) {
            ErpEstablishmentResource::Establishment => $query->whereIn('erp_establishments.id', $ids),
            ErpEstablishmentResource::TaxRate => $query->where(fn (Builder $inner) => $inner->whereNull('erp_tax_rates.erp_establishment_id')->orWhereIn('erp_tax_rates.erp_establishment_id', $ids)),
            ErpEstablishmentResource::OperationNature => $query->where(fn (Builder $inner) => $inner->whereNull('erp_operation_natures.erp_establishment_id')->orWhereIn('erp_operation_natures.erp_establishment_id', $ids)),
            ErpEstablishmentResource::StockTransfer => $query->whereIn('erp_stock_transfers.from_establishment_id', $ids)->whereIn('erp_stock_transfers.to_establishment_id', $ids),
            ErpEstablishmentResource::FinancialAccount => $this->whereSelectedNonNull($query, 'erp_financial_accounts.erp_establishment_id', $ids),
            ErpEstablishmentResource::Obligation => $this->whereSelectedNonNull($query, 'erp_obligations.erp_establishment_id', $ids),
            ErpEstablishmentResource::Payment => $this->whereSelectedNonNull($query, 'erp_payments.erp_establishment_id', $ids),
            ErpEstablishmentResource::FiscalCertificate => $this->whereSelectedNonNull($query, 'erp_fiscal_certificates.erp_establishment_id', $ids),
            ErpEstablishmentResource::FiscalEvent => $query->whereHas('document', fn (Builder $document) => $document->whereIn('erp_fiscal_documents.erp_establishment_id', $ids)),
            ErpEstablishmentResource::AccountingDelivery => $query->whereHas('erpFiscalDocument', fn (Builder $document) => $document->whereIn('erp_fiscal_documents.erp_establishment_id', $ids)),
            default => $query->whereIn($this->column($resource), $ids),
        };
    }

    public function assertRelated(CurrentCompany $context, int $establishmentId, string $field = 'erp_establishment_id'): ErpEstablishment
    {
        $establishment = ErpEstablishment::query()
            ->where('erp_company_id', $context->company->id)
            ->whereKey($establishmentId)
            ->first();
        if ($establishment === null) {
            throw ValidationException::withMessages([$field => 'Estabelecimento inválido para esta empresa']);
        }

        $allowedIds = $this->allowedIds($context);
        if ($allowedIds !== null && ! in_array($establishmentId, $allowedIds, true)) {
            throw ValidationException::withMessages([$field => 'Estabelecimento não autorizado para esta associação']);
        }

        return $establishment;
    }

    public function assertNullableRelated(CurrentCompany $context, ?int $establishmentId, string $field = 'erp_establishment_id'): void
    {
        if ($establishmentId !== null) {
            $this->assertRelated($context, $establishmentId, $field);

            return;
        }

        $this->allowedIds($context);
        if (! $this->allowsCompanywideNullableRecords($context)) {
            throw ValidationException::withMessages([$field => 'Acesso a registros sem estabelecimento exige escopo completo']);
        }
    }

    public function assertNullableCatalogRelated(CurrentCompany $context, ?int $establishmentId, string $field = 'erp_establishment_id'): void
    {
        if ($establishmentId !== null) {
            $this->assertRelated($context, $establishmentId, $field);
        }
    }

    public function assertAllScope(CurrentCompany $context, string $field = 'erp_establishment_id'): void
    {
        if ($this->allowedIds($context) !== null) {
            throw ValidationException::withMessages([$field => 'Esta operação exige escopo completo de estabelecimentos']);
        }
    }

    public function assertResource(CurrentCompany $context, Model $model): void
    {
        abort_unless($this->resourceVisible($context, $model), 404);
    }

    public function assertRelatedResource(CurrentCompany $context, Model $model, string $field): void
    {
        if (! $this->resourceVisible($context, $model)) {
            throw ValidationException::withMessages([$field => 'Registro relacionado fora do escopo autorizado']);
        }
    }

    private function resourceVisible(CurrentCompany $context, Model $model): bool
    {
        $resource = ErpEstablishmentResource::forModel($model::class);
        if ($resource === null) {
            return false;
        }

        $query = $this->companyResourceQuery($model, $context);

        /** @var Builder<Model> $query */
        return $this->apply($query, $context, $resource)->whereKey($model->getKey())->exists();
    }

    /** @return Builder<Model> */
    private function companyResourceQuery(Model $model, CurrentCompany $context): Builder
    {
        $query = $model->newQuery();
        if ($model::class === ErpFiscalEvent::class) {
            return $query->whereHas('document', fn (Builder $document) => $document->where('erp_company_id', $context->company->id));
        }

        return $query->where($model->getTable().'.erp_company_id', $context->company->id);
    }

    /** @param list<int> $ids
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private function whereSelectedNonNull(Builder $query, string $column, array $ids): Builder
    {
        return $query->whereNotNull($column)->whereIn($column, $ids);
    }

    public function allowsCompanywideNullableRecords(CurrentCompany $context): bool
    {
        if ($context->accessKind !== 'direct' || ! $context->membership instanceof ErpCompanyMembership) {
            return false;
        }

        $membership = ErpCompanyMembership::query()
            ->whereKey($context->membership->id)
            ->where('erp_company_id', $context->company->id)
            ->first();

        return $membership?->hasAllEstablishmentAccess() ?? false;
    }

    private function directMembership(CurrentCompany $context): ErpCompanyMembership
    {
        abort_unless($context->accessKind === 'direct' && $context->membership instanceof ErpCompanyMembership, 403, 'Contexto direto inválido');

        $membership = ErpCompanyMembership::query()
            ->whereKey($context->membership->id)
            ->where('erp_company_id', $context->company->id)
            ->first();
        abort_unless($membership !== null, 403, 'Contexto direto inválido');

        return $membership;
    }

    private function column(ErpEstablishmentResource $resource): string
    {
        return match ($resource) {
            ErpEstablishmentResource::Establishment => 'erp_establishments.id',
            ErpEstablishmentResource::StockBalance => 'erp_stock_balances.erp_establishment_id',
            ErpEstablishmentResource::StockMovement => 'erp_stock_movements.erp_establishment_id',
            ErpEstablishmentResource::StockLot => 'erp_stock_lots.erp_establishment_id',
            ErpEstablishmentResource::StockAdjustment => 'erp_stock_adjustments.erp_establishment_id',
            ErpEstablishmentResource::Purchase => 'erp_purchases.erp_establishment_id',
            ErpEstablishmentResource::PurchaseReturn => 'erp_purchase_returns.erp_establishment_id',
            ErpEstablishmentResource::Quote => 'erp_quotes.erp_establishment_id',
            ErpEstablishmentResource::Order => 'erp_orders.erp_establishment_id',
            ErpEstablishmentResource::Sale => 'erp_sales.erp_establishment_id',
            ErpEstablishmentResource::SaleReturn => 'erp_sale_returns.erp_establishment_id',
            ErpEstablishmentResource::CashRegister => 'erp_cash_registers.erp_establishment_id',
            ErpEstablishmentResource::CashSession => 'erp_cash_sessions.erp_establishment_id',
            ErpEstablishmentResource::FiscalDocument => 'erp_fiscal_documents.erp_establishment_id',
            ErpEstablishmentResource::FiscalInvalidation => 'erp_fiscal_invalidations.erp_establishment_id',
            ErpEstablishmentResource::AccountingMapping => 'erp_accounting_mappings.erp_establishment_id',
            default => throw new \LogicException('Resource requires a dedicated establishment policy'),
        };
    }
}
