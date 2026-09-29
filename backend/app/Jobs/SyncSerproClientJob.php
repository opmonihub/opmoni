<?php

namespace App\Jobs;

use App\Enums\SerproFailure;
use App\Enums\SerproSyncItemState;
use App\Models\AccountCertificate;
use App\Models\Client;
use App\Models\SerproCall;
use App\Models\SerproSyncRunItem;
use App\Services\SerproAccountEnablement;
use App\Services\SerproCallRecorder;
use App\Services\SerproClient;
use App\Services\SerproClientLock;
use App\Services\SerproEligibility;
use App\Services\SerproException;
use App\Services\SerproMonitoringMapper;
use App\Services\SerproMonitoringWriter;
use App\Services\SerproObligationCatalog;
use App\Services\SerproPowerOracle;
use App\Services\SerproResult;
use App\Services\SerproRunFinalizer;
use App\Services\SerproTermManager;
use App\Tenant\CurrentTenant;
use Closure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * O trabalhador de um cliente dentro de uma execução.
 *
 * **Uma obrigação por entrega.** Cada `handle()` escolhe a próxima chamada
 * ainda não registrada, marca `current_obligation` + `attempted_at` como
 * fronteira de envio, chama e libera o item para a entrega seguinte com
 * `release(1)`. O checkpoint é o que mantém os serviços habilitados dentro
 * dos 75s, e é o que torna o crash observável: a marca que sobrou sem
 * resposta é a prova de que a chamada *pode* ter sido aplicada, e a resposta
 * honesta é `indeterminado` — nunca reenvio às cegas.
 *
 * **A reentrega não repete.** O que já saiu está em `serpro_calls` por
 * `(run, cliente, serviço)`, então o mesmo job entregue duas vezes avança
 * para o próximo serviço em vez de cobrar de novo.
 *
 * **Nada vem do singleton.** A conta viaja no job, o `CurrentTenant` é
 * restaurado em `finally`, e toda leitura passa por `account_id` explícito —
 * no worker o escopo global não filtra nada.
 */
final class SyncSerproClientJob implements ShouldQueue
{
    use Queueable;

    /**
     * Vinte tentativas com folga de 15–60s: é o que uma janela de throttle do
     * provedor pede para passar, e o teto que impede um item ruim de girar
     * para sempre — o `failed()` é da próxima task do plano.
     */
    public int $tries = 20;

    /** Abaixo do `retry_after` de 90s do redis: quem encosta no lease é reentrega dupla. */
    public int $timeout = 75;

    /** @var list<int> */
    public array $backoff = [15, 60];

    public function __construct(
        public int $runId,
        public int $accountId,
        public int $clientId,
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
        $item = SerproSyncRunItem::query()
            ->where('account_id', $this->accountId)
            ->where('run_id', $this->runId)
            ->where('client_id', $this->clientId)
            ->firstOrFail();

        // Item terminal é reentrega de trabalho concluído: a resposta é
        // reconhecer e sair, e não reabrir o que já decidiu.
        if ($item->state !== SerproSyncItemState::NotProcessed) {
            return;
        }

        $client = Client::query()->where('account_id', $this->accountId)->findOrFail($this->clientId);

        if ($client->tax_id === null) {
            // Sem documento não há `contribuinte` para o envelope, e a
            // chamada que sairia assim é cobrança por uma recusa certa.
            $this->finish($item, SerproSyncItemState::Skipped, 'sem_documento');

            return;
        }

        $lock = resolve(SerproClientLock::class)->acquire($this->accountId, (string) $client->tax_id);

        if ($lock === null) {
            // Outra entrega está trabalhando neste contribuinte agora; a
            // reagendada revisita daqui a pouco, e a que segura o lock é a
            // que termina o serviço.
            $this->release(15);

            return;
        }

        try {
            $this->process($item, $client);
        } finally {
            $lock->release();
        }
    }

    private function process(SerproSyncRunItem $item, Client $client): void
    {
        // A conta é relida aqui, e não no disparo: o flag pode ter sido
        // desligado depois que a execução nasceu, e desligar é contenção.
        if (! resolve(SerproAccountEnablement::class)->enabled($this->accountId)) {
            $this->finish($item, SerproSyncItemState::Skipped, 'conta_desligada');

            return;
        }

        if ($item->current_obligation !== null && $item->attempted_at !== null) {
            // A fronteira de envio foi cruzada e a resposta não chegou —
            // reenviar cobraria de novo o que talvez já tenha sido aplicado.
            $this->finish($item, SerproSyncItemState::Indeterminate, 'tentativa_sem_resposta');

            return;
        }

        $token = resolve(SerproTermManager::class)->validToken($this->accountId);

        if ($token === null) {
            $this->finish($item, SerproSyncItemState::Skipped, 'sem_termo');

            return;
        }

        $certificate = AccountCertificate::currentFor($this->accountId);

        if ($certificate === null) {
            $this->finish($item, SerproSyncItemState::Skipped, 'sem_certificado');

            return;
        }

        $this->step($item, $client, $certificate, $token);
    }

