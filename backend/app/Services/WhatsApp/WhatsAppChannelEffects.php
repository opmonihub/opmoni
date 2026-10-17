<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppChannel;
use App\Services\ExternalEffectJournal;
use App\Support\ExternalHttpBudget;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Durable provider receipts; the ordinary channel is only a recoverable projection. */
final class WhatsAppChannelEffects
{
    public function __construct(private ExternalEffectJournal $journal, private WzapClient $wzap) {}

    public static function resource(int $accountId): string
    {
        return ExternalEffectJournal::resourceHash($accountId, 'whatsapp.channel', ['name' => 'opmoni-account-'.$accountId]);
    }

    public function operationNotice(int $accountId): ?array
    {
        $rows = $this->journal->forResource($accountId, self::resource($accountId));
        if ($rows === []) {
            return null;
        }
        $last = $rows[array_key_last($rows)];
        foreach ($rows as $row) {
            if ($row['state'] === 'uncertain') {
                return ['code' => in_array($row['step'], ['whatsapp.connect', 'whatsapp.disconnect'], true) ? 'operation_reconciliation_required' : 'operation_manual_review_required',
                    'operation_id' => $row['operation_id'], 'next_step' => $row['step']];
            }
        }
        if ($last['state'] === 'pending' || ! in_array($last['step'], ['whatsapp.connect', 'whatsapp.disconnect'], true)) {
            return ['code' => 'operation_continuation_required', 'operation_id' => $last['operation_id'],
                'next_step' => $last['state'] === 'pending' ? $last['step'] : 'whatsapp.connect'];
        }

        return null;
    }

    /** Never persists: read-only members may recover a live view, not write the mirror. */
    public function recover(int $accountId, ?WhatsAppChannel $channel): ?WhatsAppChannel
    {
        $mirrorUpdatedAt = $channel?->updated_at;
        $projectedEffectId = $channel?->last_projected_effect_id;
        $projectedCredentialId = $channel?->last_projected_credential_effect_id;
        foreach ($this->journal->forResource($accountId, self::resource($accountId)) as $effect) {
            $step = $effect['step'];
            $payload = $effect['payload'];
            if ($effect['state'] === 'uncertain') {
                if (in_array($step, ['whatsapp.instance.create', 'whatsapp.instance.rotate'], true)) {
                    $channel?->forceFill(['instance_api_key_encrypted' => null, 'status' => 'error']);
                } elseif (in_array($step, ['whatsapp.disconnect', 'whatsapp.connect'], true)) {
                    $channel?->forceFill(['status' => 'error', 'connected_at' => null, 'pairing_started_at' => null]);
                }

                continue;
            }
            if ($effect['state'] !== 'confirmed' || $effect['receipt'] !== 'applied') {
                continue;
            }
            $credential = in_array($step, ['whatsapp.instance.create', 'whatsapp.instance.rotate'], true);
            $marker = $credential ? $projectedCredentialId : $projectedEffectId;
            if ($marker !== null && (int) $effect['id'] <= $marker) {
                continue;
            }
            if (isset($payload['instance_id'])) {
                $channel ??= new WhatsAppChannel(['status' => 'disconnected']);
                $channel->forceFill(['account_id' => $accountId, 'wzap_instance_id' => $payload['instance_id']]);
            }
            if ($channel === null) {
                continue;
            }
            if (isset($payload['instance_api_key'])) {
                $channel->forceFill(['instance_api_key_encrypted' => Crypt::encryptString($payload['instance_api_key'])]);
            }
            if (isset($payload['status']) && in_array($payload['status'], ['connected', 'pairing', 'disconnected', 'error'], true)
                && ($projectedEffectId !== null || $mirrorUpdatedAt === null || $mirrorUpdatedAt->lessThan($effect['confirmed_at']))) {
                $channel->status = $payload['status'];
                if ($channel->status === 'disconnected') {
                    $channel->forceFill(['connected_at' => null, 'pairing_started_at' => null]);
                }
            }
            $channel->projectEffect((int) $effect['id'], $credential);
        }

        return $channel;
    }

