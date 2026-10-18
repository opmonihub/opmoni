<?php

namespace App\Jobs;

use App\Models\WhatsAppChannel;
use App\Models\WhatsAppDispatch;
use App\Services\SerproException;
use App\Services\SerproPgdasDocumentReader;
use App\Services\WhatsApp\WhatsAppFailureReason;
use App\Services\WhatsApp\WzapClient;
use App\Services\WhatsApp\WzapException;
use App\Tenant\CurrentTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * O envio de um Disparo: lê os bytes do artefato pelo mesmo serviço do
 * download PGDAS — nenhum PDF é armazenado por causa do WhatsApp — e
 * manda multipart ao wzap, que aplica a `Idempotency-Key` da linha.
 *
 * **A conta viaja no job.** No worker o `CurrentTenant` está vazio e o
 * escopo global não filtra nada: a linha se carrega pelo par
 * (`id`, `account_id`), o canal pelo `forAccount` e o leitor recebe o
 * `account_id` explícito — o singleton é restaurado em `finally`.
 *
 * **Transições.** `queued` → `sent` quando o wzap aceita (o `message_id`
 * é o que o webhook de recibo casa depois). `queued` → `failed` quando o
 * envio falha ou fica incerto — o `failure_reason` é sempre sanitizado.
 * `send_attempted_at` é gravado atomicamente antes do HTTP: só falhas
 * anteriores a essa fronteira podem gerar chave nova sem ciência.
 * Uma linha terminal que o worker reencontra é devolvida, nunca
 * reenviada.
 */
final class SendWhatsAppDocumentJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    /**
     * Uma tentativa: a idempotência do gateway expira. Depois da fronteira
     * persistida de envio, reentrega nunca chama sendMedia novamente; um
     * resultado incerto exige ciência explícita para um novo envio.
     */
    public int $tries = 1;

    public function __construct(
        public int $accountId,
        public int $dispatchId,
    ) {}

    public function handle(): void
    {
        $tenant = resolve(CurrentTenant::class);
        $previous = $tenant->accountId;
        $tenant->accountId = $this->accountId;

        try {
            $this->work();
        } finally {
            $tenant->accountId = $previous;
        }
    }

    private function work(): void
    {
        $dispatch = WhatsAppDispatch::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $this->accountId)
            ->find($this->dispatchId);

        // Linha ausente (cliente apagado em cascata, conta removida) ou já
        // terminal: reconhece e sai — reentrega de trabalho concluído não
        // reenvia documento.
        if ($dispatch === null || $dispatch->status !== WhatsAppDispatch::STATUS_QUEUED) {
            return;
        }

        $channel = WhatsAppChannel::forAccount($this->accountId);

        if ($channel === null || ! $channel->isConnected()) {
            $this->fail($dispatch, 'O Canal WhatsApp do escritório não está conectado.');

            return;
        }

        $apiKey = $channel->instanceApiKey();

        if ($apiKey === null) {
            $this->fail($dispatch, 'O Canal WhatsApp não tem credencial de envio: pareie de novo.');

            return;
        }

        $phone = $this->telefoneDestino($dispatch);

        if ($phone === null) {
            $this->fail($dispatch, $dispatch->client_dispatch_contact_id !== null
                ? 'O Contato de envio foi removido do cadastro do cliente.'
                : 'O cliente não tem telefone cadastrado na carteira nem Contato de envio WhatsApp.');

            return;
        }

        try {
            $arquivo = resolve(SerproPgdasDocumentReader::class)->download(
                $this->accountId,
                (int) $dispatch->client_id,
                (string) $dispatch->competence,
                (string) $dispatch->artifact_type,
            );
        } catch (SerproException $exception) {
            $this->fail($dispatch, $this->sanitizar($exception->failure->label(), $apiKey));

            return;
        } catch (HttpExceptionInterface $exception) {
            // O leitor aborta (404/409/502) quando a competência, o termo
            // ou o PDF do provedor não servem — o motivo para a tela é o
            // rótulo da situação, nunca o detalhe interno.
            $this->fail($dispatch, $this->sanitizar(
                $exception->getMessage() !== ''
                    ? $exception->getMessage()
                    : 'O documento não está disponível para envio.',
                $apiKey,
            ));

            return;
        }

        $claimed = WhatsAppDispatch::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $this->accountId)
            ->whereKey($dispatch->getKey())
            ->where('status', WhatsAppDispatch::STATUS_QUEUED)
            ->whereNull('send_attempted_at')
            ->update(['send_attempted_at' => now(), 'retry_safe' => false]);

        if ($claimed !== 1) {
            return;
        }

        try {
            $resposta = resolve(WzapClient::class)->sendMedia(
                (string) $channel->wzap_instance_id,
                $apiKey,
                $arquivo['bytes'],
                $arquivo['filename'],
                $arquivo['content_type'],
                $phone,
                (string) $dispatch->idempotency_key,
                $this->caption($dispatch),
            );
        } catch (WzapException $exception) {
            $this->fail($dispatch, $this->sanitizar($exception->getMessage(), $apiKey));

            return;
        }

        DB::transaction(function () use ($dispatch, $resposta): void {
            $current = WhatsAppDispatch::query()->withoutGlobalScope('account')
                ->where('account_id', $this->accountId)->whereKey($dispatch->getKey())
                ->lockForUpdate()->first();
            if ($current === null) {
                return;
            }
            $changes = [];
            if ($current->status === WhatsAppDispatch::STATUS_QUEUED) {
                $changes = ['status' => WhatsAppDispatch::STATUS_SENT, 'failure_reason' => null];
            }
            if ($current->message_id === null && isset($resposta['message_id'])) {
                $changes['message_id'] = (string) $resposta['message_id'];
            }
            if ($changes !== []) {
                WhatsAppDispatch::query()->withoutGlobalScope('account')
                    ->where('account_id', $this->accountId)->whereKey($current->getKey())->update($changes);
            }
        });
    }

    /**
     * O destino do envio: o `value` do Contato de envio que a linha
     * referencia, lido pela conta explícita do job — nunca pelo que
     * sobrou no tenant.
     *
     * Disparo com `client_dispatch_contact_id` cujo contato sumiu
     * (removido do cadastro — a coluna fica órfã sem FK de delete — ou
     * de conta divergente) não cai no telefone da carteira: o membro
     * marcou aquele destino, e enviar a outro número é pior que falhar
     * — o `null` aqui vira `failed` com o motivo legível no caller. O
     * fallback para `clients.phone` é só das linhas sem contato desde o
     * início: o POST legado e os disparos anteriores à coluna.
     */
    private function telefoneDestino(WhatsAppDispatch $dispatch): ?string
    {
        if ($dispatch->client_dispatch_contact_id !== null) {
            $contato = $dispatch->dispatchContact()->first();

            if ($contato === null || $contato->account_id !== $this->accountId) {
                return null;
            }

            $digits = preg_replace('/\D+/', '', (string) $contato->value);

            return $digits !== '' ? $digits : null;
        }

        $client = $dispatch->client()->first();

        if ($client === null || $client->account_id !== $this->accountId) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) $client->phone);

        return $digits !== '' ? $digits : null;
    }

    /**
     * A legenda fixa da guia: nome do documento + competência em
     * `MM/AAAA`, para o destinatário reconhecer o PDF no WhatsApp. Só a
     * guia leva caption — recibo e declaração seguem sem texto.
     */
    private function caption(WhatsAppDispatch $dispatch): ?string
    {
        if ($dispatch->artifact_type !== 'guia') {
            return null;
        }

        $competencia = (string) $dispatch->competence;

        if (preg_match('/^(\d{4})-(\d{2})$/', $competencia, $m) === 1) {
            $competencia = "{$m[2]}/{$m[1]}";
        }

        return "Guia PGDAS {$competencia}";
    }

    /**
     * Marca a linha como falha terminal com texto seguro para a UI.
     * Nenhum caminho aqui grava apikey, bytes de PDF ou corpo de mensagem.
     */
    private function fail(WhatsAppDispatch $dispatch, string $reason): void
    {
        Log::info('Disparo WhatsApp falhou', [
            'account_id' => $this->accountId,
            'dispatch_id' => $dispatch->getKey(),
            'reason' => $reason,
        ]);

        WhatsAppDispatch::query()->withoutGlobalScope('account')
            ->where('account_id', $this->accountId)->whereKey($dispatch->getKey())
            ->where('status', WhatsAppDispatch::STATUS_QUEUED)
            ->update([
                'status' => WhatsAppDispatch::STATUS_FAILED,
                'failure_reason' => $reason,
            ]);
    }

    /**
     * O motivo que sobe para a UI não pode carregar a apikey da instância
     * nem o segredo global: o envelope do wzap pode ecoar texto arbitrário
     * e a recusa de chave divergente pode citar a própria chave. A regra
     * vive em `WhatsAppFailureReason` porque o webhook sanitiza igual.
     */
    private function sanitizar(string $reason, ?string $instanceApiKey): string
    {
        return WhatsAppFailureReason::sanitizar($reason, $instanceApiKey);
    }

    /**
     * O desfecho de uma tentativa que morreu de forma que `handle()` não
     * viu. A linha fica `queued` se o worker não chegou a gravar — uma
     * recusa que já foi registrada não volta atrás.
     */
    public function failed(?\Throwable $exception): void
    {
        if ($exception === null) {
            return;
        }

        $dispatch = WhatsAppDispatch::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $this->accountId)
            ->find($this->dispatchId);

        if ($dispatch === null || $dispatch->status !== WhatsAppDispatch::STATUS_QUEUED) {
            return;
        }

        $this->fail($dispatch, $this->sanitizar(
            'O envio não completou e a fila esgotou as tentativas.',
            null,
        ));
    }
}
