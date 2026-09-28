<?php

namespace App\Services\Fiscal\Capture;

use App\Enums\FiscalFailure;
use App\Enums\FiscalSkipReason;
use App\Enums\FiscalSource;
use App\Models\Client;
use App\Models\ClientCertificate;
use App\Models\FiscalCursor;
use App\Models\FiscalGap;
use App\Services\Fiscal\Contracts\FiscalConnector;
use App\Services\Fiscal\Contracts\PullResult;
use App\Services\Fiscal\Exceptions\FiscalException;
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
 *    lote segue, o que já entrou fica, e a posição que não entrou é gravada em
 *    `fiscal_gaps` — é a lacuna que fica, e não a frase do log, que é o que
 *    permite voltar atrás sem que ninguém precise adivinhar onde o buraco
 *    estava.
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
        $outcome = FiscalCaptureLock::run($client, $source, fn (): FiscalCaptureOutcome => $this->run($client, $source));

        // `null` é a chave ocupada, e o que se devolve nesse caso é a posição
        // guardada: quem estava chamando precisa saber de onde a carteira
        // parou, mesmo sem consulta nenhuma.
        return $outcome ?? FiscalCaptureOutcome::skipped(
            FiscalSkipReason::Locked,
            $this->storedPositionOf($client, $source),
        );
    }

    /**
     * Se a fonte tem conector nesta versão. A pergunta é de quem despacha em
     * lote — o comando — porque um job de fonte sem conector rodaria o
     * conector da outra fonte e arquivaria o documento na fonte errada.
     * Quando o conector do CT-e existir, é a resolução de conector que
     * cresce; a pergunta continua a mesma.
     */
    public function hasConnectorFor(FiscalSource $source): bool
    {
        return $this->connector->source() === $source;
    }

    private function run(Client $client, FiscalSource $source): FiscalCaptureOutcome
    {
        $cursor = $this->cursor($client, $source);
        $from = (int) $cursor->last_nsu;

        // O certificado primeiro, entre as três guardas: ele é a única delas cujo
        // motivo não passa, e a carteira precisa de um cliente não capturável
        // distinguível de um cliente que hoje não pode ser consultado.
        $certificate = $client->currentCertificate;

        if ($this->certificateIsUnusable($certificate)) {
            // Sem certificado, sem senha ou com certificado vencido, o painel já
            // descreve o estado pelo próprio certificado. Cliente com senha
            // guardada que não abre é outro estado — o A1 tem de voltar — e sem
            // uma marcação na coluna a carteira veria só "não capturável", que é
            // a mesma palavra de quem nunca enviou certificado. A marcação entra
            // aqui, antes de qualquer requisição e sem mexer na posição: sem
            // senha não há o que consultar, e uma posição que anda por cima de
            // uma consulta que não aconteceu perde documento em silêncio.
            if ($this->passwordIsUndecryptable($certificate)) {
                $cursor->forceFill(['last_error' => 'certificate_reupload'])->save();
            }

            return FiscalCaptureOutcome::skipped(FiscalSkipReason::NoCertificate, $from);
        }

        if ($cursor->isBlocked()) {
            return FiscalCaptureOutcome::skipped(FiscalSkipReason::Blocked, $from);
        }

        if ($cursor->historyIsInterrupted()) {
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

        $batch = $this->storeBatch($client, $source, $result);

        $pending = $this->recordGaps($client, $source, $batch['unread']);

        $this->persistAnswer($cursor, $result, $pending, $from);

        return new FiscalCaptureOutcome(
            ran: true,
            skipReason: null,
            stored: $batch['stored'],
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
     * podem ser gravadas. E `$pending === 0` é o que o disco aceitou: gravar a
     * posição depois de um documento que não entrou é perdê-lo em silêncio, porque
     * a consulta seguinte começa depois dele.
     *
     * `$pending` são as posições que o serviço entregou, que não entraram e que
     * ainda têm reconciliação pela frente. A lacuna **fica gravada** em
     * `fiscal_gaps`, então a posição não está perdida — mas a consulta seguinte
     * começa depois dela, e a captura incremental não volta atrás por posição.
     * Quem volta atrás é a reconciliação, e ela só sabe onde é porque a lacuna
     * foi gravada antes desta linha.
     *
     * `last_seen_at` e `last_success_at` são escritos mesmo com o lote
     * incompleto, e essa é a parte que parece errada e não é: quem precisa do
     * fisco é o serviço, e o serviço está vivo e respondendo. Uma falha de disco
     * local não para o fisco de gerar posições, e fingir que parou produziria um
     * histórico interrompido que ninguém teve.
     *
     * @param  int  $pending  posições entregues que seguem pendentes de reconciliação
     */
    private function persistAnswer(FiscalCursor $cursor, PullResult $result, int $pending, int $from): void
    {
        $positions = count($result->documents) + count($result->failures);

        $cursor->forceFill([
            'last_nsu' => $result->mayAdoptPosition && $pending === 0 ? $result->lastNsu : $from,
            'last_seen_at' => now(),
            'last_success_at' => now(),
            // Duas pausas de uma hora, uma coluna. O lote incompleto vem
            // primeiro porque ele descreve um buraco que alguém precisa
            // reconciliar; depois, o consumo indevido — que para o fisco e
            // também é problema do cliente — ganha um rótulo fixo, o mesmo
            // motivo pelo qual `certificate_reupload` é uma palavra e não uma
            // frase. O esfriamento de `137` não marca nada: ele se repete a
            // cada consulta de um cliente saudável.
            'last_error' => $pending > 0 ? $this->incompleteNote($pending, $positions)
                : ($result->failure === FiscalFailure::Blocked ? 'blocked_consumption' : null),
            // A parada do serviço é um eixo separado da posição: ela vale mesmo no
            // lote que não entrou inteiro, e vale nos dois tipos de pausa. Grava
            // sempre, e zera quando a resposta não trouxe parada, que é o que
            // limpa uma janela já vencida.
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
     * @return array{stored: int, unread: list<int>} documentos gravados e
     *                                               posições entregues que não viraram documento
     */
    private function storeBatch(Client $client, FiscalSource $source, PullResult $result): array
    {
        $stored = 0;
        $unread = [];

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
                $unread[] = $document->nsu;
            }
        }

        // As entradas que o próprio conector recusou nunca chegaram aqui como
        // documento, e continuam sendo buracos a reconciliar: a posição parada
        // é o que faz o serviço reentregar a entrada, e este aviso é o que diz
        // qual delas.
        foreach ($result->failures as $failure) {
            $this->reportUnreadableEntry($client, $failure->nsu, $failure->reason);
            $unread[] = $failure->nsu;
        }

        return ['stored' => $stored, 'unread' => $unread];
    }

    /**
     * As posições que o serviço entregou e que não entraram viram lacuna, e o
     * método devolve quantas delas continuam pendentes de reconciliação.
     *
     * **Uma posição que não veio no lote não é lacuna — e nenhuma varredura de
     * sequência consegue dizer que é.** A distância entre duas posições vistas
     * também é um número, e ela não prova nada: `ultNSU` é a posição do
     * ambiente nacional, e o serviço entrega o que pertence ao CNPJ
     * consultado. A posição do ambiente é maior que a última entrada, e o que
     * está no meio pertence a outro contribuinte. A primeira captura de um
     * cliente com histórico em 100, 5000 e 200000 "acharia" 4 899 posições que
     * não são documento nenhum, e cada uma delas custaria uma consulta por hora
     * ao CNPJ só para o fisco responder "não há documento nesta posição".
     *
     * E não é risco teórico. O `design.md` do change nomeia "outro sistema
     * captura o mesmo CNPJ primeiro" como a principal causa de buraco e diz
     * que **não há defesa técnica, só operacional** — a entrega de cada posição
     * é única por CNPJ, e a posição consumida por outro some. Esse é
     * justamente o buraco que nenhuma varredura enxerga, e é o motivo de a
     * reconciliação existir da forma que existe: ela não caça posição ausente,
     * ela volta atrás da posição que o fisco **entregou** e que nós não
     * conseguimos ler.
     *
     * Por isso a lacuna nasce de duas coisas, e nenhuma delas é ausência: uma
     * entrada que o conector recusou e um documento que o writer não gravou. Nos
     * dois casos o fisco disse que existe documento em tal posição, e a spec
     * pede exatamente isso — recuperar **aquele** documento.
     *
     * E uma posição que já gastou as tentativas configuradas sai da conta sem
     * sair da tabela. A linha fica, com a posição, as tentativas e a última
     * vez que foi tentada, porque é o histórico do que o fisco respondeu. Mas
     * ela não segura mais a posição do cliente: a spec manda parar depois da
     * contagem configurada, e parar de consultar não pode virar parar de
     * capturar — senão um "não há documento nesta posição" dito três vezes
     * deixaria o cliente travado na mesma janela para sempre, sem nenhum
     * documento a perder. A posição é imutável e cresce, então a resposta não
     * muda com o tempo.
     *
     * O teto de `fiscal.batch_limit` limita as linhas gravadas, não a conta. A
     * conta é o número de posições que o serviço entregou e que não entraram, e
     * é ela que segura a posição do cliente: um lote não traz mais entradas do
     * que o tamanho do lote, então o teto não morde, e se morresse o corte seria
     * do registro de recuperação, nunca da integridade do cursor.
     *
     * @param  list<int>  $nsus  posições que o serviço entregou e que não viraram documento
     * @return int quantas delas continuam pendentes de reconciliação
     */
    private function recordGaps(Client $client, FiscalSource $source, array $nsus): int
    {
        $nsus = array_values(array_unique($nsus));

        if ($nsus === []) {
            return 0;
        }

        // Uma consulta por lote, e não uma por entrada: a lista de posições que
        // já esgotaram as tentativas é do cliente e da fonte, e resolver isso
        // aqui deixa a conta decideda antes de percorrer as entradas. O `intval`
        // é o que garante a comparação estrita adiante: `bigint` volta do
        // Postgres como string em geral, e "101" !== 101 silenciosamente
        // contaria uma lacuna esgotada como pendente.
        $esgotadas = FiscalGap::query()
            ->forClientSource($client, $source)
            ->where('attempts', '>=', (int) config('fiscal.reconcile_max_attempts', 3))
            ->pluck('nsu')
            ->map(fn ($nsu): int => (int) $nsu)
            ->all();

        $cap = (int) config('fiscal.batch_limit', 50);
        $gravadas = 0;
        $pendentes = 0;

        foreach ($nsus as $nsu) {
            if (in_array($nsu, $esgotadas, true)) {
                $this->reportSpentGap($client, $nsu);

                continue;
            }

            $pendentes++;

            if ($gravadas < $cap) {
                $this->recordGap($client, $source, $nsu);
                $gravadas++;
            }
        }

        return $pendentes;
    }

    /**
     * A lacuna que saiu da conta, e o cliente que volta a capturar.
     *
     * O que entra é a posição e a frase fixa; nada do que o fisco respondeu
     * entra, porque a linha da lacuna é o registro disso e o log é o aviso de
     * que o registro parou de ser urgente.
     */
    private function reportSpentGap(Client $client, int $nsu): void
    {
        Log::warning('fiscal.capture.lacuna_esgotada', [
            'account_id' => (int) $client->account_id,
            'client_id' => (int) $client->getKey(),
            'nsu' => $nsu,
            'reason' => 'tentativas de reconciliação esgotadas; a posição não segura mais o cursor.',
        ]);
    }

    /**
     * Uma lacuna, ou a que já existe.
     *
     * Reencontrar a mesma posição não cria uma segunda linha e **não zera** o
     * histórico de tentativas: a captura reentrega o mesmo lote enquanto a
     * lacuna estiver aberta, e uma linha que voltasse a zero a cada hora seria
     * uma posição com três vidas Renovadas para sempre. Por isso o
     * `firstOrNew` sem `fill()`.
     *
     * `account_id` é da conta do cliente, e não da conta corrente: a captura
     * roda em comando, em job e em fila longa, e a conta corrente é um
     * singleton que o worker nunca zera.
     */
    private function recordGap(Client $client, FiscalSource $source, int $nsu): void
    {
        $gap = FiscalGap::query()->forClientSource($client, $source)->where('nsu', $nsu)->first();

        if ($gap !== null) {
            return;
        }

        $gap = new FiscalGap([
            'client_id' => $client->getKey(),
            'source' => $source,
            'nsu' => $nsu,
        ]);
        $gap->account_id = $client->account_id;
        $gap->save();
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
     * do resto. Uma posição que já esgotou as tentativas de reconciliação não
     * entra na conta: ela tem linha na lacuna e nenhuma urgência, e contá-la
     * seria descrever um cliente parado por uma posição que ninguém mais vai
     * buscar.
     */
    private function incompleteNote(int $pending, int $total): string
    {
        return sprintf('lote incompleto: %d de %d posições não gravadas.', $pending, $total);
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

    private function certificateIsUnusable(?ClientCertificate $certificate): bool
    {
        if ($certificate === null) {
            return true;
        }

        return $certificate->certificatePassword() === null
            || $certificate->valid_until->isPast();
    }

    /**
     * A coluna tem senha e a senha não abre: `APP_KEY` rotacionada, valor
     * truncado, lixo antigo.
     *
     * A distinção que interessa não é uma exceção para se capturar, e a
     * `DecryptException` não é o sinal dela: `certificatePassword()` já devolve
     * `null` para a coluna vazia e para o payload que não decifra, justamente
     * para que ler credencial em qualquer tela continue sendo uma leitura. O
     * sintoma é o mesmo nos dois casos e o que os separa é a coluna — preenchida
     * e ainda assim sem senha é certificado que o cliente precisa reenviar;
     * vazia é certificado que nunca teve senha.
     *
     * A senha em si não é comparada, registrada nem devolvida: daqui sai um
     * booleano, e para a coluna vai uma palavra de classificação.
     */
    private function passwordIsUndecryptable(?ClientCertificate $certificate): bool
    {
        if ($certificate === null) {
            return false;
        }

        return ($certificate->password_encrypted !== null && $certificate->password_encrypted !== '')
            && $certificate->certificatePassword() === null;
    }
}
