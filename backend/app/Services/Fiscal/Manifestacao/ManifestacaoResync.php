<?php

namespace App\Services\Fiscal\Manifestacao;

use App\Enums\FiscalFailure;
use App\Enums\FiscalManifestationOutcome;
use App\Enums\FiscalSource;
use App\Enums\FiscalStage;
use App\Jobs\SendFiscalManifestationJob;
use App\Models\Client;
use App\Models\FiscalCursor;
use App\Models\FiscalManifestation;
use App\Services\Fiscal\Capture\FiscalCaptureLock;
use App\Services\Fiscal\Capture\FiscalConnectorRegistry;
use App\Services\Fiscal\Capture\FiscalDocumentWriter;
use App\Services\Fiscal\Contracts\PulledDocument;
use App\Services\Fiscal\Exceptions\FiscalException;
use App\Services\Fiscal\Exceptions\FiscalLookupDeferred;
use App\Services\Fiscal\Exceptions\FiscalRequestNotSent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * A ressincronização dos resumos manifestados: para cada chave cuja ciência da
 * emissão já foi registrada — por nós (`Sent`) ou por terceiro (`573`,
 * `AlreadyManifested`) —, a `consChNFe` traz o XML completo que a distribuição
 * ainda não entregou.
 *
 * É a mesma disciplina da reconciliação de lacunas, porque é a mesma consulta
 * ao mesmo serviço: uma chave por vez, dentro do teto horário de consultas
 * pontuais que o próprio `fetchByChave` reserva, e com a trava por cliente e
 * fonte — a ressincronização e a captura não consultam o mesmo CNPJ ao mesmo
 * tempo, que é o uso indevido que a NT classifica.
 *
 * O que a torna segura a repetição é o rastreio por chave no registro da
 * manifestação: `resync_attempts` limita as consultas à chave que o serviço
 * não devolve, e `xml_recovered_at` marca a que já entrou — a repetição lê as
 * duas e não repete nem a consulta gasta nem a gravação feita.
 *
 * O que ela não faz: consultar resumo sem manifestação (a `consChNFe` de uma
 * chave não manifestada é vaga gasta à toa), nem tocar na posição do cursor —
 * a posição é da captura incremental, e uma consulta pontual que a alterasse
 * inventaria avanço.
 */
final class ManifestacaoResync
{
    public function __construct(
        private readonly FiscalConnectorRegistry $connectors,
        private readonly FiscalDocumentWriter $writer,
    ) {}

    /**
     * A varredura de uma passada: os resumos manifestados e ainda incompletos
     * de todas as contas, cliente a cliente. Devolve quantos XML completos
     * entraram.
     */
    public function run(): int
    {
        // A retomada vem antes da recuperação: a manifestação órfã é quem
        // destrava o resumo, e recuperar o XML de uma chave ainda não
        // manifestada seria vaga do teto gasta à toa.
        $this->retomarOrfas();

        $recuperados = 0;

        $this->pendentes()
            ->get()
            ->groupBy('client_id')
            ->each(function ($manifestacoes, $clientId) use (&$recuperados): void {
                $client = Client::query()
                    ->withoutGlobalScope('account')
                    ->find((int) $clientId);

                // O cliente apagado da carteira não tem documento a recuperar:
                // a linha dele sai em cascata e o resumo some junto. Sem a
                // guarda, a consulta sairia com um `client_id` que ninguém mais
                // lê.
                if ($client === null) {
                    return;
                }

                $recuperados += $this->recuperarCliente($client, $manifestacoes->all());
            });

        return $recuperados;
    }

