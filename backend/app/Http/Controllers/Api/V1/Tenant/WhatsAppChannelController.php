<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\WhatsAppChannel;
use App\Services\SupportAudit;
use App\Services\WhatsApp\WhatsAppChannelEffects;
use App\Services\WhatsApp\WhatsAppFailureReason;
use App\Services\WhatsApp\WzapClient;
use App\Services\WhatsApp\WzapException;
use App\Support\CurrentAccount;
use App\Support\ExternalHttpBudget;
use App\Tenant\CurrentTenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * O Canal WhatsApp da conta corrente: um só número por Account, pareado por
 * QR contra a instância wzap cujo `external_ref` é o id da conta.
 *
 * As rotas não endereçam linha nenhuma — a conta corrente é o endereço. O
 * `404` da leitura é o de "esta conta não tem canal", e por construção é
 * também o de "o canal é de outra conta": a busca é por `account_id`
 * explícito, nunca por id vindo da rota. O feature flag `whatsapp.enabled`
 * desliga o Canal inteiro — com o flag desligado toda rota responde 404,
 * como no Disparo, porque sem wzap configurado a feature é inerte.
 */
class WhatsAppChannelController extends Controller
{
    private const WZAP_STATUS_MAP = [
        'disconnected' => WhatsAppChannel::STATUS_DISCONNECTED,
        'pairing' => WhatsAppChannel::STATUS_PAIRING,
        'connected' => WhatsAppChannel::STATUS_CONNECTED,
        'error' => WhatsAppChannel::STATUS_ERROR,
    ];

    public function __construct(private WzapClient $wzap, private WhatsAppChannelEffects $effects) {}

    /**
     * O estado do canal hoje, relido no wzap e espelhado na linha.
     */
    public function show(): JsonResponse
    {
        abort_unless((bool) config('whatsapp.enabled'), 404);

        Gate::authorize('viewAny', WhatsAppChannel::class);

        $channel = $this->effects->recover((int) $this->currentAccount()->getKey(), $this->currentChannel());
        $notice = $this->effects->operationNotice((int) $this->currentAccount()->getKey());
        if ($channel === null && $notice !== null) {
            $channel = new WhatsAppChannel(['status' => 'disconnected']);

            return response()->json(['data' => $this->channelPayload($channel) + ['operation' => $notice]]);
        }
        abort_if($channel === null, 404);

        $this->saveChannel($channel, []);
        $gateway = $notice === null ? $this->refreshFromGateway($channel) : [];

        return response()->json(['data' => $this->channelPayload($channel, $gateway) + ['operation' => $notice]]);
    }

    /**
     * Inicia (ou retoma) o pareamento. A instância é criada sob demanda e a
     * apikey de instância só vem na criação — quando o canal não a guarda
     * (instância reusada ou chave perdida), o caminho é rotacionar, porque a
     * chave antiga é impossível de recuperar.
     */
    public function startPairing(Request $request): JsonResponse
    {
        abort_unless((bool) config('whatsapp.enabled'), 404);

        Gate::authorize('create', WhatsAppChannel::class);

        $result = $this->effects->pair(
            (int) $this->currentAccount()->getKey(), (int) $request->user()->getAuthIdentifier(),
            $this->currentChannel(), null, ExternalHttpBudget::fromRequest($request),
        );

        return $this->operationResponse($request, $result, 'pairing');
    }

    /**
     * O polling do pareamento: a tela lê o estado da instância até o wzap
     * reportar `connected`. `404` quando não há pareamento em curso — um
     * `200` com `disconnected` faria a tela esperar por um QR que ninguém
     * pediu.
     */
    public function pairingStatus(): JsonResponse
    {
        abort_unless((bool) config('whatsapp.enabled'), 404);

        Gate::authorize('viewAny', WhatsAppChannel::class);

        $channel = $this->effects->recover((int) $this->currentAccount()->getKey(), $this->currentChannel());
        abort_if($channel === null || $channel->status !== WhatsAppChannel::STATUS_PAIRING, 404);

        try {
            $status = $this->wzap->status(
                (string) $channel->wzap_instance_id,
                (string) $channel->instanceApiKey(),
                ExternalHttpBudget::fromRequest(request()),
            );
        } catch (WzapException $exception) {
            return response()->json(['message' => $this->markError($channel, $exception)], 502);
        }

        $this->mirrorGatewayStatus($channel, $status);

        return response()->json(['data' => $this->channelPayload($channel, $this->attachCurrentQr($channel, $status))]);
    }