    private function step(SerproSyncRunItem $item, Client $client, AccountCertificate $certificate, string $token): void
    {
        $catalogo = resolve(SerproObligationCatalog::class);
        $oracle = $catalogo->oracle();

        // O oráculo corre primeiro: ele é quem grava as autorizações que a
        // elegibilidade de cada obrigação lê, e é a única leitura que não
        // exige outorga nenhuma.
        if (! $this->called($oracle['id_servico'])) {
            $this->deliver($item, $client, $oracle,
                fn (): SerproResult => resolve(SerproClient::class)->call(
                    $oracle['id_sistema'],
                    $oracle['id_servico'],
                    [
                        'outorgante' => $client->tax_id,
                        'tipoOutorgante' => '2',
                        'outorgado' => $certificate->document,
                        'tipoOutorgado' => '2',
                    ],
                    (string) $certificate->document,
                    (string) $client->tax_id,
                    $token,
                ),
                fn (SerproResult $result) => resolve(SerproPowerOracle::class)
                    ->persist($this->accountId, $this->clientId, $result),
            );

            return;
        }

        $ultimoMotivo = null;
        $writer = resolve(SerproMonitoringWriter::class);
        $mapper = resolve(SerproMonitoringMapper::class);

        foreach ($catalogo->syncables() as $unidade) {
            if ($this->called($unidade['id_servico'])) {
                continue;
            }

            $motivo = $this->motivoDeInelegibilidade($client, $unidade['procuracao']);

            if ($motivo !== null) {
                // A recusa não custa chamada, mas custa explicação: a linha
                // do painel recebe a causa para o operador saber o que
                // falta, em vez de um `sem_dados` que pareceria "não tentou".
                $writer->store($this->accountId, $this->clientId, $unidade['slug'], ['cause' => $motivo], null);
                $ultimoMotivo = $motivo;

                continue;
            }

            $this->deliver($item, $client, $unidade,
                fn (): SerproResult => resolve(SerproClient::class)->call(
                    $unidade['id_sistema'],
                    $unidade['id_servico'],
                    $mapper->payload($unidade['id_servico']),
                    (string) $certificate->document,
                    (string) $client->tax_id,
                    $token,
                ),
                fn (SerproResult $result) => $writer->store(
                    $this->accountId,
                    $this->clientId,
                    $unidade['slug'],
                    $mapper->project($unidade['id_servico'], $result),
                    now()->toISOString(),
                ),
            );

            return;
        }

        $this->finishTerminal($item, $ultimoMotivo);
    }

    /**
     * O ciclo de uma chamada: reivindica a obrigação, manda, registra e
     * limpa a fronteira para a próxima entrega — que chega por `release(1)`
     * na fila, e por outra chamada de `handle()` na mesma execução.
     *
     * @param  array{slug: string, id_sistema: string, id_servico: string}  $unidade
     * @param  Closure(): SerproResult  $chamada
     * @param  Closure(SerproResult): void  $persistir
     */
    private function deliver(
        SerproSyncRunItem $item,
        Client $client,
        array $unidade,
        Closure $chamada,
        Closure $persistir,
    ): void {
        if ($unidade['slug'] !== 'procuracoes') {
            // A obrigação é consulta ao provedor e não linha do painel: o
            // vínculo é criado antes do envio porque ele é o registro de
            // "esta obrigação foi tentada", e `source_at` só chega com a
            // resposta — a linha sem data é "tentado e não respondido".
            resolve(SerproMonitoringWriter::class)
                ->store($this->accountId, $this->clientId, $unidade['slug'], [], null);
        }

        $item->forceFill([
            'current_obligation' => $unidade['slug'],
            'attempted_at' => now(),
        ])->save();

        try {
            $result = resolve(SerproCallRecorder::class)->record(
                $this->runId,
                $this->accountId,
                $client->getKey(),
                $unidade['id_sistema'],
                $unidade['id_servico'],
                $chamada,
            );
        } catch (SerproException $exception) {
            $this->classificar($item, $unidade, $exception);

            return;
        }

        $persistir($result);

        $item->forceFill([
            'current_obligation' => null,
            'attempted_at' => null,
            'response_id' => $result->responseId(),
            'request_tag' => $result->requestTag(),
        ])->save();

        $this->release(1);
    }