    /** @return array{channel: WhatsAppChannel, gateway: array, operation_id: string} */
    public function pair(int $accountId, int $actorId, ?WhatsAppChannel $channel, ?string $operationId = null, ?ExternalHttpBudget $budget = null): array
    {
        $rows = $this->journal->forResource($accountId, self::resource($accountId));
        $this->guardOperation($rows, $operationId);
        $channel = $this->recover($accountId, $channel);
        $id = $channel?->wzap_instance_id;
        $key = $channel?->instanceApiKey();
        $credentialEffectId = null;
        if ($operationId !== null) {
            $operationRows = array_values(array_filter($rows, fn (array $row): bool => $row['operation_id'] === $operationId));
            if ($operationRows[0]['step'] === 'whatsapp.disconnect') {
                $this->conflict($operationId, 'whatsapp.disconnect', 'operation_superseded');
            }
            foreach ($operationRows as $row) {
                if ($row['state'] === 'uncertain') {
                    $this->conflict($operationId, $row['step'], in_array($row['step'], ['whatsapp.connect', 'whatsapp.disconnect'], true) ? 'operation_reconciliation_required' : 'operation_manual_review_required');
                }
            }
        }
        if ($id === null) {
            $effect = $this->step($accountId, $actorId, 'whatsapp.instance.create', $operationId);
            try {
                $this->execute($effect, $budget, fn ($before, $result) => $this->wzap->createInstance((string) $accountId, 'opmoni-account-'.$accountId, $budget, $before, $result), function (array $result, int $http): array {
                    if (! is_string($result['instance_api_key'] ?? null) || $result['instance_api_key'] === '') {
                        throw new WzapException('Chave de instância ausente.');
                    }

                    return ['instance_id' => $result['id'], 'instance_api_key' => $result['instance_api_key'], 'http_status' => $http];
                }, true);
            } catch (WzapException $error) {
                if ($error->status() !== 409) {
                    throw $error;
                }
            }
            $operationId = $effect['operation_id'];
            if ($effect['receipt'] === 'not_applied') {
                $this->available($budget, $operationId, 'whatsapp.instance.rotate');
                try {
                    $found = $this->wzap->findInstance((string) $accountId, 'opmoni-account-'.$accountId, $budget);
                } catch (WzapException|HttpExceptionInterface) {
                    $this->conflict($operationId, 'whatsapp.instance.rotate');
                }
                if ($found === null) {
                    $this->conflict($operationId, 'whatsapp.instance.create', 'operation_manual_review_required');
                }
                $id = $found['id'];
            } else {
                $id = $effect['payload']['instance_id'];
                $key = $effect['payload']['instance_api_key'];
                $credentialEffectId = (int) $effect['id'];
            }
        }
        if ($key === null) {
            $effect = $this->step($accountId, $actorId, 'whatsapp.instance.rotate', $operationId);
            $this->execute($effect, $budget, fn ($before, $result) => $this->wzap->rotateInstanceKey($id, $budget, $before, $result), function (array $result, int $http) use ($id): array {
                if (! is_string($result['instance_api_key'] ?? null) || $result['instance_api_key'] === '') {
                    throw new WzapException('Chave de instância ausente.');
                }

                return ['instance_id' => $id, 'instance_api_key' => $result['instance_api_key'], 'http_status' => $http];
            });
            $operationId = $effect['operation_id'];
            $key = $effect['payload']['instance_api_key'];
            $credentialEffectId = (int) $effect['id'];
        }
        $created = $operationId === null ? null : $this->journal->find($accountId, $operationId, self::resource($accountId), 'whatsapp.instance.create');
        if (($created['receipt'] ?? null) !== 'applied' && is_string(config('whatsapp.wzap.webhook_url')) && config('whatsapp.wzap.webhook_url') !== '') {
            $effect = $this->step($accountId, $actorId, 'whatsapp.webhook', $operationId);
            $this->execute($effect, $budget, fn ($before, $result) => $this->wzap->configureWebhook($id, $budget, $before, $result), fn (array $result, int $http): array => ['instance_id' => $id, 'http_status' => $http]);
            $operationId = $effect['operation_id'];
        }
        $effect = $this->step($accountId, $actorId, 'whatsapp.connect', $operationId);
        $gateway = $this->execute($effect, $budget, fn ($before, $result) => $this->wzap->connect($id, $key, $budget, $before, $result), fn (array $result, int $http): array => ['instance_id' => $id, 'status' => $this->validStatus($result), 'http_status' => $http]);
        $channel ??= new WhatsAppChannel(['status' => 'disconnected']);
        $channel->forceFill(['account_id' => $accountId, 'wzap_instance_id' => $id, 'instance_api_key_encrypted' => Crypt::encryptString($key)]);
        if ($credentialEffectId !== null) {
            $channel->projectEffect($credentialEffectId, true);
        }
        if ($gateway === null) {
            $gateway = ['status' => $channel->status];
        } else {
            $channel->status = $effect['payload']['status'];
            $channel->projectEffect((int) $effect['id']);
        }

        return ['channel' => $channel, 'gateway' => $gateway, 'operation_id' => $effect['operation_id']];
    }

