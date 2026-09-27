<?php

namespace App\Services\Fiscal\Capture;

use App\Enums\FiscalSkipReason;
use App\Enums\FiscalSource;
use App\Models\Client;
use App\Models\FiscalCursor;
use App\Services\Fiscal\Contracts\FiscalConnector;
use App\Services\Fiscal\Contracts\PullResult;
use App\Services\Fiscal\Exceptions\FiscalException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * A captura de um cliente: quem é consultável, quem guarda o lote inteiro antes de
 * mexer na posição, e quem impede a posição de avançar.
 *
 * Quatro regras, e a ordem entre elas é a segurança do módulo:
 *
 * 1. **O lote é gravado antes de a posição andar.** Uma interrupção no meio só
 *    repete trabalho, e a repetição é inofensiva porque o documento é
 *    identificado por chave de acesso. Ao contrário, gravar a posição primeiro
 *    perde documento em silêncio: a consulta seguinte nem vai pedi-lo de novo.
 * 2. **A posição é o valor que a resposta devolveu, nunca o valor local mais
 *    um.** E ela só é gravada quando a resposta autorizou (`mayAdoptPosition`) e
 *    o lote inteiro entrou — as duas condições, porque cada uma sozinha mente.
 * 3. **A parada é absoluta e sobrevive entre execuções.** `blocked_until` é coluna
 *    porque retomar antes de completar a hora zera a contagem do fisco e a
 *    reinicia: backoff curto não desbloqueia nunca.
 * 4. **Uma posição que não virou documento é um buraco, não o fim da fila.** O
 *    lote segue, o que já entrou fica, e quem reconcilia o buraco é a posição
 *    parada mais o relatório da entrada ilegível.
 */
final class FiscalCaptureService
{
    /**
     * Teto do texto gravado em `last_error`. O valor é uma classe de exceção mais
     * uma frase fixa, então o corte nunca acontece na prática — ele existe para o
     * caso em que a "classe" não é um identificador: uma classe anônima carrega o
     * arquivo e a linha onde foi criada, e nenhum dos dois pertence a um texto
     * que o painel vai renderizar.
     */
    private const REASON_LIMIT = 200;

    public function __construct(
        private readonly FiscalConnector $connector,
        private readonly FiscalDocumentWriter $writer,
    ) {}

    public function capture(Client $client, FiscalSource $source): FiscalCaptureOutcome
    {
        $lock = Cache::lock($this->lockKey($client, $source), $this->lockSeconds());

        if (! $lock->get()) {
            return FiscalCaptureOutcome::skipped(FiscalSkipReason::Locked, $this->storedPositionOf($client, $source));
        }

        try {
            return $this->run($client, $source);
        } finally {
            $lock->release();
        }
    }

    private function run(Client $client, FiscalSource $source): FiscalCaptureOutcome
    {
        $cursor = $this->cursor($client, $source);
        $from = (int) $cursor->last_nsu;

        // O certificado primeiro, entre as três guardas: ele é a única delas cujo
        // motivo não passa, e a carteira precisa de um cliente não capturável
        // distinguível de um cliente que hoje não pode ser consultado.
        if ($this->certificateIsUnusable($client)) {
            return FiscalCaptureOutcome::skipped(FiscalSkipReason::NoCertificate, $from);
        }

        if ($cursor->blocked_until?->isFuture() === true) {
            return FiscalCaptureOutcome::skipped(FiscalSkipReason::Blocked, $from);
        }

        if ($this->historyIsInterrupted($cursor)) {
            return FiscalCaptureOutcome::skipped(FiscalSkipReason::Interrupted, $from);
        }

        // "Rodamos" é verdade desde antes da chamada: uma resposta que não veio é
        // o serviço recusando ou fora do ar, e nenhum dos dois desfaz que houve
        // tentativa. Já "vimos" só pode ser escrito depois que a resposta chegou,
        // porque uma captura que vem falhando há dois meses precisa continuar
        // parecendo não vista — é esse o dado que a detecção de histórico
        // interrompido existe para achar.
        $cursor->forceFill(['last_run_at' => now()])->save();

        try {
            $result = $this->connector->pull($client, $from, (int) config('fiscal.batch_limit', 50));
        } catch (Throwable $exception) {
            $this->persistFailure($cursor, $exception);

            throw $exception;
        }

        $stored = $this->storeBatch($client, $source, $result);

        $this->persistAnswer($cursor, $result, $stored, $from);

        return new FiscalCaptureOutcome(
            ran: true,
            skipReason: null,
            stored: $stored,
            fromNsu: $from,
            toNsu: (int) $cursor->last_nsu,
        );
    }

