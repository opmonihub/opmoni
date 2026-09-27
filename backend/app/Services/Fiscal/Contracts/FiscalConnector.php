<?php

namespace App\Services\Fiscal\Contracts;

use App\Enums\FiscalSource;
use App\Models\Client;

/**
 * Um conector fala com **um** serviço e faz o parse do envelope **dele**.
 *
 * Não escreve no banco: `FiscalDocumentWriter` é o único caminho de escrita, e
 * é por isso que painel e tabela são escritos uma vez só. O conector também
 * não decide o que fazer com uma rejeição — ele a classifica e a levanta, e o
 * consumidor decide entre repetir, bloquear e parar.
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
}
