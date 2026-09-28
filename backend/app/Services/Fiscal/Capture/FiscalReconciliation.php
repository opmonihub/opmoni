<?php

namespace App\Services\Fiscal\Capture;

use App\Enums\FiscalFailure;
use App\Enums\FiscalSource;
use App\Models\Client;
use App\Models\FiscalCursor;
use App\Models\FiscalGap;
use App\Services\Fiscal\Contracts\FiscalConnector;
use App\Services\Fiscal\Contracts\PulledDocument;
use App\Services\Fiscal\Exceptions\FiscalException;
use App\Services\Fiscal\Exceptions\FiscalLookupDeferred;
use Carbon\CarbonImmutable;
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
 *    retrabalho. A única coluna desta tabela que ela escreve é
 *    `blocked_until`, e só quando o fisco manda parar o CNPJ; a única escrita
 *    de documento é a do `FiscalDocumentWriter`.
 * 2. **Uma consulta por buraco, e o teto da hora é o teto da execução.** O
 *    fisco conta consulta pontual no mesmo teto por CNPJ, e uma execução que
 *    estudasse mais buracos do que a hora inteira permite gastaria o orçamento
 *    inteiro e ainda assim tentaria a vaga seguinte, que não existe.
 * 3. **Adiar não é falhar, e parar não é cobrar.** Teto estourado, trava do
 *    próprio CNPJ ocupada e consumo indevido são condições diferentes com a
 *    mesma resposta: nenhuma delas é o fisco dizendo algo sobre aquela posição.
 *    As duas primeiras param a execução sem gastar tentativa, e o consumo
 *    indevido faz o mesmo e ainda grava a pausa de uma hora — a única coluna de
 *    `fiscal_cursors` que esta classe escreve, e ela escreve porque
 *    `blocked_until` é autoritativa para as duas consultas ao mesmo CNPJ.
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
        // A fonte que o conector ligado não serve é recusada aqui, no único
        // lugar por onde a reconciliação fala com o fisco. O job pode ter sido
        // despachado por um comando de outra versão — o conector do CT-e é
        // registrado no container depois, e a fila é longa e sobrevive ao
        // deploy — e um job de CT-e rodando o conector da NF-e consultaria a
        // posição de um serviço pelo outro, e o writer arquivaria a resposta na
        // fonte errada, que é a linha pior possível na tabela. A guarda é a
        // mesma pergunta que `FiscalCaptureService` faz, no mesmo formato, e
        // recusar aqui protege qualquer chamador — hoje só o job.
        if (! $this->hasConnectorFor($source)) {
            $this->reportMismatchedConnector($client, $source);

            return 0;
        }

        return FiscalCaptureLock::run($client, $source, fn (): int => $this->recover($client, $source)) ?? 0;
    }

    /**
     * Se a fonte tem conector nesta versão. Mesma resposta e mesmo formato do
     * `FiscalCaptureService`: quem despacha em lote pergunta antes de encher a
     * fila, e quem executa pergunta de novo antes de gastar orçamento do CNPJ.
     */
    public function hasConnectorFor(FiscalSource $source): bool
    {
        return $this->connector->source() === $source;
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
        // Uma recusa dessas para a execução sem gastar a tentativa.
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
            } catch (FiscalException $exception) {
                if ($exception->failure !== FiscalFailure::Blocked) {
                    // Indisponibilidade, credencial recusada e recusa do schema
                    // são falhas que adiantam repetir, e nenhuma delas é a
                    // resposta "não há documento nesta posição".
                    $this->reportKeptGap($client, $gap, 'consulta sem resposta: '.class_basename($exception));
                    $this->postpone($gap);

                    continue;
                }

                // Consumo indevido, e aqui a parada é do CNPJ inteiro: o fisco
                // mandou que este cliente não seja consultado por uma hora. As
                // posições que ainda faltam na fila recebem a mesma resposta que
                // esta, então continuar seria mandar mais `consNSU` para um CNPJ
                // que acabou de dizer para deixá-lo em paz — é assim que se
                // bloqueia por consumo indevido, e o fisco zera a contagem se a
                // volta vier antes da hora.
                //
                // Nenhuma tentativa é cobrada: o `656` não diz nada sobre a
                // posição, diz que o serviço não responde a este CNPJ agora. E
                // a pausa é gravada, porque a reconciliação é consulta pontual
                // ao mesmo serviço que a captura usa e a parada é do CNPJ, não
                // do caminho — devolvê-la em branco deixaria a captura batting
                // no mesmo bloqueio na mesma noite.
                $this->reportBlocked($client, $gap);
                $this->block($cursor);

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
     * As lacunas devidas, da posição mais antiga para a mais nova, e no máximo o
     * teto de consultas pontuais da hora.
     *
     * Uma lacuna que já gastou as tentativas configuradas não entra: ela continua
     * na tabela, com a posição, as tentativas e a última vez que foi tentada, e
     * é o registro do que o fisco respondeu nessa posição. Ela para de ser
     * consultada, e parou de segurar a posição do cliente também — essa segunda
     * parte é decidida pela captura, e é o que impede que um "não há documento
     * nesta posição" dito três vezes vire um cliente que nunca mais puxa lote.
     *
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
     * O cursor lido, nunca criado: um cliente sem cursor é um cliente que a
     * captura ainda não rodou — o que é diferente de um cliente bloqueado. A
     * reconciliação não anda com a posição dele, e a única coluna que escreve
     * aqui é `blocked_until`, quando o fisco manda parar o CNPJ.
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
     * A janela de parada do fisco gravada no cursor, e a única coluna que esta
     * classe escreve.
     *
     * Mesma coluna, mesma semântica e mesma configuração que a captura usa
     * (`fiscal.block_minutes`): `blocked_until` é autoritativa para as duas
     * consultas, e um CNPJ que a reconciliation descobriu bloqueado e não
     * registrou seria um CNPJ que a captura volta a consultar na mesma noite.
     *
     * A posição, os instantes de execução e o motivo ficam intocados: a
     * reconciliação não anda com nada disso, e `last_run_at` aqui seria
     * mentira — nenhuma captura incremental rodou.
     *
     * Cliente sem cursor é cliente que a captura ainda não capturou, e o
     * caminho que descobre o bloqueio é justamente a reconciliação: sem linha
     * para escrever, a pausa não sobrevive, e o que sobra é o aviso.
     */
    private function block(?FiscalCursor $cursor): void
    {
        if ($cursor === null) {
            return;
        }

        $cursor->forceFill([
            'blocked_until' => CarbonImmutable::now()->addMinutes((int) config('fiscal.block_minutes', 60)),
        ])->save();
    }

    /**
     * O consumo indevido que interrompeu a execução, com a posição que estava
     * em consulta e frase fixa.
     *
     * O `xMotivo` do fisco e o `docZip` que ele devolveu não entram: o que o
     * painel precisa ler é que o CNPJ está bloqueado, e o motivo é o mesmo
     * rótulo fixo que a captura grava.
     */
    private function reportBlocked(Client $client, FiscalGap $gap): void
    {
        Log::warning('fiscal.reconciliacao.consulta_bloqueada', [
            'account_id' => (int) $client->account_id,
            'client_id' => (int) $client->getKey(),
            'nsu' => (int) $gap->nsu,
            'tentativas' => (int) $gap->attempts,
            'reason' => 'consumo indevido: o serviço mandou parar este cliente por uma hora.',
        ]);
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

    /**
     * A recusa de fonte, e a lacuna que por causa dela continua pendente e sem
     * contagem: nada foi perguntado, e uma posição que ninguém perguntou não
     * pode ser considerada tentada.
     *
     * O log existe porque o silêncio é o pior diagnóstico: sem ele, uma entrada
     * despachada para a fonte errada parece uma noite em que não havia buraco
     * nenhum, e a lacuna volta na noite seguinte sem que ninguém entenda por
     * quê. Só entram valores de taxonomia do módulo — conta, cliente, a fonte
     * pedida e a que o conector serve.
     */
    private function reportMismatchedConnector(Client $client, FiscalSource $source): void
    {
        Log::warning('fiscal.reconciliacao.conetor_de_outra_fonte', [
            'account_id' => (int) $client->account_id,
            'client_id' => (int) $client->getKey(),
            'fonte' => $source->value,
            'fonte_do_conector' => $this->connector->source()->value,
        ]);
    }
}