    /**
     * O desfecho da exceção, por classe de falha. `release` sem limpar a
     * fronteira transformaria a próxima entrega em `indeterminado`, então a
     * retentativa só acontece para a falha que a resposta prova não ter sido
     * aplicada.
     *
     * @param  array{slug: string, id_sistema: string, id_servico: string}  $unidade
     */
    private function classificar(SerproSyncRunItem $item, array $unidade, SerproException $exception): void
    {
        // O que a linha do item precisa contar, mesmo quando o desfecho não é
        // terminal: código, identificador de resposta e tag são a prova do
        // que aconteceu, e ficam no item porque é ele que a tela abre.
        $item->forceFill([
            'provider_code' => $exception->providerCode,
            'response_id' => $exception->responseId,
            'request_tag' => $exception->requestTag,
        ])->save();

        if ($exception->providerCode === 'AcessoNegado-ICGERENCIADOR-022') {
            // O provedor disse que a outorga não cobre o serviço — é o
            // `sem_procuracao` que a elegibilidade não viu, e a obrigação
            // fica marcada para o painel, não para retentativa.
            if ($unidade['slug'] !== 'procuracoes') {
                resolve(SerproMonitoringWriter::class)
                    ->store($this->accountId, $this->clientId, $unidade['slug'], ['cause' => 'sem_procuracao'], null);
            }
            $this->limparFronteira($item);
            $this->release(1);

            return;
        }

        match ($exception->failure) {
            SerproFailure::Indeterminate => $this->finish($item, SerproSyncItemState::Indeterminate, 'resposta_incerta'),
            SerproFailure::Throttled => $this->reagendar($item, 60),
            SerproFailure::Upstream => $exception->responseId !== null
                ? $this->reagendar($item, 15)
                // A resposta não veio com identificador: não há prova de que
                // o provedor nem tenha recebido, e é por isso que a
                // incerteza vale — `indeterminado`, não falha.
                : $this->finish($item, SerproSyncItemState::Indeterminate, 'resposta_incerta'),
            default => $this->finish($item, SerproSyncItemState::Failed, $exception->failure->value),
        };
    }

    private function reagendar(SerproSyncRunItem $item, int $delay): void
    {
        $this->limparFronteira($item);
        $this->release($delay);
    }

    private function limparFronteira(SerproSyncRunItem $item): void
    {
        $item->forceFill(['current_obligation' => null, 'attempted_at' => null])->save();
    }

    /**
     * O primeiro motivo que impede a chamada, na mesma gramática da
     * elegibilidade — e `null` quando nenhum se aplica. Cada alternativa do
     * mapa é um conjunto que precisa estar inteiro concedido.
     */
    private function motivoDeInelegibilidade(Client $client, ?string $procuracao): ?string
    {
        $catalogo = resolve(SerproObligationCatalog::class);
        $elegibilidade = resolve(SerproEligibility::class);

        foreach ($catalogo->procuracaoAlternatives($procuracao) as $alternativa) {
            if ($alternativa === []) {
                return null;
            }

            $concedida = collect($alternativa)->every(
                fn (string $family): bool => $elegibilidade
                    ->for($this->accountId, $client->getKey(), $family)['eligible'],
            );

            if ($concedida) {
                return null;
            }
        }

        // Nenhuma alternativa coberta: o motivo da primeira família é o que
        // a tela mostra — os demais são o mesmo problema com outros códigos.
        $primeira = $catalogo->procuracaoAlternatives($procuracao)[0][0] ?? null;

        if ($primeira === null) {
            return null;
        }

        return $elegibilidade->for($this->accountId, $client->getKey(), $primeira)['reason'];
    }

    /**
     * "Já decidiu", e não "já existe linha": `Throttled` e `Upstream` são as
     * falhas que `reagendar()` marca para tentar de novo, e um `exists()`
     * sem exceção pularia o serviço que a retentativa existe para refazer.
     */
    private function called(string $idServico): bool
    {
        return SerproCall::query()
            ->where('account_id', $this->accountId)
            ->where('run_id', $this->runId)
            ->where('client_id', $this->clientId)
            ->where('id_servico', $idServico)
            ->whereNotIn('status', [SerproFailure::Throttled, SerproFailure::Upstream])
            ->exists();
    }

    /**
     * `current_obligation` é apagada e `attempted_at` fica: a fronteira não
     * vale mais para um item decidido, e o instante da última tentativa é a
     * única pista que sobra quando o desfecho foi `indeterminado`.
     */
    private function finish(SerproSyncRunItem $item, SerproSyncItemState $state, ?string $reason): void
    {
        $item->forceFill([
            'state' => $state,
            'reason' => $reason,
            'current_obligation' => null,
        ])->save();

        resolve(SerproRunFinalizer::class)->recount($this->runId, $this->accountId);
    }

    /**
     * O desfecho quando não há mais o que chamar: `sincronizado` se alguma
     * obrigação desta execução respondeu sucesso, `ignorado` se só o oráculo
     * falou — ele mede a autorização e não entrega dado do cliente, então a
     * resposta dele sozinha não conta o que não veio.
     */
    private function finishTerminal(SerproSyncRunItem $item, ?string $reason): void
    {
        $sincronizou = SerproCall::query()
            ->where('account_id', $this->accountId)
            ->where('run_id', $this->runId)
            ->where('client_id', $this->clientId)
            ->where('id_servico', '!=', 'OBTERPROCURACAO41')
            ->where('status', SerproFailure::Success)
            ->exists();

        $this->finish(
            $item,
            $sincronizou ? SerproSyncItemState::Synchronized : SerproSyncItemState::Skipped,
            $reason,
        );
    }
}
