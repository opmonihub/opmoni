<?php

namespace App\Jobs;

use App\Enums\FiscalManifestationEventType;
use App\Enums\FiscalManifestationOutcome;
use App\Enums\FiscalSource;
use App\Enums\FiscalStage;
use App\Models\Client;
use App\Models\FiscalCursor;
use App\Models\FiscalDocument;
use App\Models\FiscalManifestation;
use App\Services\Fiscal\Exceptions\FiscalException;
use App\Services\Fiscal\Exceptions\FiscalRequestNotSent;
use App\Services\Fiscal\Manifestacao\FiscalManifestationStore;
use App\Services\Fiscal\Manifestacao\RecepcaoEventoConnector;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A execução da ciência da emissão: um job por chave, e todas as guardas na
 * hora de rodar — não no despacho.
 *
 * O dispatcher já verificou o gate e registrou o pedido; o que muda entre um e
 * outro é o estado do mundo: o cliente pode ter entrado em bloqueio, a ciência
 * pode ter sido registrada por outra execução, o prazo pode ter vencido. Por
 * isso a ordem aqui é a ordem de custo da decisão — gate, cliente, bloqueio,
 * deduplicação, prazo — e cada guarda devolve antes de gastar a mais cara.
 *
 * O que ele não faz: montar evento, assinar, classificar cStat ou medir o
 * prazo de 90 dias — isso é do `RecepcaoEventoConnector`, que é quem fala com
 * o serviço. O que ele decide: quando chamar o conector, e o que fazer com as
 * duas exceções que não são veredito (`FiscalRequestNotSent` vira `NotSendable`;
 * `FiscalException` transitória re-enfileira, e a definitiva fica `pending`
 * para o registro do que o fisco respondeu).
 */
final class SendFiscalManifestationJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 85;

    public int $tries = 1;

    public function __construct(
        public int $accountId,
        public int $clientId,
        public string $chaveAcesso,
        public FiscalManifestationEventType $eventType,
        public int $eventSeq = 1,
    ) {}

    public function handle(
        FiscalManifestationStore $store,
        RecepcaoEventoConnector $conector,
    ): void {
        if (! config('fiscal.manifestacao_enabled', false)) {
            return;
        }

        $client = Client::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $this->accountId)
            ->find($this->clientId);

        if ($client === null) {
            return;
        }

        // A janela de parada é a mesma da captura e da reconciliação: o fisco
        // mandou este CNPJ não ser consultado por uma hora, e um evento enviado
        // dentro dela é consulta repetida — a que zera a contagem e reinicia a
        // espera. A manifestação espera a janela vencer: o registro fica
        // `pending` e a reentrega com delay a reencontra.
        if ($this->clienteBloqueado($client)) {
            $this->release((int) config('fiscal.block_minutes', 60) * 60);

            return;
        }

        $manifestation = $this->registro();

        if ($manifestation === null) {
            return;
        }

        // Deduplicação na execução: o veredito já gravado — aceite ou já-
        // manifestado — dispensa um segundo evento para a mesma chave. O
        // `Queued` é o intermediário legítimo: quem marcou foi uma execução
        // anterior que parou antes do conector.
        if (in_array($manifestation->outcome, [
            FiscalManifestationOutcome::Sent,
            FiscalManifestationOutcome::AlreadyManifested,
        ], true)) {
            return;
        }

        $store->marcarEnfileirado(
            accountId: $this->accountId,
            clientId: $this->clientId,
            chaveAcesso: $this->chaveAcesso,
            eventType: $this->eventType,
            eventSeq: $this->eventSeq,
        );

        try {
            $conector->cienciaDaEmissao(
                $client,
                $manifestation->refresh(),
                $this->emissaoDoResumo($client),
            );
        } catch (FiscalRequestNotSent) {
            // O que não pode sair não é o serviço recusando: é o nosso lado sem
            // condição de montar — sem certificado, sem senha, UF fora da
            // tabela. Nenhum retry produz o A1, então o veredito é o nosso.
            $store->veredito(
                $manifestation->refresh(),
                FiscalManifestationOutcome::NotSendable,
                null,
                'Manifestação não enviada: condição do cliente não atendida.',
            );
        } catch (FiscalException $exception) {
            // A recusa transitória do serviço re-enfileira: a fila decide a
            // hora, e a guarda de bloqueio acima decide se ela chegou. A
            // definitiva fica `pending` — o registro é o que o fisco respondeu,
            // e a ressincronização não a consulta.
            if ($exception->failure->retryable()) {
                $this->release((int) config('fiscal.block_minutes', 60) * 60);
            }
        }
    }

    /**
     * O cliente dentro da janela de parada do fisco, ou fora dela sem cursor
     * nenhum — um cliente sem cursor é um cliente que a captura ainda não
     * rodou, e a janela é coluna dele.
     */
    private function clienteBloqueado(Client $client): bool
    {
        $cursor = FiscalCursor::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $this->accountId)
            ->where('client_id', $client->getKey())
            ->where('source', FiscalSource::NfeDistribuicao)
            ->first();

        return $cursor?->isBlocked() === true;
    }

    /**
     * O registro deste evento, pela chave lógica — `registrarPedido` no
     * dispatcher já o criou, e um job sem registro é um job que ninguém pediu.
     */
    private function registro(): ?FiscalManifestation
    {
        return FiscalManifestation::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $this->accountId)
            ->where('client_id', $this->clientId)
            ->where('chave_acesso', $this->chaveAcesso)
            ->where('event_type', $this->eventType)
            ->where('event_seq', $this->eventSeq)
            ->first();
    }

    /**
     * A data da autorização que o resumo gravou, e que o conector compara com
     * o prazo de 90 dias. Nula quando o resumo não está gravado — o conector
     * lê ausência de data como prazo não verificável, e o veredito é dele.
     */
    private function emissaoDoResumo(Client $client): ?CarbonInterface
    {
        return FiscalDocument::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $this->accountId)
            ->where('client_id', $client->getKey())
            ->where('chave_acesso', $this->chaveAcesso)
            ->where('stage', FiscalStage::Summary)
            ->value('emissao_at');
    }
}