    /**
     * O passo que só pode vir depois de todos: a resposta do serviço, gravada no
     * cursor depois que o lote inteiro passou pelo writer.
     *
     * A posição é adotada quando **as duas** condições são verdadeiras, e cada uma
     * sozinha mente. `mayAdoptPosition` é o que o serviço respondeu — a posição
     * depois de um buraco ou a posição de um "nenhum documento localizado" não
     * podem ser gravadas. E `$unread === 0` é o que o disco aceitou: gravar a
     * posição depois de um documento que não entrou é perdê-lo em silêncio, porque
     * a consulta seguinte começa depois dele.
     *
     * `last_seen_at` e `last_success_at` são escritos mesmo com o lote
     * incompleto, e essa é a parte que parece errada e não é: quem precisa do
     * fisco é o serviço, e o serviço está vivo e respondendo. Uma falha de disco
     * local não para o fisco de gerar posições, e fingir que parou produziria um
     * histórico interrompido que ninguém teve.
     */
    private function persistAnswer(FiscalCursor $cursor, PullResult $result, int $stored, int $from): void
    {
        $unread = count($result->documents) - $stored + count($result->failures);

        $cursor->forceFill([
            'last_nsu' => $result->mayAdoptPosition && $unread === 0 ? $result->lastNsu : $from,
            'last_seen_at' => now(),
            'last_success_at' => now(),
            'last_error' => $unread === 0 ? null : $this->incompleteNote($unread, count($result->documents) + count($result->failures)),
            // A parada do serviço é um eixo separado da posição: ela vale mesmo no
            // lote que não entrou inteiro. Grava sempre, e zera quando a resposta
            // não trouxe parada, que é o que limpa uma janela já vencida.
            'blocked_until' => $result->blockedUntil,
        ])->save();
    }

    /**
     * Uma chamada que não produziu resposta nenhuma.
     *
     * A exceção sobe depois disso: quem chamou é quem decide se adianta repetir,
     * e a captura não engole falha de transporte para fingir que rodou. O que
     * fica é a linha de texto que o painel lê, e ela é a mesma de sempre:
     * classe e etapa, nunca a mensagem do serviço.
     */
    private function persistFailure(FiscalCursor $cursor, Throwable $exception): void
    {
        $cursor->forceFill([
            'last_error' => $this->boundedReason($exception, 'falha na consulta ao serviço de distribuição.'),
        ])->save();
    }

    /**
     * Grava o lote inteiro, e para no primeiro documento que não pode ser gravado
     * sem derrubar o resto junto.
     *
     * O que é pegado aqui é `RuntimeException`, e a escolha é deliberada: é o que
     * o writer levanta ao recusar uma chave ou um evento, e é a superclasse de
     * `QueryException` (via `PDOException`) e de `UnableToWriteFile` (via
     * `FilesystemException`). `Error` e `TypeError` passam, porque defeito de
     * programação tem de aparecer em vez de virar entrada ilegível para sempre.
     *
     * O que já foi gravado fica gravado. A gravação é idempotente por chave de
     * acesso, então repetir a captura depois reconstrói o lote sem duplicar nada.
     *
     * @return int quantos documentos entraram
     */
    private function storeBatch(Client $client, FiscalSource $source, PullResult $result): int
    {
        $stored = 0;

        foreach ($result->documents as $document) {
            try {
                $this->writer->store($client, $source, $document);
                $stored++;
            } catch (RuntimeException $exception) {
                $this->reportUnreadableEntry(
                    $client,
                    $document->nsu,
                    'gravação recusada: '.class_basename($exception),
                    $document->chave,
                );
            }
        }

        // As entradas que o próprio conector recusou nunca chegaram aqui como
        // documento, e continuam sendo buracos a reconciliar: a posição parada
        // é o que faz o serviço reentregar a entrada, e este aviso é o que diz
        // qual delas.
        foreach ($result->failures as $failure) {
            $this->reportUnreadableEntry($client, $failure->nsu, $failure->reason);
        }

        return $stored;
    }

    /**
     * Uma entrada ilegível, com a posição que a reconciliação vai procurar.
     *
     * O aviso é o único lugar onde a posição sobrevive: `last_error` tem uma
     * linha por lote e a soma, e a identidade da entrada é o que faz o buraco
     * recuperável em vez de apenas contabilizado. A chave de acesso entra porque
     * é identificador fiscal público; o payload, o `docZip` e a senha do
     * certificado não, e o motivo é a frase que o conector já condensou ou o
     * nome da classe que recusou — nunca a mensagem da exceção.
     */
    private function reportUnreadableEntry(Client $client, int $nsu, string $reason, ?string $chave = null): void
    {
        Log::warning('fiscal.capture.entrada_ilegivel', [
            'account_id' => (int) $client->account_id,
            'client_id' => (int) $client->getKey(),
            'nsu' => $nsu,
            'chave_acesso' => $chave,
            'reason' => $reason,
        ]);
    }

    /**
     * Uma frase por lote, e ela é a soma das posições que não entraram.
     *
     * `last_error` tem uma linha só, então a contagem é o que dá a dimensão e o
     * texto não muda de forma com ela. "Incompleto" é o estado honesto: o serviço
     * respondeu, parte do lote está gravada, e a posição não vai passar por cima
     * do resto.
     */
    private function incompleteNote(int $unread, int $total): string
    {
        return sprintf('lote incompleto: %d de %d posições não gravadas.', $unread, $total);
    }