    public function disconnect(int $accountId, int $actorId, WhatsAppChannel $channel, ?string $operationId = null, ?ExternalHttpBudget $budget = null): array
    {
        $this->guardOperation($this->journal->forResource($accountId, self::resource($accountId)), $operationId);
        $channel = $this->recover($accountId, $channel);
        $effect = $this->step($accountId, $actorId, 'whatsapp.disconnect', $operationId);
        if ($effect['state'] === 'confirmed' && $effect['receipt'] === 'applied') {
            return ['channel' => $channel, 'gateway' => ['status' => $channel->status], 'operation_id' => $effect['operation_id']];
        }
        $this->execute($effect, $budget, fn ($before, $result) => $this->wzap->disconnect((string) $channel->wzap_instance_id, (string) $channel->instanceApiKey(), $budget, $before, $result), fn (array $result, int $http): array => ['instance_id' => $channel->wzap_instance_id, 'status' => 'disconnected', 'http_status' => $http]);
        $channel->forceFill(['status' => 'disconnected', 'connected_at' => null, 'pairing_started_at' => null]);
        $channel->projectEffect((int) $effect['id']);

        return ['channel' => $channel, 'gateway' => [], 'operation_id' => $effect['operation_id']];
    }

    public function continueOperation(int $accountId, int $actorId, ?WhatsAppChannel $channel, string $operationId, ?ExternalHttpBudget $budget = null): array
    {
        $rows = $this->journal->forResource($accountId, self::resource($accountId));
        $this->guardOperation($rows, $operationId);
        $operationRows = array_values(array_filter($rows, fn (array $row): bool => $row['operation_id'] === $operationId));
        if ($operationRows[0]['step'] === 'whatsapp.disconnect') {
            $channel = $this->recover($accountId, $channel);
            if ($channel === null) {
                $this->conflict($operationId, 'whatsapp.disconnect', 'operation_manual_review_required');
            }

            return $this->disconnect($accountId, $actorId, $channel, $operationId, $budget);
        }

        return $this->pair($accountId, $actorId, $channel, $operationId, $budget);
    }

    /** Explicit reconciliation never repeats a POST, including an uncertain connect. */
    public function reconcile(int $accountId, int $actorId, ?WhatsAppChannel $channel, string $operationId, ?ExternalHttpBudget $budget = null): array
    {
        $rows = $this->journal->forResource($accountId, self::resource($accountId));
        $this->guardOperation($rows, $operationId);
        $channel = $this->recover($accountId, $channel);
        foreach ($rows as $row) {
            if ($row['operation_id'] !== $operationId || $row['state'] !== 'uncertain') {
                continue;
            }
            if (! in_array($row['step'], ['whatsapp.connect', 'whatsapp.disconnect'], true) || $channel?->instanceApiKey() === null) {
                $this->conflict($operationId, $row['step'], 'operation_manual_review_required');
            }
            $this->available($budget, $operationId, 'whatsapp.status');
            try {
                $gateway = $this->wzap->status((string) $channel->wzap_instance_id, $channel->instanceApiKey(), $budget);
            } catch (WzapException|HttpExceptionInterface) {
                $this->conflict($operationId, $row['step'], 'operation_reconciliation_required');
            }
            $status = $gateway['status'] ?? null;
            if (! in_array($status, ['connected', 'disconnected', 'pairing', 'error'], true)) {
                $this->conflict($operationId, $row['step'], 'operation_manual_review_required');
            }
            $proven = $row['step'] === 'whatsapp.connect' ? in_array($status, ['pairing', 'connected'], true) : $status === 'disconnected';
            if (! $proven) {
                $this->conflict($operationId, $row['step'], 'operation_manual_review_required');
            }
            $row = $this->journal->confirm($row, 'applied', ['instance_id' => $channel->wzap_instance_id, 'status' => $status, 'http_status' => 200]);
            $channel->status = $row['payload']['status'];
            $channel->projectEffect((int) $row['id']);

            return ['channel' => $channel, 'gateway' => $gateway, 'operation_id' => $operationId];
        }
        $operationRows = array_values(array_filter($rows, fn (array $row): bool => $row['operation_id'] === $operationId));
        foreach ($operationRows as $row) {
            if ($row['state'] === 'pending') {
                $this->conflict($operationId, $row['step']);
            }
        }
        $last = $operationRows[array_key_last($operationRows)];
        if ($channel === null || $last['state'] !== 'confirmed' || $last['receipt'] !== 'applied'
            || ! in_array($last['step'], ['whatsapp.connect', 'whatsapp.disconnect'], true)) {
            $this->conflict($operationId, $last['step']);
        }

        return ['channel' => $channel, 'gateway' => ['status' => $channel->status], 'operation_id' => $operationId];
    }

    private function step(int $accountId, int $actorId, string $step, ?string $operationId): array
    {
        return $this->journal->begin($accountId, $actorId, self::resource($accountId), $step, $operationId);
    }

