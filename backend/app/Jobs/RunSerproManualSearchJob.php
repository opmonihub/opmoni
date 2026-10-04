<?php

namespace App\Jobs;

use App\Enums\SerproFailure;
use App\Enums\SerproManualSearchState;
use App\Models\AccountCertificate;
use App\Models\Client;
use App\Models\SerproManualSearch;
use App\Services\SerproAccountEnablement;
use App\Services\SerproCallRecorder;
use App\Services\SerproClient;
use App\Services\SerproClientLock;
use App\Services\SerproEligibility;
use App\Services\SerproException;
use App\Services\SerproMonitoringMapper;
use App\Services\SerproMonitoringWriter;
use App\Services\SerproObligationCatalog;
use App\Services\SerproResult;
use App\Services\SerproTermManager;
use App\Tenant\CurrentTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * O trabalhador de uma busca manual: uma obrigação, um cliente, uma chamada.
 *
 * **Reusa a camada de leitura do `SyncSerproClientJob`, sem a execução.** A
 * elegibilidade, o envelope, o recorder e o writer são os mesmos serviços —
 * o que muda é o recorte: em vez de caminhar pelo catálogo inteiro, o job
 * entrega a única obrigação que o operador pediu, e grava na mesma projeção
 * que a sincronização alimenta. `run_id` sai `null` no recorder: a chamada é
 * auditável, mas não pertence a execução nenhuma.
 *
 * **O oráculo não corre aqui.** Ele é o passo de descoberta da execução
 * completa, e cobrá-lo a cada busca manual dobraria a fatura sem o operador
 * pedir: a outorga que vale é a que `serpro:refresh-powers` mantém
 * atualizada, e é dela que a elegibilidade lê. Sem outorga conhecida, a
 * busca falha com o motivo nomeado — e não custa chamada nenhuma.
 *
 * **`recalculate_date` e `mode` viajam no registro, não no pedido.** Nenhum
 * serviço habilitado documenta recorte de data ou de período no envelope de
 * pedido — o `CONSDECLARACAO13` aceita só `anoCalendario`, e o restante nem
 * isso. A busca sai completa dos dois jeitos, e a data e o modo ficam
 * gravados no registro como o que o operador pediu; quando um serviço
 * passar a aceitar recorte, o recorte entra no `SerproMonitoringMapper`,
 * que é a fronteira de tudo o que se pergunta ao provedor.
 *
 * **Nada vem do singleton.** A conta viaja no job, o `CurrentTenant` é
 * restaurado em `finally`, e toda leitura passa por `account_id` explícito.
 */
final class RunSerproManualSearchJob implements ShouldQueue
{
    use Queueable;

    /**
     * Poucas tentativas: a busca manual tem operador esperando, e a fila que
     * não anda vira `failed` com motivo em minutos, não em horas.
     */
    public int $tries = 3;

    /** Abaixo do `retry_after` de 90s: quem encosta no lease é reentrega dupla. */
    public int $timeout = 75;

    /** @var list<int> */
    public array $backoff = [15, 60];

    public function __construct(
        public int $searchId,
        public int $accountId,
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

    /**
     * O desfecho do job que esgotou as tentativas ou morreu fora do `try`.
     *
     * A chamada pode ter sido aplicada no provedor e a resposta se perdido —
     * e a resposta honesta para o operador é `resposta_incerta`, nunca o
     * texto da exceção, que pode carregar segredo e documento.
     */
    public function failed(?\Throwable $exception): void
    {
        $busca = SerproManualSearch::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $this->accountId)
            ->whereKey($this->searchId)
            ->first();

        if ($busca === null || ! in_array($busca->state, [SerproManualSearchState::Queued, SerproManualSearchState::Running], true)) {
            return;
        }

        $this->finish($busca, SerproManualSearchState::Failed, 'resposta_incerta');
    }

    private function work(): void
    {
        $busca = SerproManualSearch::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $this->accountId)
            ->whereKey($this->searchId)
            ->firstOrFail();

        // `queued` é a reivindicação da entrega; `running` é a reentrega que
        // volta do throttle e segue de onde parou; terminal não reabre.
        if ($busca->state === SerproManualSearchState::Queued) {
            $busca->forceFill(['state' => SerproManualSearchState::Running])->save();
        } elseif ($busca->state !== SerproManualSearchState::Running) {
            return;
        }

        $client = Client::query()->where('account_id', $this->accountId)->findOrFail($busca->client_id);

        if ($client->tax_id === null) {
            // Sem documento não há `contribuinte` para o envelope, e a chamada
            // que sairia assim é cobrança por uma recusa certa.
            $this->finish($busca, SerproManualSearchState::Failed, 'sem_documento');

            return;
        }

        // A conta é relida aqui, e não no disparo: o flag pode ter sido
        // desligado depois que a busca nasceu, e desligar é contenção.
        if (! resolve(SerproAccountEnablement::class)->enabled($this->accountId)) {
            $this->finish($busca, SerproManualSearchState::Failed, 'conta_desligada');

            return;
        }