    /**
     * A coluna `last_error` é lida pelo painel, então é texto de usuário: quem
     * falhou, de que tipo, e em que etapa. A mensagem da exceção não entra — pelo
     * mesmo motivo que `FailedEntry::$reason` não a carrega: quem a escreve é o
     * serviço ou o libxml, o `faultstring` vem da autoridade, e o validador deste
     * módulo cita na mensagem o valor do elemento que reprovou.
     */
    private function boundedReason(Throwable $exception, string $phrase): string
    {
        $reason = class_basename($exception).$this->failureKindOf($exception).' — '.$phrase;

        return mb_strlen($reason) > self::REASON_LIMIT
            ? mb_substr($reason, 0, self::REASON_LIMIT).'…'
            : $reason;
    }

    /**
     * `[cursor_ahead]`, `[unauthorized]`, `[upstream]` — a classificação que o
     * conector já pôs no `FiscalException` e que ninguém estava lendo.
     *
     * Sem ela, a coluna descreve com a mesma frase uma posição que precisa de
     * reconciliação, uma credencial que o serviço recusou e uma indisponibilidade
     * que se resolve sozinha — e as três pedem uma reação diferente de quem vai
     * ler. A reconciliação é a segunda metade da spec do `CursorAhead`, e ela
     * depende de distinguir: o valor gravado é o que este caminho **não** toca,
     * então a posição do cliente continua intacta para ser reconciliada.
     *
     * Nada entra que não seja nosso: o valor do enum é uma palavra da taxonomia do
     * módulo, e é por isso que ele pode ir na coluna onde a mensagem do serviço não
     * pode. Exceção que não é do conector não tem classificação — a
     * `RuntimeException` do writer, por exemplo, já diz a etapa na frase, e o que
     * a recusou é o nome da classe.
     */
    private function failureKindOf(Throwable $exception): string
    {
        return $exception instanceof FiscalException
            ? ' ['.$exception->failure->value.']'
            : '';
    }

    private function cursor(Client $client, FiscalSource $source): FiscalCursor
    {
        $cursor = FiscalCursor::query()->firstOrNew([
            'client_id' => $client->getKey(),
            'source' => $source,
        ]);

        if (! $cursor->exists) {
            // `account_id` fora do `firstOrCreate` de propósito, pelo mesmo motivo
            // que no writer: a coluna não é mass-assignável nos modelos de tenant
            // deste módulo, e o hook de criação a puxaria da conta corrente, que
            // em um comando de console não existe. A dona do cursor é a conta do
            // cliente, e é isso que fica gravado.
            $cursor->account_id = $client->account_id;
            $cursor->save();
        }

        return $cursor;
    }

    /**
     * A posição de um cliente que não chegou a ser consultado por causa do
     * bloqueio, lida sem criar nada.
     *
     * Criar aqui abriria a corrida que a chave existe para fechar: dois
     * workers da mesma carteira fariam `firstOrCreate` na mesma linha ao mesmo
     * tempo, e a leitura é o que o relatório precisa mesmo quando o cursor ainda
     * não existe — nesse caso a posição é zero porque nada foi capturado.
     */
    private function storedPositionOf(Client $client, FiscalSource $source): int
    {
        return (int) FiscalCursor::query()
            ->where('client_id', $client->getKey())
            ->where('source', $source)
            ->value('last_nsu');
    }

    private function certificateIsUnusable(Client $client): bool
    {
        $certificate = $client->currentCertificate;

        if ($certificate === null) {
            return true;
        }

        return $certificate->certificatePassword() === null
            || $certificate->valid_until->isPast();
    }

    /**
     * O fisco não gera posições retroativas para o período que ficou de fora, então
     * uma captura parada além da janela de continuidade não recupera nada: ela
     * apenas produz "nenhum documento localizado" para sempre. A data é a da
     * última vez que o serviço respondeu, e é por isso que ela é escrita depois
     * da chamada.
     */
    private function historyIsInterrupted(FiscalCursor $cursor): bool
    {
        if ($cursor->last_seen_at === null) {
            return false;
        }

        return $cursor->last_seen_at->lt(now()->subDays((int) config('fiscal.continuity_days', 60)));
    }

    /**
     * O bloqueio é de dono, não de tempo: só quem tomou a chave pode devolver, e
     * a janela serve para o worker que morreu no meio do lote. Por isso o TTL é
     * acima do tempo que a chamada pode levar — expirar antes faria a segunda
     * execução começar enquanto a primeira ainda escreve, que é exatamente a
     * consulta paralela que a NT classifica como uso indevido.
     */
    private function lockSeconds(): int
    {
        return (int) config('fiscal.timeout', 60) + 30;
    }

    private function lockKey(Client $client, FiscalSource $source): string
    {
        return "fiscal:capture:{$client->getKey()}:{$source->value}";
    }
}
