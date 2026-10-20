<?php

namespace App\Erp;

use App\Erp\Finance\ErpDecimal;
use App\Erp\Finance\ErpFinanceOperation;
use App\Erp\Finance\ErpPaymentAllocationOperation;
use App\Erp\Sales\ErpCommercialOperation;
use App\Erp\Stock\ErpPurchaseOperation;
use App\Erp\Stock\ErpStockOperation;
use App\Models\Erp\ErpOperationReceipt;
use App\Models\Erp\Finance\ErpPaymentAllocation;
use App\Support\AccessSession;
use App\Support\CurrentCompany;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class ErpOperationJournal
{
    public function __construct(private ErpPaymentAllocationOperation $allocation, private ErpCatalogImportOperation $imports, private ErpStockOperation $stock, private ErpFinanceOperation $finance) {}

    public function key(Request $request, bool $required = false): ?string
    {
        $headers = $request->headers->all('Idempotency-Key');
        $header = $headers[0] ?? null;
        $original = [];
        if ($request->isJson()) {
            $original = json_decode($request->getContent(), true);
        } elseif (str_starts_with((string) $request->header('Content-Type'), 'application/x-www-form-urlencoded')) {
            parse_str($request->getContent(), $original);
        }
        if ($request->exists('source_operation_key') && (! is_array($original) || ! array_key_exists('source_operation_key', $original))) {
            throw ValidationException::withMessages(['source_operation_key' => 'Informe a chave em JSON, formulário codificado ou Idempotency-Key']);
        }
        $hasAlias = is_array($original) && array_key_exists('source_operation_key', $original);
        $alias = $hasAlias ? $original['source_operation_key'] : null;
        if (count($headers) > 1 || ($header !== null && ! $this->validKey($header))
            || ($hasAlias && ! $this->validKey($alias))
            || ($header !== null && $hasAlias && $header !== $alias)) {
            throw ValidationException::withMessages(['source_operation_key' => 'Chave de operação inválida ou divergente']);
        }
        $key = $header ?? $alias;
        if ($required && $key === null) {
            throw ValidationException::withMessages(['source_operation_key' => 'Informe Idempotency-Key']);
        }

        return $key;
    }

    private function validKey(mixed $key): bool
    {
        return is_string($key) && preg_match('/^[\x21-\x7E]{1,128}$/D', $key) === 1 && ! str_contains($key, ',');
    }

    public function execute(Request $request, int|string $payment): array
    {
        abort_unless(DB::transactionLevel() > 0, 500);

        return DB::transaction(function () use ($request, $payment): array {
            $key = $this->key($request);
            $receipt = $key !== null ? $this->receipts($request, $key)->lockForUpdate()->first() : null;
            $version = $receipt?->canonical_version ?? 'v2';
            abort_unless(in_array($version, ['v1', 'v2'], true), 409);
            $payload = $this->allocation->canonical($request, $payment, $receipt !== null && $version === 'v1');
            $this->allocation->assertAuthorized($request, $payload);
            AccessSession::assertOwnership($request);
            $hash = hash('sha256', $version.':'.$this->canonicalJson($payload));
            if ($receipt) {
                $this->allocation->assertAuthorized($request, $receipt->resource_refs);
                abort_unless(hash_equals($receipt->payload_hash, $hash), 409, 'Chave de operação reutilizada com conteúdo diferente');
                AccessSession::assertOwnership($request);

                return ['status' => $receipt->response_status, 'body' => $receipt->response_body];
            }
            $participantKey = $key === null ? 'allocation-'.Str::uuid() : 'op1:'.hash('sha256', $this->canonicalJson([
                CurrentCompany::fromRequest($request)->company->id, $request->user()->id, ErpPaymentAllocationOperation::NAME, hash('sha256', $key),
            ]));
            if ($key !== null) {
                $legacy = $this->legacy($request, $key)->limit(2)->get();
                if ($legacy->isNotEmpty() || $this->legacy($request, $participantKey)->exists()) {
                    $error = ['code' => 'legacy_operation_requires_reconciliation', 'message' => 'Operação anterior exige conciliação'];
                    if ($legacy->count() === 1 && $legacy[0]->posted_by_user_id === $request->user()->id
                        && $legacy[0]->erp_payment_id === $payload['payment_id'] && $legacy[0]->erp_obligation_id === $payload['erp_obligation_id']
                        && ErpDecimal::parse($legacy[0]->amount) === $payload['amount']) {
                        $error['resource'] = ['type' => 'payment_allocation', 'id' => $legacy[0]->id];
                    }
                    abort(response()->json($error, 409));
                }
            }
            AccessSession::assertOwnership($request);
            $result = $this->allocation->post($request, $payload, $participantKey);
            abort_if(strlen($this->canonicalJson($result['body'])) > 16384, 422, 'Resultado de operação inválido');
            if ($key !== null) {
                ErpOperationReceipt::query()->create([
                    'erp_company_id' => CurrentCompany::fromRequest($request)->company->id,
                    'actor_user_id' => $request->user()->id, 'operation' => ErpPaymentAllocationOperation::NAME,
                    'key_digest' => hash('sha256', $key), 'canonical_version' => 'v2', 'payload_hash' => $hash,
                    'response_status' => $result['status'], 'response_body' => $result['body'], 'resource_refs' => $result['resource_refs'], 'recorded_at' => now(),
                ]);
            }
            AccessSession::assertOwnership($request);

            return $result;
        });
    }

    public static function operations(): array
    {
        return [ErpPaymentAllocationOperation::NAME, ErpCatalogImportOperation::PRODUCTS, ErpCatalogImportOperation::CONTACTS, ...ErpStockOperation::names(), ...ErpFinanceOperation::names(), ...ErpCommercialOperation::names(), ...ErpPurchaseOperation::names()];
    }

    public function executeImport(Request $request, string $operation): array
    {
        abort_unless(DB::transactionLevel() > 0 && in_array($operation, [ErpCatalogImportOperation::PRODUCTS, ErpCatalogImportOperation::CONTACTS], true), 500);

        return DB::transaction(function () use ($request, $operation): array {
            $key = $this->key($request, true);
            $payload = $this->imports->canonical($request, $operation);
            $receipt = $this->receipts($request, $key, $operation)->lockForUpdate()->first();
            $this->imports->assertAuthorized($request, $operation, $payload);
            AccessSession::assertOwnership($request);
            $hash = hash('sha256', 'v1:'.$this->canonicalJson($payload));
            if ($receipt) {
                $this->imports->assertAuthorized($request, $operation, $receipt->resource_refs);
                abort_unless($receipt->canonical_version === 'v1' && hash_equals($receipt->payload_hash, $hash), 409, 'Chave de operação reutilizada com conteúdo diferente');
                AccessSession::assertOwnership($request);

                return ['status' => $receipt->response_status, 'body' => $receipt->response_body];
            }
            $result = $this->imports->post($request, $operation, $payload, hash('sha256', $key), $hash);
            abort_if(strlen($this->canonicalJson($result['body'])) > 16384, 422, 'Resultado de operação inválido');
            ErpOperationReceipt::query()->create([
                'erp_company_id' => CurrentCompany::fromRequest($request)->company->id,
                'actor_user_id' => $request->user()->id, 'operation' => $operation,
                'key_digest' => hash('sha256', $key), 'canonical_version' => 'v1', 'payload_hash' => $hash,
                'response_status' => $result['status'], 'response_body' => $result['body'], 'resource_refs' => $result['resource_refs'], 'recorded_at' => now(),
            ]);
            AccessSession::assertOwnership($request);

            return $result;
        });
    }

    /** @param Closure(string):JsonResponse $post */
    public function executeStock(Request $request, string $operation, ?int $sourceId, Closure $post): JsonResponse
    {
        abort_unless(DB::transactionLevel() > 0 && in_array($operation, ErpStockOperation::names(), true), 500);

        return DB::transaction(function () use ($request, $operation, $sourceId, $post): JsonResponse {
            $key = $this->key($request) ?? ($sourceId === null ? 'stock-'.Str::uuid() : 'source-'.$operation.'-'.$sourceId);
            $payload = $this->stock->canonical($request, $operation, $sourceId);
            $this->stock->assertAuthorized($request, $operation, $payload);
            AccessSession::assertOwnership($request);
            $hash = hash('sha256', 'v1:'.$this->canonicalJson($payload));
            $receipt = $this->receipts($request, $key, $operation)->lockForUpdate()->first();
            if ($receipt) {
                $this->stock->assertAuthorized($request, $operation, $receipt->resource_refs);
                abort_unless($receipt->canonical_version === 'v1' && hash_equals($receipt->payload_hash, $hash), 409, 'Chave de operação reutilizada com conteúdo diferente');
                AccessSession::assertOwnership($request);

                return response()->json($receipt->response_body, $receipt->response_status);
            }
            $this->stock->assertNoLegacy($request, $operation, $payload, $key);
            $participantKey = 'op1:'.hash('sha256', $this->canonicalJson([CurrentCompany::fromRequest($request)->company->id, $request->user()->id, $operation, hash('sha256', $key)]));
            $response = $post($participantKey);
            $body = $response->getData(true);
            abort_if(strlen($this->canonicalJson($body)) > 16384, 422, 'Resultado de operação inválido');
            ErpOperationReceipt::query()->create([
                'erp_company_id' => CurrentCompany::fromRequest($request)->company->id, 'actor_user_id' => $request->user()->id,
                'operation' => $operation, 'key_digest' => hash('sha256', $key), 'canonical_version' => 'v1', 'payload_hash' => $hash,
                'response_status' => $response->getStatusCode(), 'response_body' => $body, 'resource_refs' => ['resource_id' => $body['data']['id']], 'recorded_at' => now(),
            ]);
            AccessSession::assertOwnership($request);

            return $response;
        });
    }

    public function executeFinance(Request $request, string $operation, int|string|null $sourceId = null): JsonResponse
    {
        abort_unless(DB::transactionLevel() > 0 && in_array($operation, ErpFinanceOperation::names(), true), 500);

        return DB::transaction(function () use ($request, $operation, $sourceId): JsonResponse {
            $payload = $this->finance->canonical($request, $operation, $sourceId);
            $this->finance->assertAuthorized($request, $operation, $payload);
            if ($operation === ErpFinanceOperation::CLOSE && $request->exists('close_operation_key')) {
                $alias = ErpFinanceOperation::rawInput($request)['close_operation_key'] ?? null;
                if (! $this->validKey($alias) || ($request->hasHeader('Idempotency-Key') && $request->header('Idempotency-Key') !== $alias)) {
                    throw ValidationException::withMessages(['close_operation_key' => 'Chave de fechamento inválida ou divergente.']);
                }
                if (! $request->hasHeader('Idempotency-Key')) {
                    $request->headers->set('Idempotency-Key', $alias);
                }
            }
            $key = $this->key($request, true);
            AccessSession::assertOwnership($request);
            $hash = hash('sha256', 'v1:'.$this->canonicalJson($payload));
            $receipt = $this->receipts($request, $key, $operation)->lockForUpdate()->first();
            if ($receipt) {
                $this->finance->assertAuthorized($request, $operation, $receipt->resource_refs);
                abort_unless($receipt->canonical_version === 'v1' && hash_equals($receipt->payload_hash, $hash), 409, 'Chave de operação reutilizada com conteúdo diferente');
                AccessSession::assertOwnership($request);

                return response()->json($receipt->response_body, $receipt->response_status);
            }
            $this->finance->assertNoLegacy($request, $operation, $key);
            $participantKey = 'op1:'.hash('sha256', $this->canonicalJson([CurrentCompany::fromRequest($request)->company->id, $request->user()->id, $operation, hash('sha256', $key)]));
            $this->finance->assertNoLegacy($request, $operation, $participantKey);
            $result = $this->finance->post($request, $operation, $payload, $participantKey);
            abort_if(strlen($this->canonicalJson($result['body'])) > 16384, 422, 'Resultado de operação inválido');
            ErpOperationReceipt::create([
                'erp_company_id' => CurrentCompany::fromRequest($request)->company->id, 'actor_user_id' => $request->user()->id,
                'operation' => $operation, 'key_digest' => hash('sha256', $key), 'canonical_version' => 'v1', 'payload_hash' => $hash,
                'response_status' => $result['status'], 'response_body' => $result['body'], 'resource_refs' => $result['resource_refs'], 'recorded_at' => now(),
            ]);
            AccessSession::assertOwnership($request);

            return response()->json($result['body'], $result['status']);
        });
    }

    public function executeCommercial(Request $request, string $operation, ?int $id = null): JsonResponse
    {
        abort_unless(DB::transactionLevel() > 0 && in_array($operation, ErpCommercialOperation::names(), true), 500);

        return DB::transaction(function () use ($request, $operation, $id): JsonResponse {
            $adapter = app(ErpCommercialOperation::class);
            $adapter->assertAuthorized($request, $operation, ['source_id' => $id]);
            $key = $this->key($request, true);
            $receipt = $this->receipts($request, $key, $operation)->lockForUpdate()->first();
            if ($receipt && $receipt->canonical_version === 'v1' && in_array($operation, ErpStockOperation::names(), true)) {
                $payload = $this->stock->canonical($request, $operation, $id);
                foreach (['draft_revision', 'commercial_snapshot', 'draft_input', 'erp_price_group_id', 'erp_operation_nature_id', 'conversion_source_id', ...($operation === 'erp.sales.post' ? [] : ['reason', 'currency', 'subtotal_amount', 'discount_amount', 'tax_amount'])] as $field) {
                    unset($payload['snapshot'][$field]);
                }
                foreach ($payload['snapshot']['lines'] ?? [] as $index => $line) {
                    foreach (['ordinal', 'erp_unit_id', 'gross_amount', 'base_quantity', 'erp_company_id', 'source_quote_line_id', 'source_quote_document_id', 'source_order_line_id', 'source_order_document_id'] as $field) {
                        unset($line[$field]);
                    }
                    $payload['snapshot']['lines'][$index] = $line;
                }
                $this->stock->assertAuthorized($request, $operation, $receipt->resource_refs);
                abort_unless(hash_equals($receipt->payload_hash, hash('sha256', 'v1:'.$this->canonicalJson($payload))), 409);
                AccessSession::assertOwnership($request);

                return response()->json($receipt->response_body, $receipt->response_status);
            }
            $payload = $adapter->canonical($request, $operation, $id);
            $hash = hash('sha256', 'commercial-v1:'.$this->canonicalJson($adapter->identity($payload)));
            if ($receipt) {
                $adapter->assertAuthorized($request, $operation, $receipt->resource_refs + ['_original_response' => $receipt->response_body]);
                abort_unless($receipt->canonical_version === 'commercial-v1' && (hash_equals($receipt->payload_hash, $hash) || hash_equals($receipt->payload_hash, hash('sha256', 'commercial-v1:'.$this->canonicalJson($payload)))), 409, 'Chave de operação reutilizada com conteúdo diferente');
                AccessSession::assertOwnership($request);

                return response()->json($receipt->response_body, $receipt->response_status);
            }
            AccessSession::assertOwnership($request);
            $participantKey = 'op1:'.hash('sha256', $this->canonicalJson([CurrentCompany::fromRequest($request)->company->id, $request->user()->id, $operation, hash('sha256', $key)]));
            $result = $adapter->post($request, $operation, $payload, $participantKey);
            abort_if(strlen($this->canonicalJson($result['body'])) > 16384, 422, 'Resultado de operação inválido');
            ErpOperationReceipt::create(['erp_company_id' => CurrentCompany::fromRequest($request)->company->id, 'actor_user_id' => $request->user()->id, 'operation' => $operation, 'key_digest' => hash('sha256', $key), 'canonical_version' => 'commercial-v1', 'payload_hash' => $hash, 'response_status' => $result['status'], 'response_body' => $result['body'], 'resource_refs' => $result['resource_refs'], 'recorded_at' => now()]);
            AccessSession::assertOwnership($request);

            return response()->json($result['body'], $result['status']);
        });
    }

    public function executePurchase(Request $request, string $operation, ?int $id = null): JsonResponse
    {
        abort_unless(DB::transactionLevel() > 0 && in_array($operation, ErpPurchaseOperation::names(), true), 500);

        return DB::transaction(function () use ($request, $operation, $id): JsonResponse {
            $adapter = app(ErpPurchaseOperation::class);
            $adapter->authorize($request, $operation, ['source_id' => $id]);
            $key = $this->key($request, true);
            $receipt = $this->receipts($request, $key, $operation)->lockForUpdate()->first();
            if ($receipt && $operation === ErpStockOperation::PURCHASE && $receipt->canonical_version === 'v1') {
                $payload = $this->stock->canonical($request, $operation, $id);
                foreach (['source_import_id', 'source_access_key', 'source_sha256', 'source_byte_length', 'source_snapshot', 'draft_revision', 'commercial_snapshot'] as $field) {
                    unset($payload['snapshot'][$field]);
                }
                foreach ($payload['snapshot']['lines'] ?? [] as $index => $line) {
                    foreach (['ordinal', 'source_ordinal', 'description', 'erp_unit_id', 'base_quantity'] as $field) {
                        unset($line[$field]);
                    }
                    $payload['snapshot']['lines'][$index] = $line;
                }
                $this->stock->assertAuthorized($request, $operation, $receipt->resource_refs);
                abort_unless(hash_equals($receipt->payload_hash, hash('sha256', 'v1:'.$this->canonicalJson($payload))), 409, 'Chave de operação reutilizada com conteúdo diferente');
                AccessSession::assertOwnership($request);

                return response()->json($receipt->response_body, $receipt->response_status);
            }
            $payload = $adapter->canonical($request, $operation, $id);
            $hash = hash('sha256', 'purchase-v1:'.$this->canonicalJson($payload));
            if ($receipt) {
                abort_unless($receipt->canonical_version === 'purchase-v1' && hash_equals($receipt->payload_hash, $hash), 409, 'Chave reutilizada com conteúdo diferente.');
                $adapter->authorize($request, $operation, $receipt->resource_refs);
                AccessSession::assertOwnership($request);

                return response()->json($receipt->response_body, $receipt->response_status);
            }
            AccessSession::assertOwnership($request);
            $participantKey = 'op1:'.hash('sha256', $this->canonicalJson([CurrentCompany::fromRequest($request)->company->id, $request->user()->id, $operation, hash('sha256', $key)]));
            $result = $adapter->post($request, $operation, $payload, $participantKey, hash('sha256', $key), $hash);
            $responseLimit = strlen($this->canonicalJson($result['body'])) > 1_048_576;
            abort_if($responseLimit, 422, 'Resultado de operação excede o limite permitido');
            ErpOperationReceipt::create(['erp_company_id' => CurrentCompany::fromRequest($request)->company->id, 'actor_user_id' => $request->user()->id, 'operation' => $operation, 'key_digest' => hash('sha256', $key), 'canonical_version' => 'purchase-v1', 'payload_hash' => $hash, 'response_status' => $result['status'], 'response_body' => $result['body'], 'resource_refs' => $result['resource_refs'], 'recorded_at' => now()]);
            AccessSession::assertOwnership($request);

            return response()->json($result['body'], $result['status']);
        });
    }

    public function lookup(Request $request): array
    {
        $key = $this->key($request, true);
        $operation = $request->input('operation');
        abort_unless(in_array($operation, self::operations(), true), 422);
        $receipt = $this->receipts($request, $key, $operation)->first();
        if ($receipt) {
            if (in_array($operation, ErpPurchaseOperation::names(), true) && $receipt->canonical_version === 'purchase-v1') {
                app(ErpPurchaseOperation::class)->authorize($request, $operation, $receipt->resource_refs);
            } elseif ($operation === ErpPaymentAllocationOperation::NAME) {
                $this->allocation->assertAuthorized($request, $receipt->resource_refs);
            } elseif (in_array($operation, ErpCommercialOperation::names(), true) && $receipt->canonical_version === 'commercial-v1') {
                app(ErpCommercialOperation::class)->assertAuthorized($request, $operation, $receipt->resource_refs + ['_original_response' => $receipt->response_body]);
            } elseif (in_array($operation, ErpFinanceOperation::names(), true)) {
                $this->finance->assertAuthorized($request, $operation, $receipt->resource_refs);
            } elseif (in_array($operation, ErpStockOperation::names(), true)) {
                $this->stock->assertAuthorized($request, $operation, $receipt->resource_refs);
            } else {
                $this->imports->assertAuthorized($request, $operation, $receipt->resource_refs);
            }
            AccessSession::assertOwnership($request);

            return ['operation' => $operation, 'state' => 'completed', 'recorded_at' => $receipt->recorded_at->toISOString(), 'result' => $receipt->response_body['data']];
        }
        if ($operation !== ErpPaymentAllocationOperation::NAME) {
            abort(response()->json(['code' => 'operation_not_recorded', 'message' => 'Operação não registrada'], 404));
        }
        $legacy = $this->legacy($request, $key)->limit(2)->get();
        if ($legacy->count() === 1 && $legacy[0]->posted_by_user_id === $request->user()->id) {
            $row = $legacy[0];
            $this->allocation->assertAuthorized($request, ['payment_id' => $row->erp_payment_id, 'erp_obligation_id' => $row->erp_obligation_id, 'allocation_id' => $row->id]);
            AccessSession::assertOwnership($request);

            return ['operation' => ErpPaymentAllocationOperation::NAME, 'state' => 'legacy_observed', 'recorded_at' => null, 'result' => null, 'resource' => ['type' => 'payment_allocation', 'id' => $row->id]];
        }
        abort(response()->json(['code' => 'operation_not_recorded', 'message' => 'Operação não registrada'], 404));
    }

    private function receipts(Request $request, string $key, string $operation = ErpPaymentAllocationOperation::NAME): Builder
    {
        return ErpOperationReceipt::query()->where('erp_company_id', CurrentCompany::fromRequest($request)->company->id)
            ->where('actor_user_id', $request->user()->id)->where('operation', $operation)->where('key_digest', hash('sha256', $key));
    }

    private function legacy(Request $request, string $key): Builder
    {
        $companyId = CurrentCompany::fromRequest($request)->company->id;

        return ErpPaymentAllocation::query()->where('source_operation_key', $key)
            ->whereHas('payment', fn ($query) => $query->where('erp_company_id', $companyId))
            ->whereHas('obligation', fn ($query) => $query->where('erp_company_id', $companyId));
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode($this->canonicalValue($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function canonicalValue(mixed $value): mixed
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return array_map(fn ($item) => $this->canonicalValue($item), $value);
        }
        if (! is_null($value) && ! is_string($value) && ! is_int($value) && ! is_bool($value)) {
            throw new InvalidArgumentException('Unsupported operation value.');
        }

        return $value;
    }
}