    /**
     * Encerra a sessão no wzap e volta o canal para `disconnected`. Sem
     * sessão o Disparo para de aceitar envio — quem decide se o canal existe
     * de novo é um novo pareamento, não esta rota.
     */
    public function destroy(Request $request): JsonResponse
    {
        abort_unless((bool) config('whatsapp.enabled'), 404);

        Gate::authorize('delete', WhatsAppChannel::class);

        $channel = $this->effects->recover((int) $this->currentAccount()->getKey(), $this->currentChannel());
        abort_if($channel === null, 404);

        $result = $this->effects->disconnect(
            (int) $this->currentAccount()->getKey(), (int) $request->user()->getAuthIdentifier(),
            $channel, null, ExternalHttpBudget::fromRequest($request),
        );

        return $this->operationResponse($request, $result, 'disconnect');
    }

    public function continueOperation(Request $request, string $operation): JsonResponse
    {
        abort_unless((bool) config('whatsapp.enabled'), 404);
        Gate::authorize('create', WhatsAppChannel::class);
        $result = $this->effects->continueOperation(
            (int) $this->currentAccount()->getKey(), (int) $request->user()->getAuthIdentifier(),
            $this->currentChannel(), $operation, ExternalHttpBudget::fromRequest($request),
        );

        return $this->operationResponse($request, $result, 'pairing');
    }

    public function reconcileOperation(Request $request, string $operation): JsonResponse
    {
        abort_unless((bool) config('whatsapp.enabled'), 404);
        Gate::authorize('create', WhatsAppChannel::class);
        $result = $this->effects->reconcile(
            (int) $this->currentAccount()->getKey(), (int) $request->user()->getAuthIdentifier(),
            $this->currentChannel(), $operation, ExternalHttpBudget::fromRequest($request),
        );

        return $this->operationResponse($request, $result, 'reconcile');
    }

    private function operationResponse(Request $request, array $result, string $action): JsonResponse
    {
        $channel = $result['channel'];
        $this->mirrorPairingResult($channel, ['status' => $channel->status] + $result['gateway']);
        SupportAudit::logWrite($request, 'whatsapp_channels', $action, $channel->getKey(), ['status' => $channel->status]);

        return response()->json(['data' => $this->channelPayload($channel, $result['gateway']), 'operation_id' => $result['operation_id']]);
    }

    /** O canal da conta corrente pelo account_id verificado. */
    private function currentChannel(): ?WhatsAppChannel
    {
        $accountId = resolve(CurrentTenant::class)->accountId;

        return $accountId === null ? null : WhatsAppChannel::forAccount($accountId);
    }

    private function currentAccount(): Account
    {
        return Account::findOrFail(resolve(CurrentTenant::class)->accountId);
    }

    /**
     * A leitura de status é barata para o operador e cara para o wzap — mas
     * é a única fonte do número pareado e do `last_error`, e o espelho local
     * precisa dela para sair de `pairing` sem a tela abrir o polling.
     */
    /**
     * @return array<string, mixed>
     */
    private function refreshFromGateway(WhatsAppChannel $channel): array
    {
        if ($channel->wzap_instance_id === null || $channel->instanceApiKey() === null) {
            return [];
        }

        try {
            $status = $this->wzap->status($channel->wzap_instance_id, $channel->instanceApiKey(), ExternalHttpBudget::fromRequest(request()));
        } catch (WzapException) {
            // A leitura não derruba a tela: a última foto conhecida é o que
            // o espelho já tem, e o `last_error` não é desta rota.
            return [];
        }

        $this->mirrorGatewayStatus($channel, $status);

        return $this->attachCurrentQr($channel, $status);
    }

