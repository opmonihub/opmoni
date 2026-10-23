<?php

namespace App\Erp\Sales;

use App\Erp\ErpCommercialReturnService;
use App\Erp\ErpEstablishmentAccess;
use App\Erp\Finance\ErpFinanceOperation;
use App\Erp\Finance\ErpFinanceParticipant;
use App\Erp\Finance\ErpFinanceReadService;
use App\Erp\Stock\ErpStockParticipant;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\Finance\ErpObligation;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ErpCommercialOperation
{
    public static function names(): array
    {
        return ['erp.quotes.store', 'erp.quotes.update', 'erp.quotes.convert', 'erp.orders.store', 'erp.orders.update', 'erp.orders.convert', 'erp.sales.store', 'erp.sales.update', 'erp.sales.post', 'erp.sale-returns.store', 'erp.sale-returns.post', 'erp.purchase-returns.store', 'erp.purchase-returns.post'];
    }

    public function kind(string $operation): string
    {
        return match (explode('.', $operation)[1]) {
            'quotes' => 'quote', 'orders' => 'order', 'sales' => 'sale', 'sale-returns' => 'sale_return', 'purchase-returns' => 'purchase_return'
        };
    }

    public function canonical(Request $request, string $operation, ?int $id): array
    {
        $body = ErpFinanceOperation::rawInput($request);
        unset($body['source_operation_key']);
        $this->strict($body);
        if ($id !== null) {
            ErpCommercialDraftService::id($id, 'source_id');
        }
        if (isset($body['lines']) && is_array($body['lines'])) {
            $lineFields = str_ends_with($this->kind($operation), '_return') ? ['source_line_id', 'quantity'] : ['id', 'erp_product_variant_id', 'description', 'erp_unit_id', 'quantity', 'unit_price', 'discount_amount', 'tax_amount', 'unit_conversion_factor', 'erp_tax_rate_id', 'erp_discount_rule_id', 'line_total'];
            foreach ($body['lines'] as $i => $line) {
                if (! is_array($line) || array_diff(array_keys($line), $lineFields) !== []) {
                    throw ValidationException::withMessages(['lines.'.$i => 'Conteúdo da linha não suportado.']);
                }
            }
        }
        $kind = $this->kind($operation);
        $isReturn = str_ends_with($kind, '_return');
        $allowed = match (true) {
            $isReturn && str_ends_with($operation, '.post') => ['expected_total'],
            $isReturn => [$kind === 'sale_return' ? 'erp_sale_id' : 'erp_purchase_id', 'reason', 'lines', 'expected_total', 'total_amount'],
            str_ends_with($operation, '.post') => ['draft_revision', 'expected_total', 'payment'],
            str_ends_with($operation, '.convert') => ['draft_revision', $kind === 'quote' ? 'order_no' : 'invoice_no'],
            default => ['erp_establishment_id', 'erp_contact_id', 'erp_price_group_id', 'erp_operation_nature_id', 'currency', ErpCommercialDraftService::numberField($kind), ...($kind === 'sale' ? ['sale_no'] : []), 'lines', 'expected_total', 'total_amount', 'subtotal_amount', 'discount_amount', 'tax_amount', 'discount', 'tax_rate_percent', 'erp_discount_rule_id', 'draft_revision'],
        };
        if (array_diff(array_keys($body), $allowed) !== []) {
            throw ValidationException::withMessages(['operation' => 'Conteúdo não suportado.']);
        }

        return ['source_id' => $id, 'body' => $body];
    }

    private function strict(array $body, string $prefix = ''): void
    {
        foreach ($body as $key => $value) {
            $field = $prefix.$key;
            if (is_array($value)) {
                $this->strict($value, $field.'.');
            } elseif (str_ends_with((string) $key, '_id') || in_array($key, ['id', 'source_line_id', 'draft_revision'], true)) {
                if ($value !== null) {
                    ErpCommercialDraftService::id($value, $field);
                }
            } elseif (in_array($key, ['quantity', 'unit_price', 'discount_amount', 'tax_amount', 'total_amount', 'subtotal_amount', 'expected_total', 'line_total', 'tax_rate_percent', 'amount', 'unit_conversion_factor'], true)) {
                ErpCommercialDraftService::decimal($value, $field, $key === 'unit_conversion_factor' ? 8 : 4);
            } elseif (! is_null($value) && ! is_string($value) && ! is_int($value) && ! is_bool($value)) {
                throw ValidationException::withMessages([$field => 'Conteúdo inválido.']);
            }
        }
    }

    /** Normalize accepted numeric representations while preserving omitted/default/null modes. */
    public function identity(array $payload): array
    {
        $normalized = [];
        foreach ($payload as $field => $value) {
            if (is_array($value)) {
                $value = $this->identity($value);
            } elseif ($value !== null && (str_ends_with((string) $field, '_id') || in_array($field, ['id', 'draft_revision'], true))) {
                $value = ErpCommercialDraftService::id($value, $field);
            } elseif ($value !== null && in_array($field, ['quantity', 'unit_price', 'discount_amount', 'tax_amount', 'total_amount', 'subtotal_amount', 'expected_total', 'line_total', 'tax_rate_percent', 'amount', 'unit_conversion_factor'], true)) {
                $value = ErpCommercialDraftService::decimal($value, $field, $field === 'unit_conversion_factor' ? 8 : 4);
            }
            $normalized[$field] = $value;
        }

        return $normalized;
    }

    public function assertAuthorized(Request $request, string $operation, array $refs): void
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertPermission($operation, true);
        $kind = $this->kind($operation);
        if (isset($refs['_original_response'])) {
            $originalScopes = $refs['establishment_ids'] ?? null;
            if ($originalScopes === null) {
                abort_if(str_ends_with($operation, '.update'), response()->json(['code' => 'legacy_operation_requires_reconciliation', 'reason' => 'original_scope_unproved'], 409));
                $originalScopes = [];
                $collect = function (array $value) use (&$collect, &$originalScopes): void {
                    foreach ($value as $field => $item) {
                        if ($field === 'erp_establishment_id' && $item !== null) {
                            $originalScopes[] = ErpCommercialDraftService::id($item, 'erp_establishment_id');
                        } elseif (is_array($item)) {
                            $collect($item);
                        }
                    }
                };
                $collect($refs['_original_response']);
                abort_if($originalScopes === [], response()->json(['code' => 'legacy_operation_requires_reconciliation', 'reason' => 'original_scope_unproved'], 409));
            }
            foreach ($originalScopes as $establishmentId) {
                $establishment = ErpEstablishment::where('erp_company_id', $context->company->id)->findOrFail($establishmentId);
                app(ErpEstablishmentAccess::class)->assertResource($context, $establishment);
            }
        }
        if (isset($refs['resource_id']) || isset($refs['source_id'])) {
            $id = $refs['resource_id'] ?? $refs['source_id'];
            if (str_ends_with($kind, '_return')) {
                app(ErpCommercialReturnService::class)->resource($request, $kind, $id);
            } else {
                app(ErpCommercialDraftService::class)->resource($request, $kind, $id);
            }
        }
        foreach ($refs['documents'] ?? [] as $document) {
            app(ErpCommercialDraftService::class)->resource($request, $document['kind'], $document['id']);
        }
        foreach (array_filter([$refs['obligation_id'] ?? null, ...($refs['obligations'] ?? [])]) as $obligationId) {
            app(ErpFinanceReadService::class)->resource($request, ErpObligation::class, $obligationId);
        }
        if (isset($refs['payment_refs'])) {
            app(ErpFinanceOperation::class)->assertAuthorized($request, ErpFinanceOperation::PAYMENT, $refs['payment_refs']);
            $context->assertPermission('erp.payments.allocate', true);
            if (! empty($refs['payment_refs']['cashier_sessions'])) {
                $context->assertPermission('erp.cash-sessions.movements.store', true);
            }
        }
    }

    public function post(Request $request, string $operation, array $payload, string $key): array
    {
        $kind = $this->kind($operation);
        $body = $payload['body'];
        $id = $payload['source_id'];
        $drafts = app(ErpCommercialDraftService::class);
        $refs = ['establishment_ids' => []];
        if ($id !== null) {
            $existing = str_ends_with($kind, '_return') ? app(ErpCommercialReturnService::class)->resource($request, $kind, $id) : $drafts->resource($request, $kind, $id);
            $refs['establishment_ids'] = str_ends_with($kind, '_return') ? app(ErpCommercialReturnService::class)->scopeIds($existing, $kind) : $drafts->scopeIds($existing);
        }
        if (str_ends_with($kind, '_return')) {
            $returns = app(ErpCommercialReturnService::class);
            $model = str_ends_with($operation, '.post') ? $returns->post($request, $kind, $id, $body, $key) : $returns->store($request, $kind, $body);
            $data = $returns->serialize($model, false);
            if ($data['credit_id'] !== null) {
                $refs['obligations'] = [$data['credit_id']];
            }
        } elseif (str_ends_with($operation, '.convert')) {
            [$source, $child] = $drafts->convert($request, $kind, $id, $body);
            $target = $kind === 'quote' ? 'order' : 'sale';
            $data = [$kind => $drafts->serialize($source, false), $target => $drafts->serialize($child, false)];
            $model = $source;
            $refs['documents'] = [['kind' => $target, 'id' => $child->id]];
            $refs['establishment_ids'] = [...$refs['establishment_ids'], ...$drafts->scopeIds($child)];
        } elseif (str_ends_with($operation, '.post')) {
            $model = $this->postSale($request, $id, $body, $key, $refs);
            $data = $drafts->serialize($model, false) + ['obligation_id' => $refs['obligation_id'] ?? null, 'payment_id' => $refs['payment_id'] ?? null, 'financial_disposition' => bccomp($model->total_amount, '0', 4) === 0 ? 'zero_consideration' : 'receivable'];
        } else {
            $model = $drafts->save($request, $kind, $body, $id);
            $data = $drafts->serialize($model, false);
        }
        $scopes = str_ends_with($kind, '_return') ? app(ErpCommercialReturnService::class)->scopeIds($model, $kind) : $drafts->scopeIds($model);
        $refs['establishment_ids'] = array_values(array_unique([...($refs['establishment_ids'] ?? []), ...$scopes]));
        $refs['resource_id'] = $model->id;

        return ['status' => str_ends_with($operation, '.store') ? 201 : 200, 'body' => ['data' => $data], 'resource_refs' => $refs];
    }

    private function postSale(Request $request, int $id, array $body, string $key, array &$refs): Model
    {
        $drafts = app(ErpCommercialDraftService::class);
        $sale = $drafts->resource($request, 'sale', $id, true)->load('lines');
        abort_unless($sale->status === 'draft', 409, 'Venda já lançada.');
        abort_unless($sale->draft_revision !== null && $sale->lines->isNotEmpty(), response()->json(['code' => 'legacy_operation_requires_reconciliation'], 409));
        $drafts->assertRevision($sale, $body);
        if (isset($body['expected_total']) && ErpCommercialDraftService::decimal($body['expected_total'], 'expected_total') !== $sale->total_amount) {
            throw ValidationException::withMessages(['expected_total' => 'Total alterado.']);
        }
        $drafts->validateNewReferences($request, $sale);
        $paymentPayload = null;
        if (isset($body['payment'])) {
            if (! is_array($body['payment'])) {
                throw ValidationException::withMessages(['payment' => 'Pagamento inválido.']);
            }
            $context = CurrentCompany::fromRequest($request);
            $context->assertPermission('erp.payments.store', true);
            $context->assertPermission('erp.payments.allocate', true);
            $tender = $body['payment'];
            if (($tender['direction'] ?? null) !== 'in' || (isset($tender['currency']) && $tender['currency'] !== $sale->currency) || (isset($tender['erp_establishment_id']) && (int) $tender['erp_establishment_id'] !== $sale->erp_establishment_id)) {
                throw ValidationException::withMessages(['payment' => 'Direção, moeda ou estabelecimento incompatível.']);
            }
            $amount = ErpCommercialDraftService::decimal($tender['amount'] ?? null, 'payment.amount');
            if (bccomp($amount, '0', 4) <= 0 || bccomp($amount, $sale->total_amount, 4) > 0) {
                throw ValidationException::withMessages(['payment.amount' => 'Pagamento deve ser positivo e não exceder o total.']);
            }
            $tender['currency'] = $sale->currency;
            $tender['erp_establishment_id'] = $sale->erp_establishment_id;
            $paymentRequest = Request::create($request->getPathInfo(), 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($tender, JSON_THROW_ON_ERROR));
            $paymentPayload = app(ErpFinanceOperation::class)->canonical($paymentRequest, ErpFinanceOperation::PAYMENT, null);
        }
        app(ErpStockParticipant::class)->issueForSale($sale->erp_company_id, $sale->erp_establishment_id, $sale->id, $key, $sale->lines->map(fn (Model $line): array => $line->only(['erp_product_variant_id', 'quantity', 'unit_conversion_factor', 'product_snapshot']) + ['line_id' => $line->id])->all(), $request->user()->id, $sale->currency);
        $sale->commercial_snapshot = $drafts->acceptedSnapshot($sale, $request->user()->id);
        $sale->status = 'posted';
        $sale->posted_by_user_id = $request->user()->id;
        $sale->posted_at = now();
        $sale->source_operation_key = $key;
        $sale->save();
        if (bccomp($sale->total_amount, '0', 4) > 0) {
            $refs = array_replace($refs, app(ErpFinanceParticipant::class)->postInlineSalePayment($request, $sale, $paymentPayload, $key));
        }

        return $sale;
    }
}
