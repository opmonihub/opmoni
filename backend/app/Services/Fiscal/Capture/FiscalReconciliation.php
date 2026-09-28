<?php

namespace App\Services\Fiscal\Capture;

use App\Enums\FiscalSource;
use App\Models\Client;
use App\Models\FiscalCursor;
use App\Models\FiscalGap;
use App\Services\Fiscal\Contracts\FiscalConnector;
use App\Services\Fiscal\Contracts\PulledDocument;
use App\Services\Fiscal\Exceptions\FiscalLookupDeferred;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * A volta atrás: buscar, uma a uma, as posições que a captura registrou como
 * buraco.
 *
 * Quatro regras, e a ordem entre elas é a segurança do módulo:
 *
 * 1. **A reconciliação não anda com a posição.** Nem `last_nsu`, nem
 *    `last_run_at`, nem `last_seen_at`, nem `last_success_at`. A posição parada
 *    em 300 é a posição que a captura incremental soube alcançar, e uma
 *    recuperação que a alterasse inventaria avanço — e é a idempotência do
 *    módulo inteiro (o lote gravado antes de a posição andar) que segura o
 *    retrabalho. Por isso nada aqui escreve em `fiscal_cursors`: a única
 *    escrita de documento é a do `FiscalDocumentWriter`.
 * 2. **Uma consulta por buraco, e o teto da hora é o teto da execução.** O
 *    fisco conta consulta pontual no mesmo teto por CNPJ, e uma execução que
 *    estudasse mais buracos do que a hora inteira permite gastaria o orçamento
 *    inteiro e ainda assim tentaria a vaga seguinte, que não existe.
 * 3. **Adiar não é falhar.** Teto estourado e trava do próprio CNPJ ocupada
 *    chegam como `FiscalLookupDeferred`, e nenhum dos dois é resposta do
 *    fisco sobre aquela posição: cobrar uma tentativa seria cobrar de uma
 *    consulta que não saiu, e três dessas encerram a lacuna sem nunca ter
 *    perguntado alguma coisa.
 * 4. **Só é resolvido o que foi gravado.** A lacuna sai da fila depois do
 *    arquivo em disco e da linha no banco, e só quando o documento é o da
 *    posição pedida: o conector devolve o que o serviço mandou, e um documento
 *    de outra posição arquivado sob esta seria a pior linha possível na
 *    tabela — some o documento verdadeiro e entra o errado, com a coluna de
 *    posição afirmando o que não é.
 */
final class FiscalReconciliation
{
    public function __construct(
        private readonly FiscalConnector $connector,
        private readonly FiscalDocumentWriter $writer,
    ) {}

    /**
     * Estuda as lacunas devidas de um cliente e devolve quantos documentos
     * entraram. `0` é tanto "não havia buraco" quanto "não deu para consultar
     * agora" — a diferença entre as duas é do log e das colunas da lacuna.
     */
    public function run(Client $client, FiscalSource $source): int
    {
        return FiscalCaptureLock::run($client, $source, fn (): int => $this->recover($client, $source)) ?? 0;
    }

    private function recover(Client $client, FiscalSource $source): int
    {
        $cursor = $this->cursorOf($client, $source);

        // Ou o fisco mandou parar este cliente por uma hora, ou a última vez que
        // o serviço respondeu foi há mais que a janela de continuidade. Nos dois
        // casos não há o que perguntar, e a lacuna continua pendente e sem
        // contagem — nenhuma das duas é recusa do fisco sobre a posição.
        //
        // A guarda de certificado não é repetida aqui: ela pertence ao serviço
        // de captura, e quem a aplica no ponto da chamada é o conector, que
        // recusa antes de qualquer byte e antes de gastar a vaga do orçamento.
        // Uma recusa dessas adia a posição em vez de perdê-la.
        if ($cursor !== null && ($cursor->isBlocked() || $cursor->historyIsInterrupted())) {
            return 0;
        }

        $recovered = 0;

        foreach ($this->dueGaps($client, $source) as $gap) {
            try {
                $document = $this->connector->fetchByNsu($client, (int) $gap->nsu);
            } catch (FiscalLookupDeferred) {
                // O orçamento acabou ou outra execução detém a trava do CNPJ.
                // Não é recusa do fisco sobre esta posição, e parar aqui — em
                // vez de seguir para a próxima — é o que mantém a contagem de
                // tentativas honesta: a posição adiada nem chegou a ser
                // consultada.
                break;
            } catch (RuntimeException $exception) {
                // Indisponibilidade, credencial recusada e recusa do schema são
                // falhas que adiantam repetir, e nenhuma delas é a resposta
                // "não há documento nesta posição".
                $this->reportKeptGap($client, $gap, 'consulta sem resposta: '.class_basename($exception));
                $this->postpone($gap);

                continue;
            }

            if (! $this->store($client, $source, $gap, $document)) {
                $this->postpone($gap);

                continue;
            }

            $gap->delete();
            $recovered++;
        }

        return $recovered;
    }

