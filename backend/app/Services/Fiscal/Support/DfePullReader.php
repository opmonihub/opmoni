<?php

namespace App\Services\Fiscal\Support;

use App\Enums\FiscalFailure;
use App\Enums\FiscalModel;
use App\Services\Fiscal\Contracts\PulledDocument;
use App\Services\Fiscal\Contracts\PullResult;
use App\Services\Fiscal\Exceptions\FiscalException;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * A leitura da resposta de um serviço de distribuição: o que ela vira, e a
 * fronteira entre resultado e exceção.
 *
 * A resposta é a mesma nos dois serviços deste módulo — `retDistDFeInt`, com o
 * mesmo `cStat`, a mesma posição e o mesmo lote — e por isso a regra que decide
 * o que ela significa também é a mesma. Ela mora aqui, e não em cada conector,
 * porque é a regra mais delicada do módulo: dela saem a hora de parada do CNPJ
 * e a autorização — ou a recusa — de gravar a posição.
 *
 * Quatro regras que este arquivo existe para sustentar:
 *
 * 1. **Rejeição é estado, não exceção.** Uma rejeição que manda esperar uma
 *    hora (`137` de nenhum documento localizado, `656` de consumo indevido)
 *    volta como `PullResult` com `blockedUntil` e com a posição que a resposta
 *    trouxe. `FiscalException` fica para o que não é documento e não é espera —
 *    e é a leitura de um ponto único de consulta que devolve `null`, que é uma
 *    terceira coisa: "o serviço diz que não há documento naquela posição".
 * 2. **A posição nunca é somada.** `lastNsu` e `maxNsu` são os valores que a
 *    resposta devolveu, e a posição só é adotada quando a resposta autorizou.
 *    `656` entrega a posição do corpo da rejeição porque é a única alavanca de
 *    recuperação que o serviço oferece; `137` devolve o eco da posição pedida, e
 *    por isso a captura não a adota.
 * 3. **O status HTTP real entrou antes daqui.** O transporte já recusou toda
 *    resposta fora do `2xx`, cada uma com o status verdadeiro na exceção, então
 *    o `classify()` deste arquivo só decide pelo `cStat`, e o `HTTP_OK` diz o
 *    que ele é.
 * 4. **Uma posição que não virou documento não é posição vazia.** `null` aqui
 *    significa "o serviço respondeu que não tem documento"; uma resposta de
 *    "localizado" cujo conteúdo não deu para ler é conteúdo que chegou, e volta
 *    como `RuntimeException` nomeada com a posição — devolvê-la como ausência
 *    marcaria a lacuna como resolvida e a contaria como consultada.
 */
final class DfePullReader
{
    /**
     * Ver `regra 3`: o transporte já classificou o status HTTP real, e o que
     * sobra para a taxonomia é o `cStat` do corpo.
     */
    private const HTTP_OK = 200;

    public function __construct(private DfeEntryCollector $collector) {}

    /**
     * Resposta de captura incremental: o lote, a pausa de uma hora, ou a
     * recusa que exception é.
     *
     * `$model` é a **família** que o conector representa, não o modelo do
     * documento: o conector de CT-e recebe `Cte` e o lote pode trazer CT-e
     * regular, CT-e OS e GTV-e. Quem decide o modelo de cada documento é a
     * chave dele, lá dentro do coletor.
     */
    public function read(DfeResponse $parsed, FiscalModel $model): PullResult
    {
        $failure = FiscalFailure::classify(self::HTTP_OK, $parsed->cStat);

        if ($failure === FiscalFailure::DocumentsFound) {
            return $this->collector->collect($parsed, $model);
        }

        // `137` e a rejeição de consumo indevido são a mesma regra — parar uma
        // hora — e o eixo mora no enum, não neste `if`. Uma indisponibilidade do
        // serviço não entra aqui: ela adianta repetir, e um retry não pode virar
        // uma hora de silêncio por cliente.
        if ($failure->blocksForAnHour()) {
            return new PullResult(
                documents: [],
                lastNsu: $parsed->ultNsu,
                maxNsu: $parsed->maxNsu,
                more: false,
                blockedUntil: $this->blockUntil(),
                // "Nenhum documento localizado" e "consumo indevido" bloqueiam
                // igual e discordam sobre a posição. A primeira não entrega
                // nada, e o que devolve é o eco da posição pedida: a posição
                // armazenada fica intacta. A segunda entrega a posição correta
                // dentro do próprio corpo da rejeição, e é a única alavanca de
                // recuperação que o serviço oferece — descartá-la custaria
                // recomeçar do começo.
                mayAdoptPosition: $failure !== FiscalFailure::NoDocuments,
                // A pausa é a mesma nas duas, então o rótulo é o que separa o
                // esfriamento normal do bloqueio que é problema do cliente. É a
                // palavra da taxonomia, nunca o `xMotivo`: a coluna que a recebe
                // é lida pelo painel e não carrega texto do fisco.
                failure: $failure,
            );
        }

        throw $this->rejection($parsed, $failure);
    }

    /**
     * Resposta de consulta pontual: o documento da posição pedida, `null` quando
     * o serviço diz que não há documento nela, e exceção para o resto.
     *
     * A assimetria com `read()` é a forma, não a regra: a captura devolve um
     * resultado mesmo sem documento — o "nada novo" é um estado que a posição
     * precisa registrar — e a consulta por posição não tem onde registrar nada,
     * então a resposta vazia vira ausência.
     */
    public function readOne(DfeResponse $parsed, FiscalModel $model): ?PulledDocument
    {
        $failure = FiscalFailure::classify(self::HTTP_OK, $parsed->cStat);

        if ($failure === FiscalFailure::NoDocuments) {
            return null;
        }

        // Bloqueio, indisponibilidade e recusa de schema não viram `null`
        // aqui: `null` nesses casos diria "esta posição não existe" e mandaria
        // quem reconcilia procurar a próxima — com o CNPJ bloqueado e o limite
        // horário de consultas sendo gasto.
        if ($failure !== FiscalFailure::DocumentsFound) {
            throw $this->rejection($parsed, $failure);
        }

        $result = $this->collector->collect($parsed, $model);

        if ($result->documents !== []) {
            return $result->documents[0];
        }

        // Regra 4: uma resposta de "localizado" que não virou documento é
        // conteúdo que não deu para ler, e isso não é a mesma coisa que o
        // serviço não ter documento naquela posição. A assinatura do contrato não
        // tem onde carregar a lista de recusas, e um `FiscalFailure` mentiria
        // sobre a origem — a taxonomia classifica o que o *serviço* respondeu, e
        // quem recusou a entrada foi o nosso parse. Então a falha sobe como
        // `RuntimeException` nomeada, com a posição e o mesmo `reason` seguro
        // para log que vai em `FailedEntry`.
        $refused = $result->failures[0];

        throw new RuntimeException("A resposta do serviço traz uma entrada que não pôde ser lida na posição {$refused->nsu}: {$refused->reason}");
    }

    /**
     * A rejeição que não é espera nem ausência: o fisco respondeu, e a resposta
     * não é um documento.
     */
    private function rejection(DfeResponse $parsed, FiscalFailure $failure): FiscalException
    {
        return new FiscalException(
            $parsed->xMotivo === '' ? 'O serviço de distribuição rejeitou a consulta.' : $parsed->xMotivo,
            $failure,
        );
    }

    private function blockUntil(): CarbonImmutable
    {
        return CarbonImmutable::now()->addMinutes((int) config('fiscal.block_minutes', 60));
    }
}