        $token = resolve(SerproTermManager::class)->validToken($this->accountId);

        if ($token === null) {
            $this->finish($busca, SerproManualSearchState::Failed, 'sem_termo');

            return;
        }

        $certificate = AccountCertificate::currentFor($this->accountId);

        if ($certificate === null) {
            $this->finish($busca, SerproManualSearchState::Failed, 'sem_certificado');

            return;
        }

        // O mesmo lock do job de sincronização: duas buscas (manual e run)
        // para o mesmo contribuinte não podem cruzar no gateway.
        $lock = resolve(SerproClientLock::class)->acquire($this->accountId, (string) $client->tax_id);

        if ($lock === null) {
            $this->release(15);

            return;
        }

        try {
            $this->search($busca, $client, $certificate, $token);
        } finally {
            $lock->release();
        }
    }

    private function search(SerproManualSearch $busca, Client $client, AccountCertificate $certificate, string $token): void
    {
        $catalogo = resolve(SerproObligationCatalog::class);

        // A obrigação pedida sai do catálogo pelo slug: sem unidade servida
        // (serviço sem par verificado) não há chamada que valha a cobrança.
        $unidade = collect($catalogo->syncables())
            ->first(fn (array $candidata): bool => $candidata['slug'] === $busca->obligation);

        if ($unidade === null) {
            $this->finish($busca, SerproManualSearchState::Failed, 'obrigacao_sem_leitura');

            return;
        }

        $motivo = $this->motivoDeInelegibilidade($client, $unidade['procuracao']);

        if ($motivo !== null) {
            // A recusa não custa chamada, mas custa explicação: a linha do
            // painel recebe a causa na mesma gramática da sincronização.
            resolve(SerproMonitoringWriter::class)
                ->store($this->accountId, $client->getKey(), $busca->obligation, ['cause' => $motivo], null);
            $this->finish($busca, SerproManualSearchState::Failed, $motivo);

            return;
        }

        try {
            $result = resolve(SerproCallRecorder::class)->record(
                null,
                $this->accountId,
                $client->getKey(),
                $unidade['id_sistema'],
                $unidade['id_servico'],
                fn (): SerproResult => resolve(SerproClient::class)->call(
                    $unidade['id_sistema'],
                    $unidade['id_servico'],
                    resolve(SerproMonitoringMapper::class)->payload($unidade['id_servico']),
                    (string) $certificate->document,
                    (string) $client->tax_id,
                    $token,
                ),
            );
        } catch (SerproException $exception) {
            $this->classificar($busca, $exception);

            return;
        }

        resolve(SerproMonitoringWriter::class)->store(
            $this->accountId,
            $client->getKey(),
            $busca->obligation,
            resolve(SerproMonitoringMapper::class)->project($unidade['id_servico'], $result),
            now()->toISOString(),
        );

        $this->finish($busca, SerproManualSearchState::Completed, null);
    }

    /**
     * O desfecho da exceção, na mesma gramática do `SyncSerproClientJob` —
     * com a diferença de que aqui não há fronteira de envio nem item para
     * voltar: o que não pode repetir vira `resposta_incerta` e para.
     */
    private function classificar(SerproManualSearch $busca, SerproException $exception): void
    {
        if ($exception->providerCode === 'AcessoNegado-ICGERENCIADOR-022') {
            // O provedor disse que a outorga não cobre o serviço — é o
            // `sem_procuracao` que a elegibilidade não viu, e fica marcado
            // na linha do painel, não para retentativa.
            resolve(SerproMonitoringWriter::class)
                ->store($this->accountId, $busca->client_id, $busca->obligation, ['cause' => 'sem_procuracao'], null);
            $this->finish($busca, SerproManualSearchState::Failed, 'sem_procuracao');

            return;
        }

        match ($exception->failure) {
            SerproFailure::Throttled => $this->release(60),
            SerproFailure::Upstream => $exception->responseId !== null
                ? $this->release(15)
                : $this->finish($busca, SerproManualSearchState::Failed, 'resposta_incerta'),
            SerproFailure::Indeterminate => $this->finish($busca, SerproManualSearchState::Failed, 'resposta_incerta'),
            default => $this->finish($busca, SerproManualSearchState::Failed, $exception->failure->value),
        };
    }

    private function finish(SerproManualSearch $busca, SerproManualSearchState $state, ?string $reason): void
    {
        $busca->forceFill([
            'state' => $state,
            'reason' => $reason,
        ])->save();
    }

    /**
     * O primeiro motivo que impede a chamada, na mesma gramática do job de
     * sincronização — e `null` quando nenhum se aplica.
     *
     * @param  array{slug: string, id_sistema: string, id_servico: string, procuracao: ?string}  $unidade
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

        $primeira = $catalogo->procuracaoAlternatives($procuracao)[0][0] ?? null;

        if ($primeira === null) {
            return null;
        }

        return $elegibilidade->for($this->accountId, $client->getKey(), $primeira)['reason'];
    }
}
