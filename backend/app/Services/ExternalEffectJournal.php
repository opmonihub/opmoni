<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ExternalEffectJournal
{
    private const PAYLOAD_KEYS = [
        'serpro.mailbox.read' => ['response_id', 'http_status', 'lida_em', 'ciencia_em', 'prazo_limite', 'request_tag', 'provider_code', 'codigo'],
        'serpro.sitfis.protocol' => ['response_id', 'http_status', 'protocol', 'retry_after_seconds'],
        'serpro.sitfis.report' => ['response_id', 'http_status'],
        'whatsapp.instance.create' => ['instance_id', 'instance_api_key', 'http_status'],
        'whatsapp.instance.rotate' => ['instance_id', 'instance_api_key', 'http_status'],
        'whatsapp.connect' => ['instance_id', 'status', 'http_status'],
        'whatsapp.disconnect' => ['instance_id', 'status', 'http_status'],
        'whatsapp.status' => ['instance_id', 'status', 'http_status'],
        'whatsapp.qr' => ['instance_id', 'status', 'http_status'],
        'whatsapp.webhook' => ['instance_id', 'http_status'],
    ];

    public function __construct(private ExternalEffectJournalConnection $connections) {}

    /** @param array<string, scalar|null|array> $resource */
    public static function resourceHash(int $accountId, string $type, array $resource): string
    {
        if ($accountId < 1 || ! preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/', $type)) {
            throw new InvalidArgumentException('Recurso externo inválido.');
        }

        return hash('sha256', json_encode(['v1', $accountId, $type, self::canonical($resource)], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> Snapshot account-scoped, including owner_token/version and decrypted allowlisted payload. */
    public function begin(int $accountId, int $actorId, string $resourceHash, string $step, ?string $operationId = null): array
    {
        $this->validateResource($accountId, $resourceHash, $step);
        if ($actorId < 1) {
            throw new InvalidArgumentException('Ator externo inválido.');
        }
        if ($operationId !== null) {
            if (! Str::isUuid($operationId) || ! $this->safe(fn () => $this->query()->where('account_id', $accountId)->where('resource_hash', $resourceHash)->where('operation_id', $operationId)->exists())) {
                throw new InvalidArgumentException('Operação externa indisponível nesta conta e recurso.');
            }
            $existing = $this->find($accountId, $operationId, $resourceHash, $step);
            if ($existing !== null) {
                return $existing;
            }
        } else {
            $operationId = (string) Str::uuid();
        }
        $effect = [
            'account_id' => $accountId, 'actor_id' => $actorId, 'operation_id' => $operationId,
            'resource_hash' => $resourceHash, 'effect_key' => hash('sha256', $resourceHash.'|'.$operationId.'|'.$step),
            'step' => $step, 'state' => 'pending', 'receipt' => null, 'owner_token' => (string) Str::uuid(),
            'version' => 1, 'payload_encrypted' => null, 'armed_at' => null, 'confirmed_at' => null,
            'created_at' => now(), 'updated_at' => now(),
        ];
        $this->safe(fn () => $this->query()->insert($effect));

        return $this->find($accountId, $operationId, $resourceHash, $step) ?? throw new RuntimeException('Registro externo não encontrado.');
    }

    /** @param array<string, mixed> $effect @return array<string, mixed> */
    public function arm(array $effect): array
    {
        return $this->transition($effect, 'pending', ['state' => 'uncertain', 'armed_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $effect
     * @param  array<string, string|int|null>  $payload  No body, QR, tokens or PFX; only the one-time WhatsApp instance key is permitted.
     * @return array<string, mixed>
     */
    public function confirm(array $effect, string $receipt, array $payload = []): array
    {
        $step = $effect['step'] ?? '';
        if (! in_array($receipt, ['applied', 'not_applied'], true) || ! isset(self::PAYLOAD_KEYS[$step])) {
            throw new InvalidArgumentException('Recibo externo inválido.');
        }
        foreach ($payload as $key => $value) {
            if (! in_array($key, self::PAYLOAD_KEYS[$step], true) || ! $this->validValue($key, $value)) {
                throw new InvalidArgumentException('Campo não permitido no recibo externo.');
            }
        }
        if (isset($payload['instance_api_key']) && $receipt !== 'applied') {
            throw new InvalidArgumentException('Chave de instância exige recibo aplicado.');
        }

        return $this->transition($effect, 'uncertain', [
            'state' => 'confirmed', 'receipt' => $receipt,
            'payload_encrypted' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)),
            'confirmed_at' => now(),
        ]);
    }

    /** @return array<string, mixed>|null */
    public function find(int $accountId, string $operationId, string $resourceHash, string $step): ?array
    {
        $this->validateResource($accountId, $resourceHash, $step);
        $row = $this->safe(fn () => $this->query()->where('account_id', $accountId)->where('operation_id', $operationId)->where('resource_hash', $resourceHash)->where('step', $step)->first());

        return $row === null ? null : $this->snapshot((array) $row);
    }

    /** @return list<array<string, mixed>> */
    public function forResource(int $accountId, string $resourceHash): array
    {
        if ($accountId < 1 || ! preg_match('/\A[a-f0-9]{64}\z/', $resourceHash)) {
            throw new InvalidArgumentException('Recurso externo inválido.');
        }

        return $this->safe(fn () => $this->query()->where('account_id', $accountId)->where('resource_hash', $resourceHash)->orderBy('id')->get()->map(fn ($row): array => $this->snapshot((array) $row))->all());
    }

    private function query(): Builder
    {
        return $this->connections->connection()->table('external_effect_journal');
    }

    private function transition(array $effect, string $from, array $changes): array
    {
        $this->validateResource($effect['account_id'], $effect['resource_hash'], $effect['step']);
        $updated = $this->safe(fn () => $this->query()
            ->where('account_id', $effect['account_id'])->where('operation_id', $effect['operation_id'])
            ->where('resource_hash', $effect['resource_hash'])->where('step', $effect['step'])
            ->where('owner_token', $effect['owner_token'])->where('version', $effect['version'])
            ->where('state', $from)->update($changes + ['version' => $effect['version'] + 1, 'updated_at' => now()]));
        if ($updated !== 1) {
            throw new RuntimeException('Posse ou versão do efeito externo não confere; reenvio proibido.');
        }

        return $this->find($effect['account_id'], $effect['operation_id'], $effect['resource_hash'], $effect['step']) ?? throw new RuntimeException('Registro externo indisponível.');
    }

    private function snapshot(array $row): array
    {
        $encrypted = $row['payload_encrypted'];
        unset($row['payload_encrypted']);
        $row['payload'] = $encrypted === null ? [] : json_decode(Crypt::decryptString($encrypted), true, flags: JSON_THROW_ON_ERROR);
        $row['version'] = (int) $row['version'];
        $row['account_id'] = (int) $row['account_id'];
        $row['actor_id'] = (int) $row['actor_id'];

        return $row;
    }

    private function validateResource(int $accountId, string $resourceHash, string $step): void
    {
        if ($accountId < 1 || ! preg_match('/\A[a-f0-9]{64}\z/', $resourceHash) || ! isset(self::PAYLOAD_KEYS[$step])) {
            throw new InvalidArgumentException('Recurso ou etapa externa inválida.');
        }
    }

    private function validValue(string $key, mixed $value): bool
    {
        return match ($key) {
            'http_status' => is_int($value) && $value >= 100 && $value <= 599,
            'retry_after_seconds' => is_int($value) && $value >= 0 && $value <= 86400,
            'instance_api_key' => is_string($value) && strlen($value) >= 1 && strlen($value) <= 2048 && ! preg_match('/[\x00-\x1f\x7f]/', $value),
            'status' => is_string($value) && in_array($value, ['connected', 'disconnected', 'connecting', 'pairing', 'unknown', 'error'], true),
            'lida_em', 'ciencia_em', 'prazo_limite' => $value === null || $this->validIsoDate($value),
            'request_tag' => is_string($value) && (bool) preg_match('/\A[a-zA-Z0-9_.:-]{1,64}\z/', $value),
            'provider_code', 'codigo' => is_string($value) && (bool) preg_match('/\A[a-zA-Z0-9_.:-]{1,128}\z/', $value),
            default => is_string($value) && (bool) preg_match('/\A[a-zA-Z0-9_.:\/-]{1,256}\z/', $value),
        };
    }

    private function validIsoDate(mixed $value): bool
    {
        if (! is_string($value) || ! preg_match('/\A(\d{4})-(\d{2})-(\d{2})(?:T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d{1,6})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d))?\z/', $value, $parts)) {
            return false;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    private static function canonical(array $input): array
    {
        if (! array_is_list($input)) {
            ksort($input, SORT_STRING);
        }
        foreach ($input as &$value) {
            if (is_array($value)) {
                $value = self::canonical($value);
            } elseif (! is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException('Identificador de recurso inválido.');
            }
        }

        return $input;
    }

    /** Do not expose SQL, encrypted bindings, or a previous QueryException in logs. */
    private function safe(\Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (Throwable) {
            throw new RuntimeException('Persistência do efeito externo indisponível; reenvio proibido.');
        }
    }
}
