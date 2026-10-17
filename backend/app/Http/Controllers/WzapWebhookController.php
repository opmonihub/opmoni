<?php

namespace App\Http\Controllers;

use App\Models\WhatsAppChannel;
use App\Models\WhatsAppDispatch;
use App\Models\WhatsAppWebhookEvent;
use App\Services\WhatsApp\WhatsAppFailureReason;
use App\Tenant\CurrentTenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * O webhook de recibos do wzap: `POST /api/v1/webhooks/wzap`, pública —
 * sem Sanctum, sem CSRF — autenticada pelo header `apikey` que o gateway
 * envia em cada entrega.
 *
 * **Resolução de canal antes de qualquer tenancy.** O `apikey` é a
 * credencial da instância: cada canal a guarda cifrada, então o lookup é
 * um scan de canais descriptografando e comparando com `hash_equals`.
 * Nenhuma query de dispatch acontece antes da chave conferir, e nenhum
 * `account_id` do corpo é lido — a conta sai da linha do canal.
 *
 * **Dedupe por `event_id`.** O wzap entrega at-least-once: o insert na
 * tabela `whatsapp_webhook_events` é o dedupe, e a unique de
 * (`whatsapp_channel_id`, `event_id`) transforma reentrega em no-op 200.
 * Evento de rastreio com algum ID ainda sem disparo **não é** deduplicado
 * — responde 503 e a linha do dedupe não é gravada, porque o wzap
 * retenta entregas não-2xx com backoff e a janela que ele cobre (job
 * ainda não gravou o `message_id` do 202) é exatamente a que um dedupe
 * prematuro transformaria em perda definitiva do recibo.
 *
 * **Corpo nunca persiste.** O envelope traz `event` cru (payload
 * whatsmeow, que pode carregar texto de mensagem) — lido, nunca gravado.
 * Só `event_id`, `type` e os campos mínimos do payload tocam o banco.
 */
class WzapWebhookController extends Controller
{
    /**
     * O mapa do barramento wzap para o Rastreio: `message.status` reporta
     * `sent`/`failed`; `receipt` reporta `delivered`/`read`/`played`.
     * Qualquer coisa fora é ignorada com 200 — o dedupe já contou.
     */
    private const DISPATCH_STATUS_MAP = [
        'sent' => WhatsAppDispatch::STATUS_SENT,
        'delivered' => WhatsAppDispatch::STATUS_DELIVERED,
        'read' => WhatsAppDispatch::STATUS_READ,
        'played' => WhatsAppDispatch::STATUS_READ,
    ];

    private const CHANNEL_STATUS_MAP = [
        'disconnected' => WhatsAppChannel::STATUS_DISCONNECTED,
        'pairing' => WhatsAppChannel::STATUS_PAIRING,
        'connected' => WhatsAppChannel::STATUS_CONNECTED,
        'error' => WhatsAppChannel::STATUS_ERROR,
    ];

    /**
     * Sentinela de retorno dos handlers de rastreio: o evento era
     * bem-formado e algum ID de disparo não casou. Difere do `null` (payload
     * ignorável) porque a linha do disparo pode simplesmente ainda não
     * existir — o wzap emite o `message.status` assim que o envio sai, e
     * a entrega pode chegar antes do job gravar o `message_id` do 202.
     */
    private const SEM_MATCH = -1;

    public function store(Request $request): JsonResponse
    {
        $channel = $this->resolveChannel($request->header('apikey'));
        abort_if($channel === null, 401);

        return resolve(CurrentTenant::class)->run((int) $channel->account_id, function () use ($request, $channel): JsonResponse {
            $envelope = $request->validate([
                'event_id' => ['required', 'string', 'max:120'],
                'type' => ['required', 'string', 'max:40'],
                'payload' => ['present', 'array'],
            ]);

            return DB::transaction(function () use ($channel, $envelope): JsonResponse {
                // Dedupe, alteração e vínculo confirmam juntos. O savepoint do
                // insert permite tratar a unique sem abortar a transação no PostgreSQL.
                try {
                    $evento = (new WhatsAppWebhookEvent)->forceFill([
                        'account_id' => $channel->account_id,
                        'whatsapp_channel_id' => $channel->getKey(),
                        'event_id' => (string) $envelope['event_id'],
                    ]);
                    DB::transaction(fn (): bool => $evento->save());
                } catch (UniqueConstraintViolationException) {
                    return response()->json(['data' => ['received' => true]]);
                }

                $dispatchId = match ($envelope['type']) {
                    'receipt' => $this->aplicarRecibo($channel, $envelope['payload']),
                    'message.status' => $this->aplicarStatusDeMensagem($channel, $envelope['payload']),
                    'connection' => $this->espelharConexao($channel, $envelope['payload']),
                    default => null,
                };

                // Evento de rastreio com ID pendente: o dedupe não
                // pode condenar a reentrega — a linha sai e a resposta não-2xx
                // pede ao wzap para tentar de novo, que é o que o worker dele faz
                // com backoff até o job alcançar.
                if ($dispatchId === self::SEM_MATCH) {
                    $evento->delete();

                    return response()->json(['data' => ['received' => true]], 503);
                }

                if ($dispatchId !== null) {
                    $evento->forceFill(['whatsapp_dispatch_id' => $dispatchId])->save();
                }

                return response()->json(['data' => ['received' => true]]);
            });
        });
    }