    /**
     * As manifestações que pararam entre o enqueue e o veredito.
     *
     * O job morre entre o `marcarEnfileirado` e a resposta quando o worker
     * reinicia na hora do `post`, e o registro fica `Queued` para sempre —
     * a deduplicação na execução só sai cedo dos vereditos, e nenhuma
     * rotina olhava para quem ficou no meio. O que distingue a órfã da que
     * ainda vai rodar é o tempo: `requested_at` mais antigo que a graça e
     * `sent_at` nulo dizem que nenhuma tentativa foi à rede.
     *
     * O re-despacho é seguro por construção: a chave lógica deduplica o
     * registro e a segunda execução que encontra `Queued` passa adiante —
     * o pior caso é um segundo evento, que o fisco responde `573`, estado
     * conhecido que não rebaixa nada.
     */
    private function retomarOrfas(): int
    {
        $limite = CarbonImmutable::now()
            ->subMinutes((int) config('fiscal.manifestacao_orphan_grace_minutes', 60));

        $reentregues = 0;

        FiscalManifestation::query()
            ->withoutGlobalScope('account')
            ->whereIn('outcome', [
                FiscalManifestationOutcome::Pending,
                FiscalManifestationOutcome::Queued,
            ])
            ->whereNull('sent_at')
            ->where('requested_at', '<', $limite)
            ->orderBy('requested_at')
            ->chunkById(100, function ($manifestacoes) use (&$reentregues): void {
                foreach ($manifestacoes as $manifestation) {
                    SendFiscalManifestationJob::dispatch(
                        accountId: (int) $manifestation->account_id,
                        clientId: (int) $manifestation->client_id,
                        chaveAcesso: (string) $manifestation->chave_acesso,
                        eventType: $manifestation->event_type,
                        eventSeq: (int) $manifestation->event_seq,
                    );

                    $reentregues++;
                }
            });

        return $reentregues;
    }

