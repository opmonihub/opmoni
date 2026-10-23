<?php

namespace App\Erp\Stock;

use App\Erp\ErpEstablishmentAccess;
use App\Erp\Finance\ErpDecimal;
use App\Erp\Finance\ErpFinanceOperation;
use App\Erp\Finance\ErpFinanceReadService;
use App\Erp\Sales\ErpCommercialDraftService as Decimal;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\Finance\ErpObligation;
use App\Models\Erp\Stock\ErpPurchaseImport;
use App\Support\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ErpPurchaseOperation
{
    public const CONFIRM = 'erp.purchases.xml-confirm';

    public static function names(): array
    {
        return ['erp.purchases.store', 'erp.purchases.update', 'erp.purchases.post', self::CONFIRM];
    }

    public function canonical(Request $request, string $operation, ?int $id): array
    {
        $body = ErpFinanceOperation::rawInput($request);
        unset($body['source_operation_key']);
        $allowed = match ($operation) {
            self::CONFIRM => ['token', 'erp_establishment_id', 'erp_contact_id', 'items'],
            'erp.purchases.post' => ['draft_revision', 'expected_total'],
            default => ['erp_establishment_id', 'erp_contact_id', 'document_no', 'currency', 'lines', 'expected_total', 'draft_revision'],
        };
        if (array_diff(array_keys($body), $allowed)) {
            throw ValidationException::withMessages(['operation' => 'Conteúdo não suportado.']);
        }
        foreach (['lines', 'items'] as $field) {
            if (array_key_exists($field, $body)) {
                if (! is_array($body[$field]) || ! array_is_list($body[$field]) || count($body[$field]) < 1 || count($body[$field]) > 500) {
                    throw ValidationException::withMessages([$field => 'Informe de 1 a 500 itens.']);
                }
                $fields = $field === 'items' ? ['line', 'erp_product_variant_id', 'erp_unit_id', 'unit_conversion_factor'] : ['id', 'erp_product_variant_id', 'description', 'erp_unit_id', 'quantity', 'unit_cost', 'unit_conversion_factor', 'erp_tax_rate_id', 'erp_operation_nature_id', 'discount_amount', 'tax_amount'];
                foreach ($body[$field] as $index => $line) {
                    if (! is_array($line) || array_is_list($line) || array_diff(array_keys($line), $fields)) {
                        throw ValidationException::withMessages([$field.'.'.$index => 'Conteúdo da linha não suportado.']);
                    }
                    if ($field === 'items') {
                        foreach (['line', 'erp_product_variant_id', 'unit_conversion_factor'] as $required) {
                            if (! isset($line[$required])) {
                                throw ValidationException::withMessages([$field.'.'.$index.'.'.$required => 'Campo obrigatório.']);
                            }
                        }
                    }
                }
            }
        }
        $body = $this->normalize($body);
        if ($operation === self::CONFIRM) {
            foreach (['erp_establishment_id', 'erp_contact_id', 'items'] as $field) {
                if (! isset($body[$field])) {
                    throw ValidationException::withMessages([$field => 'Campo obrigatório.']);
                }
            }
            $preview = app(ErpPurchaseImportService::class)->find($request, true);

            return ['source_id' => null, 'body' => $body, 'preview_id' => $preview->id, 'source_sha256' => $preview->source_sha256];
        }

        return ['source_id' => $id, 'body' => $body];
    }

    private function normalize(array $body, string $prefix = ''): array
    {
        foreach ($body as $field => &$value) {
            $path = $prefix.$field;
            $isIdentifier = str_ends_with((string) $field, '_id') || in_array($field, ['id', 'draft_revision', 'line'], true);
            $isDecimal = in_array($field, ['quantity', 'unit_cost', 'discount_amount', 'tax_amount', 'expected_total', 'unit_conversion_factor'], true);
            if ($isIdentifier && $value !== null) {
                $value = Decimal::id($value, $path);
            } elseif ($isDecimal) {
                if ($field !== 'unit_conversion_factor' && is_string($value) && str_contains($value, ',')) {
                    try {
                        $value = ErpDecimal::parse($value);
                    } catch (ValidationException) {
                        throw ValidationException::withMessages([$path => 'Informe um decimal em texto válido.']);
                    }
                }
                $value = Decimal::decimal($value, $path, $field === 'unit_conversion_factor' ? 8 : 4);
            } elseif (in_array($field, ['description', 'document_no', 'currency'], true)) {
                if ($value !== null && ! is_string($value)) {
                    throw ValidationException::withMessages([$path => 'Informe texto.']);
                }
            } elseif (is_array($value)) {
                $value = $this->normalize($value, $path.'.');
            } elseif ($value !== null && ! is_string($value)) {
                throw ValidationException::withMessages([$path => 'Informe texto.']);
            }
        }
        unset($value);

        return $body;
    }

    public function authorize(Request $request, string $operation, array $refs): void
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertPermission($operation, true);
        foreach ($refs['establishment_ids'] ?? [] as $id) {
            $establishment = ErpEstablishment::where('erp_company_id', $context->company->id)->findOrFail($id);
            app(ErpEstablishmentAccess::class)->assertResource($context, $establishment);
        }
        if (isset($refs['resource_id']) || isset($refs['source_id'])) {
            app(ErpPurchaseDraftService::class)->resource($request, $refs['resource_id'] ?? $refs['source_id']);
        }
        if (isset($refs['preview_id'])) {
            $preview = ErpPurchaseImport::where('erp_company_id', $context->company->id)->where('actor_user_id', $request->user()->id)->findOrFail($refs['preview_id']);
            $establishment = ErpEstablishment::where('erp_company_id', $context->company->id)->findOrFail($preview->erp_establishment_id);
            app(ErpEstablishmentAccess::class)->assertResource($context, $establishment);
            if ($preview->erp_purchase_id) {
                app(ErpPurchaseDraftService::class)->resource($request, $preview->erp_purchase_id);
            }
        }
        if (isset($refs['obligation_id'])) {
            app(ErpFinanceReadService::class)->resource($request, ErpObligation::class, $refs['obligation_id']);
        }
    }

    public function post(Request $request, string $operation, array $payload, string $key, string $keyDigest, string $hash): array
    {
        $drafts = app(ErpPurchaseDraftService::class);
        $refs = [];
        if ($payload['source_id'] !== null) {
            $existing = $drafts->resource($request, $payload['source_id']);
            $refs['establishment_ids'] = [$existing->erp_establishment_id];
        }
        if ($operation === self::CONFIRM) {
            $data = app(ErpPurchaseImportService::class)->complete($request, $payload['body'], $keyDigest, $hash);
            $refs['preview_id'] = $payload['preview_id'];
            $model = $drafts->resource($request, $data['id']);
        } elseif ($operation === 'erp.purchases.post') {
            [$model, $obligation] = $drafts->post($request, $payload['source_id'], $payload['body'], $key);
            $data = $drafts->serialize($model, false) + ['obligation_id' => $obligation?->id, 'financial_disposition' => $obligation ? 'payable' : 'zero_consideration'];
            if ($obligation) {
                $refs['obligation_id'] = $obligation->id;
            }
        } else {
            $model = $drafts->save($request, $payload['body'], $payload['source_id']);
            $data = $drafts->serialize($model, false);
        }
        $refs['resource_id'] = $model->id;
        $refs['establishment_ids'] = array_values(array_unique([...($refs['establishment_ids'] ?? []), $model->erp_establishment_id]));

        return ['status' => in_array($operation, ['erp.purchases.store', self::CONFIRM], true) ? 201 : 200, 'body' => ['data' => $data], 'resource_refs' => $refs];
    }
}