    /**
     * O recibo agrega vários `message_ids` de uma vez — cada um é um
     * wamid do WhatsApp, e a linha casa pelo `whatsapp_id` primeiro; se
     * não achou, tenta pelo `message_id` interno do wzap. A chave dupla
     * cobre a janela em que o `message.status` que traz o wamid ainda
     * não chegou (job lento) — sem ela o recibo de `delivered` nunca
     * amarraria a linha e o Rastreio pararia em `sent`.
     *
     * @param  array<string, mixed>  $payload
     * @return int|null o id do disparo que o evento tocou, quando houve um
     */
    private function aplicarRecibo(WhatsAppChannel $channel, array $payload): ?int
    {
        $statusInformado = $payload['status'] ?? null;
        $status = is_string($statusInformado) ? (self::DISPATCH_STATUS_MAP[$statusInformado] ?? null) : null;
        $ids = $payload['message_ids'] ?? [];

        if ($status === null || ! is_array($ids)) {
            return null;
        }

        $ultimo = null;
        $pendente = false;
        $matches = [];

        foreach ($ids as $whatsappId) {
            if (! is_string($whatsappId) || $whatsappId === '') {
                continue;
            }

            $dispatch = $this->dispatchPeloWhatsappId($channel, $whatsappId)
                ?? $this->dispatchPeloMessageId($channel, $whatsappId);
            if ($dispatch !== null) {
                $matches[] = ['id' => $dispatch->getKey(), 'whatsapp_id' => $whatsappId];
            } else {
                $pendente = true;
            }
        }

        // A descoberta pode ficar stale. Só a releitura sob lock decide o
        // avanço; batches adquirem todos os locks por PK, nunca por alias
        // ou pela ordem dos IDs do payload, para não inverter os locks.
        $dispatches = WhatsAppDispatch::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $channel->account_id)
            ->whereIn('id', array_column($matches, 'id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($matches as $match) {
            $dispatch = $dispatches->get($match['id']);
            if ($dispatch === null) {
                $pendente = true;

                continue;
            }

            // O recibo é um wamid: quando o match foi pelo id interno
            // a linha ainda não o conhece, e amarrá-lo aqui é o que
            // mantém os próximos recibos no caminho primário.
            if ($dispatch->whatsapp_id === null) {
                $dispatch->forceFill(['whatsapp_id' => $match['whatsapp_id']])->save();
            }
            $this->avancar($dispatch, $status);
            $ultimo = $dispatch->getKey();
        }

        return $pendente ? self::SEM_MATCH : ($ultimo ?? self::SEM_MATCH);
    }

    /**
     * O `message.status` é o evento que amarra o `whatsapp_id`: a linha
     * nasceu com o `message_id` interno do wzap — o mesmo UUID que o 202
     * do `sendMedia` devolve e que este payload carrega — e só este
     * evento traz o wamid que os recibos depois referenciam.
     *
     * @param  array<string, mixed>  $payload
     * @return int|null o id do disparo que o evento tocou, quando houve um
     */
    private function aplicarStatusDeMensagem(WhatsAppChannel $channel, array $payload): ?int
    {
        $messageId = $payload['message_id'] ?? null;
        if (! is_string($messageId) || $messageId === '') {
            return null;
        }

        $descoberto = $this->dispatchPeloMessageId($channel, $messageId);
        $dispatch = $descoberto === null ? null : WhatsAppDispatch::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $channel->account_id)
            ->whereKey($descoberto->getKey())
            ->lockForUpdate()
            ->first();

        if ($dispatch === null) {
            return self::SEM_MATCH;
        }

        $whatsappId = $payload['whatsapp_id'] ?? null;
        if (is_string($whatsappId) && $whatsappId !== '' && $dispatch->whatsapp_id === null) {
            $dispatch->forceFill(['whatsapp_id' => $whatsappId])->save();
        }

        $status = is_string($payload['status'] ?? null) ? $payload['status'] : null;
        if ($status === 'failed') {
            $reason = WhatsAppFailureReason::sanitizar(
                is_string($payload['error'] ?? null) ? $payload['error'] : null,
                $channel->instanceApiKey(),
            );
            $this->falhar($dispatch, $reason);
        } elseif ($status !== null && isset(self::DISPATCH_STATUS_MAP[$status])) {
            $this->avancar($dispatch, self::DISPATCH_STATUS_MAP[$status]);
        }

