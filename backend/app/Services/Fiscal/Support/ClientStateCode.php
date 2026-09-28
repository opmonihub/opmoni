<?php

namespace App\Services\Fiscal\Support;

use App\Models\Client;
use App\Services\Fiscal\Exceptions\FiscalClientStateUnknown;

/**
 * O código IBGE da UF do **interessado** — o cliente que pergunta — e não a do
 * serviço que recebe a pergunta.
 *
 * A tabela mora aqui porque é a mesma para todos os serviços de distribuição deste
 * módulo e porque ela precisa ser igual em todos eles: duas cópias divergem sem
 * que nenhum teste de um serviço pegue a diferença, e a divergência apareceria
 * como uma rejeição de schema do fisco para um cliente cujo cadastro está certo.
 *
 * Sigla fora da tabela não vira um código inventado. O serviço aceita qualquer
 * código válido da tabela do IBGE, então um valor inventado passaria pela
 * validação do XSD local e seria aceito: uma afirmação falsa sobre quem pergunta,
 * com nada para denunciá-la. A recusa é `FiscalClientStateUnknown` — da família do
 * `FiscalRequestNotSent`, e não uma `FiscalException` — porque a consulta não
 * chegou a existir: quem chama trata isso como cadastro quebrado, e não como
 * resposta do fisco.
 */
final class ClientStateCode
{
    /**
     * Códigos IBGE das UFs, de `config('fiscal.environment')` para longe: a UF é
     * do cadastro do cliente e não muda com o ambiente do fisco.
     */
    private const UF_CODES = [
        'AC' => 12, 'AL' => 27, 'AP' => 16, 'AM' => 13, 'BA' => 29, 'CE' => 23,
        'DF' => 53, 'ES' => 32, 'GO' => 52, 'MA' => 21, 'MT' => 51, 'MS' => 50,
        'MG' => 31, 'PA' => 15, 'PB' => 25, 'PR' => 41, 'PE' => 26, 'PI' => 22,
        'RJ' => 33, 'RN' => 24, 'RS' => 43, 'RO' => 11, 'RR' => 14, 'SC' => 42,
        'SP' => 35, 'SE' => 28, 'TO' => 17,
    ];

    /**
     * @throws FiscalClientStateUnknown quando a UF do cadastro não está na tabela
     */
    public function of(Client $client): string
    {
        $acronym = strtoupper(trim((string) $client->state));
        $code = self::UF_CODES[$acronym] ?? null;

        if ($code === null) {
            throw new FiscalClientStateUnknown("UF do cliente {$client->tax_id} não está na tabela de UFs: '{$client->state}'.");
        }

        return (string) $code;
    }
}