    /**
     * O documento que o fisco devolveu para a posição pedida, gravado pelo
     * writer, ou `false` — que é tudo o que `true` não é: ausência, posição
     * errada ou gravação recusada.
     */
    private function store(Client $client, FiscalSource $source, FiscalGap $gap, ?PulledDocument $document): bool
    {
        if ($document === null) {
            // `null` é resposta do fisco, e a única coisa que ela autoriza é
            // tentar de novo mais tarde.
            return false;
        }

        if ($document->nsu !== (int) $gap->nsu) {
            $this->reportKeptGap($client, $gap, 'resposta em outra posição que a pedida.');

            return false;
        }

        try {
            $this->writer->store($client, $source, $document);
        } catch (RuntimeException $exception) {
            $this->reportKeptGap($client, $gap, 'gravação recusada: '.class_basename($exception));

            return false;
        }

        return true;
    }

    /**
     * Uma tentativa a mais e a próxima hora.
     *
     * A hora é a do fisco, a mesma janela que o bloqueio usa, e não um backoff
     * curto: uma posição que o serviço não devolveu pode ser documento que
     * acabou de ser publicado, e insistir em segundos só gasta o teto do CNPJ.
     */
    private function postpone(FiscalGap $gap): void
    {
        $gap->increment('attempts');
        $gap->forceFill(['next_attempt_at' => now()->addHour()])->save();
    }

    /**
     * @return Collection<int, FiscalGap>
     */
    private function dueGaps(Client $client, FiscalSource $source): Collection
    {
        return FiscalGap::query()
            ->forClientSource($client, $source)
            ->where('attempts', '<', (int) config('fiscal.reconcile_max_attempts', 3))
            // Nulo é devida agora: a linha nasce devida, e só uma consulta que
            // saiu adia a próxima.
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('nsu')
            ->limit((int) config('fiscal.consulta_hourly_limit', 20))
            ->get();
    }

    /**
     * O cursor lido, nunca criado: a reconciliação não escreve em
     * `fiscal_cursors`, e um cliente sem cursor é um cliente que a captura
     * ainda não rodou — o que é diferente de um cliente bloqueado.
     */
    private function cursorOf(Client $client, FiscalSource $source): ?FiscalCursor
    {
        return FiscalCursor::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $client->account_id)
            ->where('client_id', $client->getKey())
            ->where('source', $source)
            ->first();
    }

    /**
     * A lacuna que continua na fila, e o motivo em frase fixa.
     *
     * O que entra é a posição e a classe da exceção, nunca a mensagem: quem
     * escreveu a mensagem é o serviço ou o libxml, e ela pode repetir o
     * `docZip`, a chave de acesso ou o caminho do certificado efêmero. Um
     * log que carrega o texto da recusa carrega dado de terceiro.
     */
    private function reportKeptGap(Client $client, FiscalGap $gap, string $reason): void
    {
        Log::warning('fiscal.reconciliacao.lacuna_mantida', [
            'account_id' => (int) $client->account_id,
            'client_id' => (int) $client->getKey(),
            'nsu' => (int) $gap->nsu,
            'tentativas' => (int) $gap->attempts + 1,
            'reason' => $reason,
        ]);
    }
}