        return $dispatch->getKey();
    }

    /**
     * O espelho do canal anda pelo `connection` — bônus do design,
     * barato porque o payload é só `status`/`reason`.
     *
     * @param  array<string, mixed>  $payload
     * @return int|null sempre `null`: evento de conexão não toca disparo
     */
    private function espelharConexao(WhatsAppChannel $channel, array $payload): ?int
    {
        $statusInformado = $payload['status'] ?? null;
        $status = is_string($statusInformado) ? (self::CHANNEL_STATUS_MAP[$statusInformado] ?? null) : null;
        if ($status === null) {
            return null;
        }

        $fill = ['status' => $status];

        if ($status === WhatsAppChannel::STATUS_CONNECTED) {
            $fill['connected_at'] = now();
            $fill['last_error'] = null;
        } elseif ($status === WhatsAppChannel::STATUS_ERROR) {
            $fill['last_error'] = WhatsAppFailureReason::sanitizar(
                is_string($payload['reason'] ?? null) ? $payload['reason'] : null,
                $channel->instanceApiKey(),
            );
        }

        $channel->forceFill($fill)->save();

        return null;
    }

    /**
     * O avanço é monotônico: recibo não rebaixa terminal nem volta atrás.
     * `sent` só confirma o que o job já gravou — e um `delivered` depois
     * de `read` (out-of-order do gateway) não degrada a linha.
     */
    private function avancar(WhatsAppDispatch $dispatch, string $novo): void
    {
        $ordem = [
            WhatsAppDispatch::STATUS_QUEUED => 0,
            WhatsAppDispatch::STATUS_SENT => 1,
            WhatsAppDispatch::STATUS_DELIVERED => 2,
            WhatsAppDispatch::STATUS_READ => 3,
            WhatsAppDispatch::STATUS_FAILED => 4,
        ];

        if (($ordem[$dispatch->status] ?? -1) >= ($ordem[$novo] ?? 0)) {
            return;
        }

        $dispatch->forceFill(['status' => $novo])->save();
    }

    /**
     * Falha terminal é o único caminho que grava `failure_reason`, e só
     * se a linha ainda não confirmou entrega — um `failed` tardio depois
     * de `delivered` é ruído do gateway, não estado do Rastreio.
     */
    private function falhar(WhatsAppDispatch $dispatch, string $reason): void
    {
        if (in_array($dispatch->status, [
            WhatsAppDispatch::STATUS_DELIVERED,
            WhatsAppDispatch::STATUS_READ,
            WhatsAppDispatch::STATUS_FAILED,
        ], true)) {
            return;
        }

        $dispatch->forceFill([
            'status' => WhatsAppDispatch::STATUS_FAILED,
            'failure_reason' => $reason,
        ])->save();

        // O motivo sanitizado já está no `failure_reason` da linha para a
        // UI; no log ele ficaria carregando texto arbitrário do gateway
        // (que pode ecoar base64 de mídia), então aqui só a identidade.
        Log::info('Recibo wzap marcou disparo como falha', [
            'account_id' => $dispatch->account_id,
            'dispatch_id' => $dispatch->getKey(),
        ]);
    }

    /**
     * A linha do disparo, pelo wamid que o recibo carrega — sempre
     * dentro da conta do canal resolvido, nunca pelo escopo global.
     */
    private function dispatchPeloWhatsappId(WhatsAppChannel $channel, string $whatsappId): ?WhatsAppDispatch
    {
        return WhatsAppDispatch::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $channel->account_id)
            ->where('whatsapp_id', $whatsappId)
            ->first();
    }

    /**
     * A linha pelo `message_id` interno do wzap — a chave que o job grava
     * com o 202 do `sendMedia` e que o `message.status` devolve.
     */
    private function dispatchPeloMessageId(WhatsAppChannel $channel, string $messageId): ?WhatsAppDispatch
    {
        return WhatsAppDispatch::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $channel->account_id)
            ->where('message_id', $messageId)
            ->first();
    }

    /**
     * O canal pela apikey da instância: cada linha guarda a chave cifrada,
     * então o match é um scan curto descriptografando e comparando com
     * `hash_equals`. Erro de descriptografia (chave rotacionada fora do
     * app, coluna corrompida) vale "não é este canal", nunca 500.
     */
    private function resolveChannel(?string $apikey): ?WhatsAppChannel
    {
        if (! is_string($apikey) || $apikey === '') {
            return null;
        }

        foreach (WhatsAppChannel::query()->withoutGlobalScope('account')->cursor() as $channel) {
            try {
                $guardada = $channel->instanceApiKey();
            } catch (\Throwable) {
                continue;
            }

            if ($guardada !== null && hash_equals($guardada, $apikey)) {
                return $channel;
            }
        }

        return null;
    }
}
