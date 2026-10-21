<?php

namespace App\Erp\Fiscal;

use App\Jobs\ProcessErpFiscalAttempt;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\Fiscal\ErpFiscalAttempt;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ErpFiscalAttemptService
{
    /** @param array<string, mixed> $intent */
    public function createIntent(array $intent): ErpFiscalAttempt
    {
        $required = ['erp_company_id', 'erp_establishment_id', 'action', 'model', 'environment', 'actor_user_id', 'idempotency_key', 'request'];
        foreach ($required as $key) {
            if (! array_key_exists($key, $intent) || $intent[$key] === null || $intent[$key] === '') {
                throw new InvalidArgumentException('Intenção fiscal incompleta: '.$key.'.');
            }
        }
        if (! is_array($intent['request']) || ! in_array((string) $intent['model'], ['55', '65', '57', '58'], true)) {
            throw new InvalidArgumentException('Intenção fiscal inválida.');
        }

        $identity = [
            'erp_establishment_id' => (int) $intent['erp_establishment_id'],
            'action' => (string) $intent['action'],
            'model' => (string) $intent['model'],
            'environment' => (string) $intent['environment'],
            'series' => isset($intent['series']) ? (int) $intent['series'] : null,
            'number' => isset($intent['number']) ? (int) $intent['number'] : null,
            'erp_fiscal_document_id' => $intent['erp_fiscal_document_id'] ?? null,
            'erp_fiscal_event_id' => $intent['erp_fiscal_event_id'] ?? null,
            'erp_fiscal_invalidation_id' => $intent['erp_fiscal_invalidation_id'] ?? null,
            'request' => $this->sortAssociativeKeys($intent['request']),
        ];
        $canonicalData = $this->sortAssociativeKeys($identity);
        $canonical = json_encode($canonicalData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $requestHash = hash('sha256', $canonical);

        try {
            return DB::transaction(function () use ($intent, $requestHash, $canonical): ErpFiscalAttempt {
                $existing = ErpFiscalAttempt::query()
                    ->where('erp_company_id', $intent['erp_company_id'])
                    ->where('action', $intent['action'])
                    ->where('idempotency_key', $intent['idempotency_key'])
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    if ((int) $existing->actor_user_id !== (int) $intent['actor_user_id'] || ! hash_equals((string) $existing->request_sha256, $requestHash)) {
                        throw new InvalidArgumentException('Chave de idempotência fiscal reutilizada com ator ou solicitação diferente.');
                    }

                    return $existing;
                }

                return ErpFiscalAttempt::query()->create([
                    'erp_company_id' => (int) $intent['erp_company_id'],
                    'erp_establishment_id' => (int) $intent['erp_establishment_id'],
                    'erp_fiscal_document_id' => $intent['erp_fiscal_document_id'] ?? null,
                    'erp_fiscal_event_id' => $intent['erp_fiscal_event_id'] ?? null,
                    'erp_fiscal_invalidation_id' => $intent['erp_fiscal_invalidation_id'] ?? null,
                    'erp_fiscal_configuration_revision_id' => $intent['erp_fiscal_configuration_revision_id'] ?? null,
                    'erp_fiscal_certificate_revision_id' => $intent['erp_fiscal_certificate_revision_id'] ?? null,
                    'action' => (string) $intent['action'],
                    'model' => (string) $intent['model'],
                    'environment' => (string) $intent['environment'],
                    'series' => isset($intent['series']) ? (int) $intent['series'] : null,
                    'number' => isset($intent['number']) ? (int) $intent['number'] : null,
                    'actor_user_id' => (int) $intent['actor_user_id'],
                    'idempotency_key' => (string) $intent['idempotency_key'],
                    'request_sha256' => $requestHash,
                    'encrypted_source_snapshot' => Crypt::encryptString($canonical),
                    'schema_release' => $intent['schema_release'] ?? null,
                    'status' => $intent['status'] ?? 'pending',
                    'outbox_state' => 'new',
                    'dispatch_attempts' => 0,
                    'available_at' => now(),
                ]);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            $existing = ErpFiscalAttempt::query()->where('erp_company_id', $intent['erp_company_id'])->where('action', $intent['action'])->where('idempotency_key', $intent['idempotency_key'])->first();
            if ($existing && (int) $existing->actor_user_id === (int) $intent['actor_user_id'] && hash_equals((string) $existing->request_sha256, $requestHash)) {
                return $existing;
            }
            throw new InvalidArgumentException('Chave de idempotência fiscal reutilizada com ator ou solicitação diferente.');
        }
    }

    public function dispatchAfterCommit(ErpFiscalAttempt $attempt): void
    {
        DB::afterCommit(static function () use ($attempt): void {
            try {
                ProcessErpFiscalAttempt::dispatch((int) $attempt->id);
            } catch (\Throwable) {
                // The committed outbox row remains discoverable by the bounded scanner.
            }
        });
    }

    public function claim(int $attemptId, int $leaseSeconds = 180): ?ErpFiscalAttempt
    {
        return DB::transaction(function () use ($attemptId, $leaseSeconds): ?ErpFiscalAttempt {
            $candidate = ErpFiscalAttempt::query()->whereKey($attemptId)->first();
            if (! $candidate) {
                return null;
            }
            // ResolveCompany writes acquire Company before fiscal rows. Keep the same order here.
            ErpCompany::query()->whereKey($candidate->erp_company_id)->lockForUpdate()->first();
            $attempt = ErpFiscalAttempt::query()->whereKey($attemptId)->lockForUpdate()->first();
            if (! $attempt || ! in_array($attempt->outbox_state, ['new', 'leased'], true) || ($attempt->available_at && $attempt->available_at->isFuture())) {
                return null;
            }
            if ($attempt->outbox_state === 'leased') {
                if ($attempt->lease_expires_at?->isFuture()) {
                    return null;
                }
                $attempt->status = 'indeterminate';
                $attempt->last_error_code = 'lease_expired_after_dispatch_started';
                if (! in_array($attempt->action, ['document:transmit', 'document:reconcile', 'document:consult'], true)) {
                    $attempt->outbox_state = 'attention';
                    $attempt->lease_token = null;
                    $attempt->lease_expires_at = null;
                    $attempt->completed_at = now();
                    $attempt->save();

                    return null;
                }
                $attempt->outbox_state = 'new';
                $attempt->safe_result = ['code' => 'consult_before_retransmit', 'provider_outcome' => 'unknown'];
            }
            if (! app(ErpFiscalAttemptAuthorization::class)->allowsDispatch($attempt)) {
                $attempt->status = 'blocked_authority';
                $attempt->outbox_state = 'attention';
                $attempt->last_error_code = 'authority_revoked';
                $attempt->completed_at = now();
                $attempt->save();

                return null;
            }

            $attempt->status = 'submitting';
            $attempt->outbox_state = 'leased';
            $attempt->lease_token = (string) Str::uuid();
            $attempt->claimed_at = now();
            $attempt->lease_expires_at = now()->addSeconds(max(30, min($leaseSeconds, 600)));
            $attempt->dispatch_attempts = (int) $attempt->dispatch_attempts + 1;
            $attempt->save();

            return $attempt;
        }, 3);
    }

    /** @param array<string, mixed> $result */
    public function complete(int $attemptId, string $leaseToken, string $status, array $result = [], ?string $receipt = null, ?string $protocol = null): bool
    {
        return $this->finalize($attemptId, $leaseToken, $status, $result, $receipt, $protocol, static function (): void {});
    }

    /** @param array<string, mixed> $result */
    public function finalize(int $attemptId, string $leaseToken, string $status, array $result, ?string $receipt, ?string $protocol, callable $applyCorrelatedOutcome): bool
    {
        if (! in_array($status, ['accepted', 'rejected', 'indeterminate', 'blocked_authority', 'attention_required', 'artifact_pending'], true)) {
            throw new InvalidArgumentException('Resultado de tentativa fiscal inválido.');
        }

        return DB::transaction(function () use ($attemptId, $leaseToken, $status, $result, $receipt, $protocol, $applyCorrelatedOutcome): bool {
            $candidate = ErpFiscalAttempt::query()->whereKey($attemptId)->first();
            if (! $candidate) {
                return false;
            }
            ErpCompany::query()->whereKey($candidate->erp_company_id)->lockForUpdate()->first();
            $attempt = ErpFiscalAttempt::query()->whereKey($attemptId)->lockForUpdate()->first();
            if (! $attempt || $attempt->outbox_state !== 'leased' || ! hash_equals((string) $attempt->lease_token, $leaseToken)) {
                return false;
            }
            if ($attempt->erp_fiscal_document_id !== null) {
                $attempt->document()->lockForUpdate()->first();
            }
            $transition = $applyCorrelatedOutcome($attempt);
            $attempt->status = $status;
            if ($transition === false) {
                $result['document_transition'] = 'superseded';
                $result['document_status'] = $attempt->document()->first()?->status;
                $result['artifact_ready'] = false;
            }
            $safeResult = $this->sanitizeResult($result);
            $attempt->safe_result = $safeResult;
            $attempt->provider_receipt = $this->safeIdentifier($receipt, 128);
            $attempt->provider_protocol = $this->safeIdentifier($protocol, 64);
            $recoverable = $status === 'indeterminate' && in_array($attempt->action, ['document:transmit', 'document:consult', 'document:reconcile'], true) && (int) $attempt->dispatch_attempts < 8;
            $attempt->outbox_state = $recoverable ? 'new' : (in_array($status, ['indeterminate', 'attention_required', 'artifact_pending'], true) ? 'attention' : 'completed');
            if ($recoverable) {
                $attempt->available_at = now()->addSeconds(min(3600, 15 * (2 ** min(8, (int) $attempt->dispatch_attempts))));
            }
            $attempt->lease_token = null;
            $attempt->lease_expires_at = null;
            $attempt->last_error_code = $safeResult['code'] ?? null;
            $attempt->completed_at = now();
            $attempt->save();

            return true;
        }, 3);
    }

    /** @param array<string, mixed> $value @return array<string, mixed> */
    private function sortAssociativeKeys(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortAssociativeKeys($item);
            }
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /** @param array<string, mixed> $result @return array<string, mixed> */
    private function sanitizeResult(array $result): array
    {
        $safe = [];
        foreach (['code', 'document_status', 'document_transition', 'artifact_ready', 'artifact_sha256', 'artifact_byte_length', 'provider_outcome', 'blocker'] as $key) {
            if (! array_key_exists($key, $result) || ! is_scalar($result[$key]) || is_string($result[$key]) && strlen($result[$key]) > 512) {
                continue;
            }
            if ($key === 'document_transition' && ! in_array($result[$key], ['superseded'], true)
                || $key === 'provider_outcome' && ! in_array($result[$key], ['authorized', 'rejected', 'indeterminate', 'event_accepted', 'event_rejected', 'unknown', 'not_dispatched'], true)
                || in_array($key, ['code', 'blocker'], true) && ! preg_match('/^[a-z0-9_]{1,64}$/', (string) $result[$key])
                || $key === 'artifact_ready' && ! is_bool($result[$key])
                || $key === 'artifact_sha256' && ! preg_match('/^[a-f0-9]{64}$/', (string) $result[$key])) {
                continue;
            }
            $safe[$key] = $result[$key];
        }

        return $safe;
    }

    private function safeIdentifier(?string $value, int $maxLength): ?string
    {
        if ($value === null || strlen($value) > $maxLength || ! preg_match('/^[A-Za-z0-9._:-]+$/', $value)) {
            return null;
        }

        return $value;
    }
}
