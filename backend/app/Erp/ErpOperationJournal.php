<?php

namespace App\Erp;

use App\Erp\Finance\ErpDecimal;
use App\Erp\Finance\ErpPaymentAllocationOperation;
use App\Models\Erp\ErpOperationReceipt;
use App\Models\Erp\Finance\ErpPaymentAllocation;
use App\Support\AccessSession;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class ErpOperationJournal
{
    public function __construct(private ErpPaymentAllocationOperation $allocation) {}

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
            $payload = $this->allocation->canonical($request, $payment);
            $receipt = $key !== null ? $this->receipts($request, $key)->lockForUpdate()->first() : null;
            $this->allocation->assertAuthorized($request, $payload);
            AccessSession::assertOwnership($request);
            $hash = hash('sha256', 'v1:'.$this->canonicalJson($payload));
            if ($receipt) {
                $this->allocation->assertAuthorized($request, $receipt->resource_refs);
                abort_unless($receipt->canonical_version === 'v1' && hash_equals($receipt->payload_hash, $hash), 409, 'Chave de operação reutilizada com conteúdo diferente');
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
                    'key_digest' => hash('sha256', $key), 'canonical_version' => 'v1', 'payload_hash' => $hash,
                    'response_status' => $result['status'], 'response_body' => $result['body'], 'resource_refs' => $result['resource_refs'], 'recorded_at' => now(),
                ]);
            }
            AccessSession::assertOwnership($request);

            return $result;
        });
    }

    public function lookup(Request $request): array
    {
        $key = $this->key($request, true);
        $receipt = $this->receipts($request, $key)->first();
        if ($receipt) {
            $this->allocation->assertAuthorized($request, $receipt->resource_refs);
            AccessSession::assertOwnership($request);

            return ['operation' => ErpPaymentAllocationOperation::NAME, 'state' => 'completed', 'recorded_at' => $receipt->recorded_at->toISOString(), 'result' => $receipt->response_body['data']];
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

    private function receipts(Request $request, string $key): Builder
    {
        return ErpOperationReceipt::query()->where('erp_company_id', CurrentCompany::fromRequest($request)->company->id)
            ->where('actor_user_id', $request->user()->id)->where('operation', ErpPaymentAllocationOperation::NAME)->where('key_digest', hash('sha256', $key));
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
