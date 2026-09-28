<?php

namespace App\Services\Fiscal\Support;

use RuntimeException;

/**
 * A entrada é uma coisa que o módulo reconhece e que não é um documento a
 * indexar: uma inutilização, um cancelamento antigo, o que for.
 *
 * Ela existe porque **pular** e **recusar** têm consequências opostas, e as duas
 * se confundem na mesma exceção desde que o parser passou a olhar a raiz do XML.
 * O coletor recusa com `mayAdoptPosition: false`, e a recusa é a decisão certa
 * para uma entrada que *deveria* ter virado documento. Para uma entrada que não
 * é documento, a recusa é uma trava: o serviço entrega posições, a posição não
 * avança, a próxima consulta pede o mesmo intervalo e recebe a mesma coisa — para
 * sempre, sem caminho de desistência. Um `inut` no meio do lote travaria a
 * posição do cliente sem que nada disso apareça como erro.
 *
 * Por isso esta exceção é capturada antes da `RuntimeException` genérica e vira
 * um `continue` sem `FailedEntry`: a posição segue, e o que não foi gravado é um
 * documento que este módulo nunca gravaria de qualquer jeito.
 *
 * Ela mora em `Support` e não em `Exceptions` porque a taxonomia de lá é de
 * **falha do serviço** — carrega um `FiscalFailure` e responde "adianta repetir?"
 * — e esta não é uma falha de ninguém: é uma classificação correta.
 */
final class NotIndexableDocument extends RuntimeException
{
    /**
     * A frase é fixa e nomeia a raiz, que é o nome do elemento XML e não texto
     * do serviço. O payload, o `docZip` e qualquer conteúdo do documento ficam
     * de fora: esta mensagem é capturada e descartada no coletor, e a única
     * coisa que sobrevive dela é o controle de fluxo.
     *
     * @param  string  $root  o nome local do elemento raiz
     */
    public function __construct(public readonly string $root)
    {
        parent::__construct("Documento reconhecido que não é indexável: {$root}.");
    }
}