    /** Receipts are committed before another budget/lease check or ordinary mirror write. */
    private function execute(array &$effect, ?ExternalHttpBudget $budget, Closure $send, Closure $payload, bool $allowConflict = false): mixed
    {
        if ($effect['state'] === 'confirmed') {
            if ($effect['receipt'] !== 'applied' && ! $allowConflict) {
                $this->conflict($effect['operation_id'], $effect['step'], 'operation_manual_review_required');
            }

            return null;
        }
        if ($effect['state'] !== 'pending') {
            $this->conflict($effect['operation_id'], $effect['step'], in_array($effect['step'], ['whatsapp.connect', 'whatsapp.disconnect'], true) ? 'operation_reconciliation_required' : 'operation_manual_review_required');
        }
        $this->available($budget, $effect['operation_id'], $effect['step']);
        try {
            return $send(function () use (&$effect): void {
                $effect = $this->journal->arm($effect);
            }, function (array $result, int $http) use (&$effect, $payload): void {
                $effect = $this->journal->confirm($effect, 'applied', $payload($result, $http));
            });
        } catch (WzapException $error) {
            if ($allowConflict && $error->status() === 409 && $effect['state'] === 'uncertain') {
                $effect = $this->journal->confirm($effect, 'not_applied', ['http_status' => 409]);

                return null;
            }
            $this->conflict($effect['operation_id'], $effect['step'], $effect['state'] === 'pending' ? 'operation_continuation_required' : (in_array($effect['step'], ['whatsapp.connect', 'whatsapp.disconnect'], true) ? 'operation_reconciliation_required' : 'operation_manual_review_required'));
        } catch (HttpExceptionInterface $error) {
            if ($error->getStatusCode() !== 503) {
                throw $error;
            }
            $this->conflict($effect['operation_id'], $effect['step'], $effect['state'] === 'pending' ? 'operation_continuation_required' : 'operation_reconciliation_required');
        } catch (\RuntimeException $error) {
            if ($effect['state'] === 'pending') {
                throw $error;
            }
            $this->conflict($effect['operation_id'], $effect['step'], 'operation_manual_review_required');
        }
    }

    private function guardOperation(array $rows, ?string $operationId): void
    {
        $last = $rows === [] ? null : $rows[array_key_last($rows)];
        if ($operationId !== null) {
            if (! array_filter($rows, fn (array $row): bool => $row['operation_id'] === $operationId)) {
                throw new HttpResponseException(response()->json(['code' => 'not_found', 'message' => 'Operação não encontrada.'], 404));
            }
            if ($last['operation_id'] !== $operationId) {
                $this->conflict($operationId, $last['step'], 'operation_superseded');
            }
        }
        foreach ($rows as $row) {
            if ($row['state'] === 'uncertain' && ($operationId === null || $row['operation_id'] !== $operationId)) {
                $this->conflict($row['operation_id'], $row['step'], in_array($row['step'], ['whatsapp.connect', 'whatsapp.disconnect'], true) ? 'operation_reconciliation_required' : 'operation_manual_review_required');
            }
        }
        if ($operationId === null && $last !== null && ($last['state'] === 'pending' || ! in_array($last['step'], ['whatsapp.connect', 'whatsapp.disconnect'], true))) {
            $this->conflict($last['operation_id'], $last['state'] === 'pending' ? $last['step'] : 'whatsapp.connect');
        }
    }

    private function available(?ExternalHttpBudget $budget, string $operationId, string $step): void
    {
        try {
            $budget?->assertAvailable();
        } catch (HttpExceptionInterface $error) {
            $this->conflict($operationId, $step);
        }
    }

    private function validStatus(array $result): string
    {
        $status = $result['status'] ?? null;
        if (! in_array($status, ['connected', 'disconnected', 'pairing', 'error'], true)) {
            throw new WzapException('Estado de conexão inválido.');
        }

        return $status;
    }

    private function conflict(string $operationId, string $step, string $code = 'operation_continuation_required'): never
    {
        throw new HttpResponseException(response()->json(['code' => $code, 'message' => match ($code) {
            'operation_manual_review_required' => 'Resultado externo incerto. Revisão manual necessária; nenhuma operação foi reenviada.',
            'operation_reconciliation_required' => 'Consulte o estado da operação antes de continuar; nenhuma operação foi reenviada.',
            'operation_superseded' => 'Uma operação mais recente substituiu esta operação.',
            default => 'A etapa foi preservada. Continue a operação para concluir.',
        }, 'operation_id' => $operationId, 'next_step' => $step], 409, ['Cache-Control' => 'no-store']));
    }
}