    /**
     * O status do wzap não carrega o QR. Enquanto a instância está em
     * pareamento, a tela precisa do código corrente (`GET …/qr`) para
     * desenhar o modal — inclusive depois de recarregar a página.
     *
     * Falha nessa leitura não derruba o status: o pareamento segue e a
     * tela mostra que ainda não há código.
     *
     * @param  array<string, mixed>  $status
     * @return array<string, mixed>
     */
    private function attachCurrentQr(WhatsAppChannel $channel, array $status): array
    {
        if (! Gate::allows('create', WhatsAppChannel::class)) {
            return $status;
        }

        if (($status['status'] ?? null) !== 'pairing') {
            return $status;
        }

        if ($channel->wzap_instance_id === null || $channel->instanceApiKey() === null) {
            return $status;
        }

        try {
            $qr = $this->wzap->qr((string) $channel->wzap_instance_id, (string) $channel->instanceApiKey(), ExternalHttpBudget::fromRequest(request()));
        } catch (WzapException|ConnectionException) {
            return $status;
        }

        $status['qr_code'] = $qr['qr_code'] ?? null;
        $status['qr_expires_at'] = $qr['qr_expires_at'] ?? null;

        return $status;
    }

    /**
     * @param  array<string, mixed>  $pairing
     */
    private function mirrorPairingResult(WhatsAppChannel $channel, array $pairing): void
    {
        $mirror = self::WZAP_STATUS_MAP[$pairing['status'] ?? ''] ?? WhatsAppChannel::STATUS_ERROR;

        $attributes = ['status' => $mirror];

        if ($mirror === WhatsAppChannel::STATUS_CONNECTED) {
            $attributes['connected_at'] = $channel->connected_at ?? now();
            $attributes['pairing_started_at'] = null;
            $attributes['last_error'] = null;
        } elseif ($mirror === WhatsAppChannel::STATUS_PAIRING) {
            $attributes['pairing_started_at'] = now();
            $attributes['last_error'] = null;
        } elseif ($mirror === WhatsAppChannel::STATUS_DISCONNECTED) {
            $attributes['connected_at'] = null;
            $attributes['pairing_started_at'] = null;
        }

        if ($mirror === WhatsAppChannel::STATUS_ERROR) {
            $attributes['last_error'] = $this->presentLastError($pairing['last_error'] ?? null, $channel->instanceApiKey());
        }

        $this->saveChannel($channel, $attributes);
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function mirrorGatewayStatus(WhatsAppChannel $channel, array $status): void
    {
        $mirror = self::WZAP_STATUS_MAP[$status['status'] ?? ''] ?? WhatsAppChannel::STATUS_ERROR;

        $attributes = ['status' => $mirror];

        if ($mirror === WhatsAppChannel::STATUS_CONNECTED) {
            $attributes['connected_at'] = $channel->connected_at ?? now();
            $attributes['pairing_started_at'] = null;
            $attributes['last_error'] = null;
        } elseif ($mirror === WhatsAppChannel::STATUS_DISCONNECTED) {
            $attributes['connected_at'] = null;
            $attributes['pairing_started_at'] = null;
        }

        if ($mirror === WhatsAppChannel::STATUS_ERROR) {
            $attributes['last_error'] = $this->presentLastError($status['last_error'] ?? null, $channel->instanceApiKey());
        }

        $this->saveChannel($channel, $attributes);
    }

    /**
     * Grava o espelho do canal, absorvendo a corrida de dois POSTs paralelos.
     *
     * Dois pedidos que passam `forAccount` antes de qualquer linha existir
     * carregam dois `new WhatsAppChannel` distintos; o segundo `save()`
     * bate a unique de `account_id`. Em vez de um 500, o canal é re-lido
     * da linha que venceu a corrida e os atributos são re-aplicados — o
     * pedido responde com o espelho atualizado, como se tivesse chegado
     * depois do primeiro.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function saveChannel(WhatsAppChannel $channel, array $attributes): void
    {
        $channel->forceFill($attributes);

        if (! $this->canPersistMirror()) {
            return;
        }

        try {
            $channel->save();
        } catch (UniqueConstraintViolationException) {
            $existing = WhatsAppChannel::forAccount($channel->account_id);
            abort_if($existing === null, 409, 'O canal da conta já existe.');
            $existing->forceFill(array_intersect_key($channel->getAttributes(), array_flip(['wzap_instance_id', 'instance_api_key_encrypted', 'status', 'pairing_started_at', 'connected_at', 'last_error', 'last_projected_effect_id', 'last_projected_credential_effect_id'])))->save();
            // O caller passa a operar sobre a linha persistida, não no clone
            // que perdeu a corrida.
            $channel->setRawAttributes($existing->getAttributes(), true);
            $channel->exists = true;
        }
    }

    private function canPersistMirror(): bool
    {
        $context = request()->attributes->get(CurrentAccount::class);

        return ! $context instanceof CurrentAccount || in_array($context->membership->role, ['admin', 'operador'], true);
    }

    /**
     * Falha do gateway não derruba o canal: o estado `error` é o que a spec
     * promete à tela, e um novo pareamento é sempre possível depois.
     *
     * O motivo gravado é só o da `WzapException` — `code` + `message` do
     * envelope, nunca corpo bruto nem apikey. Uma falha antes de qualquer
     * instância existir não persiste linha nenhuma: sem `wzap_instance_id`
     * não há canal a espelhar, e gravar criaria uma linha fantasma que
     * consome a unique de `account_id` sem nunca ter visto o gateway.
     */
    private function markError(WhatsAppChannel $channel, WzapException $exception): string
    {
        $message = WhatsAppFailureReason::sanitizar($exception->getMessage(), $channel->instanceApiKey());
        $channel->status = WhatsAppChannel::STATUS_ERROR;

        if (! $channel->exists) {
            return $message;
        }

        $this->saveChannel($channel, [
            'status' => WhatsAppChannel::STATUS_ERROR,
            'last_error' => $message,
        ]);

        return $message;
    }

    /**
     * O contrato atual traz `last_error.message`; o legado traz texto ou
     * string vazia. A tela só mostra a linha quando há motivo legível.
     */
    private function presentLastError(mixed $error, ?string $instanceApiKey): ?string
    {
        if (is_array($error)) {
            $error = $error['message'] ?? null;
        }

        if (! is_string($error)) {
            return null;
        }

        $error = trim($error);

        return $error === '' ? null : WhatsAppFailureReason::sanitizar($error, $instanceApiKey);
    }

    /**
     * O que a tela lê. O QR cru (`qr_code`, `qr_expires_at`) só entra quando
     * a resposta do wzap o carrega; a apikey da instância e a cifra nunca.
     *
     * @param  array<string, mixed>  $gateway
     * @return array<string, mixed>
     */
    private function channelPayload(WhatsAppChannel $channel, array $gateway = []): array
    {
        $canPair = Gate::allows('create', WhatsAppChannel::class);
        $qrCode = $gateway['qr_code'] ?? null;
        $qrExpiresAt = $gateway['qr_expires_at'] ?? null;
        $parsedExpiry = is_string($qrExpiresAt) ? date_parse($qrExpiresAt) : null;
        $validQr = $canPair
            && $channel->status === WhatsAppChannel::STATUS_PAIRING
            && is_string($qrCode) && trim($qrCode) !== ''
            && is_string($qrExpiresAt)
            && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $qrExpiresAt)
            && $parsedExpiry['error_count'] === 0
            && $parsedExpiry['warning_count'] === 0;

        return [
            'status' => $channel->status,
            'qr_code' => $validQr ? $qrCode : null,
            'qr_expires_at' => $validQr ? $qrExpiresAt : null,
            'whatsapp_jid' => $gateway['whatsapp_jid'] ?? null,
            'last_error' => $this->presentLastError($gateway['last_error'] ?? $channel->last_error, $channel->instanceApiKey()),
            'connected_at' => $channel->connected_at?->toIso8601String(),
            'pairing_started_at' => $channel->pairing_started_at?->toIso8601String(),
        ];
    }
}
