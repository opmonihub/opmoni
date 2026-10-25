<?php

namespace App\Erp\Commercial;

use App\Models\Erp\Commercial\OfficeErpAuditEvent;
use App\Models\Erp\Commercial\OfficeErpCommercialOperation;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;

final class OfficeErpOperations
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  Closure(string, array<string, mixed>): void  $authorize
     * @param  Closure(): array<string, mixed>  $effect
     * @return array<string, mixed>
     */
    public function execute(OfficeErpExecutionContext $context, string $key, string $action, array $payload, Closure $authorize, Closure $effect): array
    {
        $this->assertContext($context);
        $this->assertKey($key);
        $this->assertAction($action);
        $this->assertPayloadHasNoSecrets($payload);

        $canonicalPayload = $this->canonicalize($payload);
        $payloadJson = $this->encode($canonicalPayload);
        $payloadHash = hash('sha256', $payloadJson);

        try {
            return DB::transaction(function () use ($context, $key, $action, $canonicalPayload, $payloadJson, $payloadHash, $authorize, $effect): array {
                $authorize($action, $canonicalPayload);

                $operation = $this->operationQuery($context, $key)->lockForUpdate()->first();
                if ($operation !== null) {
                    return $this->replayOrConflict($operation, $action, $payloadHash);
                }

                $record = new OfficeErpCommercialOperation;
                $record->forceFill([
                    'actor_kind' => $context->actorKind,
                    'actor_identity' => $context->actorIdentity,
                    'idempotency_key' => $key,
                    'action' => $action,
                    'payload_hash' => $payloadHash,
                    'payload' => json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR),
                ]);
                $record = OfficeErpRecords::persist($context, $record);

                $outcome = $this->sanitizeOutcome($effect());
                $record->forceFill(['outcome' => $outcome]);
                OfficeErpRecords::persist($context, $record);

                $audit = new OfficeErpAuditEvent;
                $audit->forceFill([
                    'operation_id' => $record->getKey(),
                    'actor_kind' => $context->actorKind,
                    'actor_identity' => $context->actorIdentity,
                    'actor_user_id' => $context->actor?->getKey(),
                    'action' => $action,
                    'subject_type' => $this->auditSubjectType($outcome),
                    'subject_id' => $this->auditSubjectId($outcome),
                    'metadata' => [
                        'payload_hash' => $payloadHash,
                        'payload' => $canonicalPayload,
                        'outcome' => $outcome,
                    ],
                ]);
                OfficeErpRecords::persist($context, $audit);

                return $outcome;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            return $this->recoverUniqueRace($context, $key, $action, $canonicalPayload, $payloadHash, $authorize, $exception);
        }
    }

    /**
     * @param  Closure(string, array<string, mixed>): void  $authorize
     * @return array<string, mixed>
     */
    public function lookup(OfficeErpExecutionContext $context, string $key, Closure $authorize): array
    {
        $this->assertContext($context);
        $this->assertKey($key);

        return DB::transaction(function () use ($context, $key, $authorize): array {
            $identity = $this->operationQuery($context, $key)
                ->first(['id', 'action', 'payload', 'payload_hash']);

            if ($identity === null) {
                abort(404);
            }

            $payload = $this->assertStoredPayload($identity->payload);
            $this->assertPayloadHasNoSecrets($payload);
            $payload = $this->canonicalize($payload);
            abort_unless(hash_equals((string) $identity->payload_hash, hash('sha256', $this->encode($payload))), 409);
            $authorize((string) $identity->action, $payload);

            $operation = $this->operationQuery($context, $key)->lockForUpdate()->first();
            if ($operation === null
                || (string) $operation->action !== (string) $identity->action
                || ! hash_equals((string) $operation->payload_hash, (string) $identity->payload_hash)) {
                abort(409);
            }

            return $this->sanitizeOutcome($this->assertStoredOutcome($operation->outcome));
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $canonicalPayload
     * @param  Closure(string, array<string, mixed>): void  $authorize
     * @return array<string, mixed>
     */
    private function recoverUniqueRace(OfficeErpExecutionContext $context, string $key, string $action, array $canonicalPayload, string $payloadHash, Closure $authorize, UniqueConstraintViolationException $original): array
    {
        return DB::transaction(function () use ($context, $key, $action, $canonicalPayload, $payloadHash, $authorize, $original): array {
            $authorize($action, $canonicalPayload);
            $operation = $this->operationQuery($context, $key)->lockForUpdate()->first();
            if ($operation === null) {
                throw $original;
            }

            return $this->replayOrConflict($operation, $action, $payloadHash);
        }, 3);
    }

    /** @return Builder<OfficeErpCommercialOperation> */
    private function operationQuery(OfficeErpExecutionContext $context, string $key): Builder
    {
        return OfficeErpRecords::query($context, OfficeErpCommercialOperation::class)
            ->where('actor_kind', $context->actorKind)
            ->where('actor_identity', $context->actorIdentity)
            ->where('idempotency_key', $key);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function canonicalize(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->canonicalize($value);
            }
        }

        if (! array_is_list($payload)) {
            ksort($payload, SORT_STRING);
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function assertPayloadHasNoSecrets(array $payload): void
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && $this->isSecretKey($key)) {
                throw ValidationException::withMessages(['payload' => ['Dados secretos não podem ser incluídos em uma operação comercial.']]);
            }
            if (is_array($value)) {
                $this->assertPayloadHasNoSecrets($value);
            } elseif (is_object($value) || is_resource($value)) {
                throw ValidationException::withMessages(['payload' => ['O conteúdo da operação deve usar apenas valores JSON.']]);
            }
        }
    }

    /** @param array<string, mixed> $outcome @return array<string, mixed> */
    private function sanitizeOutcome(array $outcome): array
    {
        foreach ($outcome as $key => $value) {
            if (is_string($key) && $this->isSecretKey($key)) {
                unset($outcome[$key]);

                continue;
            }
            if (is_array($value)) {
                $outcome[$key] = $this->sanitizeOutcome($value);
            } elseif (is_object($value) || is_resource($value)) {
                unset($outcome[$key]);
            }
        }

        return $outcome;
    }

    private function isSecretKey(string $key): bool
    {
        return preg_match('/(?:token|password|secret|credential|authorization|private[_-]?key|api[_-]?key)/i', $key) === 1;
    }

    /** @param array<string, mixed> $outcome */
    private function auditSubjectType(array $outcome): ?string
    {
        $subjectType = $outcome['subject_type'] ?? null;
        if (! is_string($subjectType) || strlen($subjectType) > 191 || preg_match('/^[A-Za-z0-9_\\\\.-]+$/', $subjectType) !== 1) {
            return null;
        }

        return $subjectType;
    }

    /** @param array<string, mixed> $outcome */
    private function auditSubjectId(array $outcome): ?int
    {
        $subjectId = $outcome['subject_id'] ?? null;
        if (! is_int($subjectId) || $subjectId <= 0) {
            return null;
        }

        return $subjectId;
    }

    /** @param array<string, mixed> $value */
    private function encode(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            throw ValidationException::withMessages(['payload' => ['O conteúdo da operação não pode ser serializado.']]);
        }
    }

    /** @param mixed $payload @return array<string, mixed> */
    private function assertStoredPayload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        abort(409);
    }

    /** @param mixed $outcome @return array<string, mixed> */
    private function assertStoredOutcome(mixed $outcome): array
    {
        if (is_array($outcome)) {
            return $outcome;
        }

        abort(409);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function replayOrConflict(OfficeErpCommercialOperation $operation, string $action, string $payloadHash): array
    {
        $storedPayload = $this->assertStoredPayload($operation->payload);
        $this->assertPayloadHasNoSecrets($storedPayload);
        $storedPayloadHash = hash('sha256', $this->encode($this->canonicalize($storedPayload)));

        if ((string) $operation->action !== $action
            || ! hash_equals((string) $operation->payload_hash, $storedPayloadHash)
            || ! hash_equals($storedPayloadHash, $payloadHash)) {
            abort(409);
        }

        return $this->sanitizeOutcome($this->assertStoredOutcome($operation->outcome));
    }

    private function assertContext(OfficeErpExecutionContext $context): void
    {
        $context->assertVerified();
        abort_unless($context->account->exists && $context->actorIdentity !== '', 403);
    }

    private function assertKey(string $key): void
    {
        if ($key === '' || strlen($key) > 191) {
            throw ValidationException::withMessages(['idempotency_key' => ['A chave da operação é inválida.']]);
        }
    }

    private function assertAction(string $action): void
    {
        if ($action === '' || strlen($action) > 100) {
            throw ValidationException::withMessages(['action' => ['A ação da operação é inválida.']]);
        }
    }
}
