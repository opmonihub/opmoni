<?php

namespace App\Http\Middleware;

use App\Erp\ErpCommercialGate;
use App\Erp\ErpCompanyAdministration;
use App\Erp\ErpCompanyAuthorization;
use App\Erp\ErpOperationJournal;
use App\Erp\ErpOperationNatureRules;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\Erp\Fiscal\ErpFiscalEvent;
use App\Models\Erp\Fiscal\ErpFiscalInvalidation;
use App\Support\AccessSession;
use App\Support\AccessTransaction;
use App\Support\CurrentCompany;
use App\Support\ErpCompanyContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class ResolveCompany
{
    public function __construct(
        private ErpCompanyAuthorization $authorization,
        private ErpCommercialGate $commercialGate,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user(), 401);
        $companyId = $request->session()->get('current_company_id');
        if (! is_int($companyId) && ! (is_string($companyId) && ctype_digit($companyId))) {
            abort(response()->json(['code' => 'company_required', 'message' => 'Selecione uma empresa'], 403));
        }
        $companyId = (int) $companyId;
        $officeId = $request->session()->get('current_account_id');
        $officeId = is_int($officeId) || (is_string($officeId) && ctype_digit($officeId)) ? (int) $officeId : null;

        return DB::transaction(function () use ($request, $next, $companyId, $officeId) {
            AccessTransaction::boundLockWaits();
            $company = ErpCompany::lockForUpdate()->find($companyId);
            if (! $company) {
                abort(response()->json(['code' => 'company_required', 'message' => 'Selecione uma empresa'], 403));
            }

            $access = $this->authorization->resolveAccess($request->user(), $company, $officeId, ! $request->isMethodSafe() || $request->routeIs('erp.operations.lookup', 'erp.payments.allocation-candidates'));
            if ($access === null) {
                abort(response()->json(['code' => 'membership_required', 'message' => 'Acesso à empresa negado'], 403));
            }

            $sessionKind = $request->session()->get('current_company_access_kind');
            $sessionOffice = $request->session()->get('current_company_office_id');
            $sessionDelegation = $request->session()->get('current_company_delegation_id');
            if ($access['kind'] === 'delegated') {
                if ($sessionKind !== 'delegated'
                    || (int) $sessionOffice !== $access['office_id']
                    || (int) $sessionDelegation !== (int) $access['delegation']->id) {
                    abort(response()->json(['code' => 'membership_required', 'message' => 'Acesso à empresa negado'], 403));
                }
            } elseif ($sessionKind === 'delegated') {
                abort(response()->json(['code' => 'membership_required', 'message' => 'Acesso à empresa negado'], 403));
            }

            $assertion = $request->header('X-Company-Id');
            if (! is_string($assertion) || ! ctype_digit($assertion) || strlen($assertion) > 16 || (int) $assertion < 1 || (int) $assertion > 9007199254740991) {
                abort(response()->json(['code' => 'validation_error', 'message' => 'Informe a empresa exibida', 'errors' => ['company_id' => ['Informe a empresa exibida']]], 422));
            }
            if ((int) $assertion !== $companyId) {
                abort(response()->json(['code' => 'company_context_changed', 'message' => 'A empresa selecionada mudou. Atualize a página.'], 409));
            }
            if (! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS']) && $request->exists('company_id')) {
                abort(response()->json(['code' => 'validation_error', 'message' => 'Empresa não pode ser informada no conteúdo', 'errors' => ['company_id' => ['Campo não permitido']]], 422));
            }
            if (! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS']) && $request->exists('account_id')) {
                abort(response()->json(['code' => 'validation_error', 'message' => 'Escritório não pode ser informado no conteúdo', 'errors' => ['account_id' => ['Campo não permitido']]], 422));
            }

            $operationLookup = $request->routeIs('erp.operations.lookup');
            if ($operationLookup) {
                $request->validate(['operation' => ['required', 'string', Rule::in(ErpOperationJournal::operations())]]);
            }
            $effectiveWrite = ! $request->isMethodSafe() || $operationLookup || $request->routeIs('erp.payments.allocation-candidates');
            $foundationSettingsWrite = $request->routeIs('erp.company.settings.update');
            if ($effectiveWrite && ! $foundationSettingsWrite) {
                $commercialDenial = $this->commercialGate->operatingWriteDenialCode($company);
                if ($commercialDenial !== null) {
                    $message = $commercialDenial === 'erp_trial_expired' ? 'Período de teste encerrado' : 'Contrato ERP inativo';
                    abort(response()->json(['code' => $commercialDenial, 'message' => $message], 403));
                }
            }
            if ($effectiveWrite
                && ! config('erp.operating_writes_enabled')
                && ! $foundationSettingsWrite) {
                abort(response()->json(['code' => 'erp_operating_disabled', 'message' => 'Operações ERP ainda não disponíveis'], 403));
            }
            $erpContext = resolve(ErpCompanyContext::class);
            $previousCompanyId = $erpContext->companyId;
            $erpContext->companyId = $companyId;
            $context = new CurrentCompany(
                $company,
                $access['kind'],
                $access['kind'] === 'direct' ? $access['membership'] : null,
                $access['kind'] === 'delegated' ? $access['delegation'] : null,
                $access['kind'] === 'delegated' ? $access['office_id'] : null,
                $access['kind'] === 'delegated' ? $access['office_role'] : null,
            );
            $request->attributes->set(CurrentCompany::class, $context);
            try {
                if ($request->routeIs('erp.members.index', 'erp.members.show', 'erp.members.permissions', 'erp.members.identity-lookup', 'erp.members.store', 'erp.members.update', 'erp.members.destroy')) {
                    app(ErpCompanyAdministration::class)->assertOwner($request);
                } else {
                    $context->assertPermission($operationLookup ? $request->input('operation') : $this->effectiveAction($request), $effectiveWrite);
                }
                $action = $operationLookup ? $request->input('operation') : (string) $request->route()?->getName();
                $module = match (true) {
                    str_starts_with($action, 'erp.purchases.') || str_starts_with($action, 'erp.purchase-returns.') => 'purchases',
                    str_starts_with($action, 'erp.sales.') || str_starts_with($action, 'erp.sale-returns.') || str_starts_with($action, 'erp.quotes.') || str_starts_with($action, 'erp.orders.') => 'add_sale',
                    str_starts_with($action, 'erp.stock.transfers.') => 'stock_transfers',
                    str_starts_with($action, 'erp.stock.adjustments.') || $action === 'erp.stock.movements.reverse' => 'stock_adjustment',
                    default => null,
                };
                $module ??= $this->fiscalModule($request, $companyId);
                if ($module !== null && ! $this->commercialGate->moduleEnabled($company, $module)) {
                    abort(response()->json(['code' => 'erp_module_disabled', 'message' => 'Módulo não habilitado no contrato'], 403));
                }

                AccessSession::assertOwnership($request);
                $response = $next($request);
                AccessSession::assertOwnership($request);
                $response->headers->set('Cache-Control', 'no-store');

                return $response;
            } finally {
                $erpContext->companyId = $previousCompanyId;
                $request->attributes->remove(CurrentCompany::class);
            }
        });
    }

    private function effectiveAction(Request $request): string
    {
        return match ((string) $request->route()?->getName()) {
            'erp.cfops.index', 'erp.cfops.show' => 'erp.operation-natures.index',
            'erp.payments.allocation-candidates' => 'erp.payments.allocate',
            'erp.purchases.xml-preview.read' => 'erp.purchases.xml-confirm',
            'erp.catalog.import.products.read' => 'erp.catalog.import.products.confirm',
            'erp.catalog.import.contacts.read' => 'erp.catalog.import.contacts.confirm',
            default => ErpOperationNatureRules::permission((string) $request->route()?->getName(), $request),
        };
    }

    private function fiscalModule(Request $request, int $companyId): ?string
    {
        if (! $request->routeIs('erp.fiscal.*')) {
            return null;
        }
        $model = null;
        if ($request->route('document') !== null) {
            $id = $request->route('document');
            $model = ErpFiscalDocument::query()->where('erp_company_id', $companyId)->whereKey($id)->value('model');
        } elseif ($request->route('event') !== null) {
            $event = ErpFiscalEvent::query()->whereHas('document', fn ($query) => $query->where('erp_company_id', $companyId))->with('document')->whereKey($request->route('event'))->first();
            $model = $event?->document?->model;
        } elseif ($request->route('invalidation') !== null) {
            $model = ErpFiscalInvalidation::query()->where('erp_company_id', $companyId)->whereKey($request->route('invalidation'))->value('model');
        } elseif ($request->routeIs('erp.fiscal.events.store')) {
            $id = $request->input('erp_fiscal_document_id');
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $model = ErpFiscalDocument::query()->where('erp_company_id', $companyId)->whereKey($id)->value('model');
            }
        } elseif ($request->routeIs('erp.fiscal.documents.store', 'erp.fiscal.invalidations.store')) {
            $model = $request->input('model');
        }

        return match ((string) $model) {
            '57' => 'cte',
            '58' => 'mdfe',
            default => null,
        };
    }
}