    /**
     * As manifestações cujo resumo ainda não virou documento completo.
     *
     * A elegibilidade são três condições e as três são da chave, não do
     * cliente: a ciência registrada (`Sent` ou `AlreadyManifested` — o `573`
     * de terceiro libera o `consChNFe` igual), o resumo gravado na mesma chave
     * e o documento completo ainda ausente. A tentativa limitada é a quarta,
     * e é o que impede que uma chave que o serviço nunca devolve custe a vaga
     * do CNPJ para sempre.
     */
    private function pendentes(): Builder
    {
        return FiscalManifestation::query()
            ->withoutGlobalScope('account')
            ->whereIn('outcome', [
                FiscalManifestationOutcome::Sent,
                FiscalManifestationOutcome::AlreadyManifested,
            ])
            ->whereNull('xml_recovered_at')
            ->where('resync_attempts', '<', (int) config('fiscal.manifestacao_resync_max_attempts', 3))
            ->whereExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('fiscal_documents')
                ->whereColumn('fiscal_documents.client_id', 'fiscal_manifestations.client_id')
                ->whereColumn('fiscal_documents.chave_acesso', 'fiscal_manifestations.chave_acesso')
                ->where('fiscal_documents.stage', FiscalStage::Summary->value))
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('fiscal_documents')
                ->whereColumn('fiscal_documents.client_id', 'fiscal_manifestations.client_id')
                ->whereColumn('fiscal_documents.chave_acesso', 'fiscal_manifestations.chave_acesso')
                ->where('fiscal_documents.stage', FiscalStage::Document->value))
            ->orderBy('requested_at');
    }

    /**
     * As chaves devidas de um cliente, sob a trava dele e da fonte — a mesma
     * `fiscal:capture:{cliente}:{fonte}` que serializa a captura e a
     * reconciliação, porque a consulta é ao mesmo serviço.
     *
     * @param  list<FiscalManifestation>  $manifestacoes
     */
    private function recuperarCliente(Client $client, array $manifestacoes): int
    {
        $cursor = FiscalCursor::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $client->account_id)
            ->where('client_id', $client->getKey())
            ->where('source', FiscalSource::NfeDistribuicao)
            ->first();

        // A janela de parada do fisco é absoluta para a consulta pontual
        // também: um `consChNFe` dentro dela zera a contagem e reinicia a hora,
        // e é por isso que a varredura do cliente para aqui — sem contar
        // tentativa nenhuma, porque nada foi perguntado.
        if ($cursor?->isBlocked() === true) {
            return 0;
        }

        return FiscalCaptureLock::run(
            $client,
            FiscalSource::NfeDistribuicao,
            fn (): int => $this->recuperarChaves($client, $manifestacoes),
        ) ?? 0;
    }

    /**
     * Uma chave por vez, dentro do que o teto deixar.
     *
     * @param  list<FiscalManifestation>  $manifestacoes
     */
    private function recuperarChaves(Client $client, array $manifestacoes): int
    {
        $recuperados = 0;
        $connector = $this->connectors->for(FiscalSource::NfeDistribuicao);

        foreach ($manifestacoes as $manifestation) {
            try {
                $document = $connector->fetchByChave($client, $manifestation->chave_acesso);
            } catch (FiscalLookupDeferred) {
                // O teto acabou ou a trava do CNPJ está com outra execução: a
                // varredura para, e a chave volta na passada seguinte — sem
                // tentativa cobrada por uma consulta que não saiu.
                break;
            } catch (FiscalRequestNotSent) {
                // Sem certificado utilizável não há consulta que saia, e a
                // pre-flight do conector é a mesma para a próxima chave deste
                // cliente — parar aqui é o que impede que uma noite vire uma
                // recusa por chave.
                break;
            } catch (FiscalException $exception) {
                if ($exception->failure === FiscalFailure::Blocked) {
                    // O `656` do fisco: parada do CNPJ inteiro, gravada no
                    // cursor para a captura ler também — e a varredura para,
                    // porque as chaves seguintes receberiam a mesma resposta.
                    $this->bloquear($client, FiscalSource::NfeDistribuicao);

                    break;
                }

                // Indisponibilidade e recusa do serviço são tentativas que a
                // chave gastou: ela conta, e a próxima responde.
                $this->tentativa($manifestation);

                continue;
            } catch (ConnectionException|RuntimeException) {
                // A consulta saiu e não voltou, ou voltou fora do contrato —
                // nos dois casos a tentativa aconteceu e é contada.
                $this->tentativa($manifestation);

                continue;
            }

            if ($document === null) {
                // O serviço diz que não tem a chave: a tentativa foi gasta
                // respondendo "não", e a chave volta na próxima passada até o
                // limite.
                $this->tentativa($manifestation);

                continue;
            }

            if ($document->chave !== $manifestation->chave_acesso) {
                // A resposta de outra chave não resolve esta: o documento é
                // descartado e a tentativa conta, porque a consulta saiu.
                $this->tentativa($manifestation);

                continue;
            }

            if (! $this->gravar($client, $document)) {
                $this->tentativa($manifestation);

                continue;
            }

            $manifestation->forceFill(['xml_recovered_at' => now()])->save();
            $recuperados++;
        }

        return $recuperados;
    }

    /**
     * Uma tentativa a mais na chave — a consulta saiu, e o `resync_attempts`
     * é o que a faz parar depois do limite configurado.
     */
    private function tentativa(FiscalManifestation $manifestation): void
    {
        $manifestation->increment('resync_attempts');
    }

    /**
     * O documento que o `consChNFe` trouxe, pelo único caminho de escrita do
     * módulo. `false` é a gravação recusada — a linha ficaria apontando para
     * arquivo nenhum, e a tentativa conta porque a consulta aconteceu.
     */
    private function gravar(Client $client, PulledDocument $document): bool
    {
        try {
            $this->writer->store($client, FiscalSource::NfeDistribuicao, $document);
        } catch (RuntimeException $exception) {
            Log::warning('fiscal.manifestacao_resync.gravacao_recusada', [
                'account_id' => (int) $client->account_id,
                'client_id' => (int) $client->getKey(),
                'chave_acesso' => $document->chave,
                'reason' => 'gravação recusada: '.class_basename($exception),
            ]);

            return false;
        }

        return true;
    }

    /**
     * A parada do fisco gravada no cursor — mesma coluna e mesma configuração
     * que a captura e a reconciliação escrevem, porque o `656` vale para o
     * CNPJ inteiro, não para o caminho que o descobriu.
     */
    private function bloquear(Client $client, FiscalSource $source): void
    {
        $cursor = FiscalCursor::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $client->account_id)
            ->where('client_id', $client->getKey())
            ->where('source', $source)
            ->first();

        $cursor?->forceFill([
            'blocked_until' => CarbonImmutable::now()->addMinutes((int) config('fiscal.block_minutes', 60)),
        ])->save();
    }
}
