<?php

namespace App\Services\Fiscal\Contracts;

use App\Enums\FiscalSource;
use App\Models\Client;

/**
 * Um conector fala com **um** serviço e faz o parse do envelope **dele**.
 *
 * Não escreve no banco: `FiscalDocumentWriter` é o único caminho de escrita, e
 * é por isso que painel e tabela são escritos uma vez só.
 *
 * Rejeição é estado, não exceção, e o `PullResult` é quem carrega essa regra
 * inteira. O conector classifica a rejeição e devolve dentro de um `PullResult`:
 * uma rejeição que bloqueia — `137` de nenhum documento localizado, `656` de
 * consumo indevido — volta como resultado, com `blockedUntil` preenchido e a
 * posição que a resposta traz. `FiscalException` fica para a chamada que não
 * produz resposta alguma, e não para a rejeição que o serviço respondeu.
 */
interface FiscalConnector
{
    public function source(): FiscalSource;

    /**
     * Puxa a partir de `$fromNsu`, no máximo `$limit` documentos.
     */
    public function pull(Client $client, int $fromNsu, int $limit): PullResult;

    /**
     * Recupera um documento pela chave de acesso, para fechar lacuna de
     * posição. O serviço de distribuição do CT-e não oferece esta consulta, e
     * por isso o retorno é anulável.
     */
    public function fetchByChave(Client $client, string $chave): ?PulledDocument;

    /**
     * Recupera o documento de uma posição específica, que é a outra forma de
     * fechar lacuna e a única que o serviço de distribuição do CT-e oferece.
     *
     * É a consulta cara: o fisco conta consulta pontual no mesmo teto por CNPJ
     * que a reconciliação precisa respeitar, então o conector reserva uma vaga
     * desse teto **antes** de qualquer chamada e recusa sem consultar quando o
     * teto acabou.
     *
     * O retorno anulável significa uma coisa só — **o serviço disse que não há
     * documento naquela posição**. Bloqueio, indisponibilidade e rejeição são
     * exceção, porque `null` nesses casos mandaria quem reconcilia marcar a
     * posição como resolvida sem que nada tenha sido resolvido.
     */
    public function fetchByNsu(Client $client, int $nsu): ?PulledDocument;
}
