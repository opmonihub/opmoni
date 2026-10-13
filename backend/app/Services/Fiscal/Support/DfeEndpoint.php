<?php

namespace App\Services\Fiscal\Support;

use RuntimeException;

/**
 * O bloco de `fiscal.endpoints` de um serviço, conferido **uma vez**, antes de
 * qualquer coisa ser construída a partir dele.
 *
 * A classe existe por um defeito que a duplicação escondia. `endpoint()` em cada
 * conector conferia a chave externa — o serviço está no `config`? — e devolvia o
 * bloco como estava; a partir daí cada um lia o que precisava, e as nove chaves
 * eram nove leituras sem guarda. Um bloco escrito pela metade (`version` sem
 * chave, `xsd_service` trocado, `holder` vazio) produzia "Undefined array key",
 * que o Laravel converte em `ErrorException`: uma exceção que **não** é
 * `RuntimeException` e por isso escapa das cinco guardas de
 * `FiscalReconciliation::recover()`, abandonando as lacunas restantes do cliente.
 *
 * Pior, a leitura Fugia do ponto da guarda: `envelopeFor()` é avaliado como
 * **argumento** de `DfeTransport::request()`, então roda antes de `request()`
 * entrar — uma guarda dentro de `request()` nunca alcançaria a chave que
 * `envelopeFor()` lê. É por isso que a conferência é do bloco inteiro, aqui, e
 * não de cada leitura: o bloco chega conferido, e quem vem depois lê.
 *
 * As nove chaves são conferidas todas, inclusive a URL do ambiente que o
 * processo não vai usar: um bloco sem `homologacao` é um bloco que quebra no dia
 * em que alguém corrigir `FISCAL_ENVIRONMENT`, e o aviso é muito mais barato
 * agora do que no dia seguinte.
 */
final class DfeEndpoint
{
    /**
     * Tudo que um bloco de serviço precisa ter, sem exceção.
     *
     * As duas URLs, os três parâmetros do WSDL, a versão, o método, a ação SOAP,
     * o elemento que embrulha o payload e o schema local que valida esse
     * payload. `config/fiscal.php` declara um bloco por serviço, e a lista é a
     * assinatura desse contrato — é ela que `of()` confere e é ela que o teste
     * percorre chave por chave.
     *
     * @var list<string>
     */
    public const CHAVES = [
        'producao',
        'homologacao',
        'namespace',
        'payload_namespace',
        'version',
        'method',
        'soap_action',
        'holder',
        'xsd_service',
    ];

    /**
     * O bloco de uma fonte, conferido, ou a recusa que diz o que falta.
     *
     * A ausência do serviço inteiro é a mensagem que já existia — "não
     * configurado" é defeito de versão, e quem lê o erro precisa saber que o
     * `config` não conhece a fonte, e não que um campo dela está vazio.
     *
     * @param  array<string, mixed>  $endpoints
     * @return array<string, string>
     */
    public static function of(array $endpoints, string $source): array
    {
        $block = $endpoints[$source] ?? null;

        if (! is_array($block)) {
            throw new RuntimeException('Endpoint de distribuição não configurado.');
        }

        foreach (self::CHAVES as $chave) {
            self::value($block, $chave);
        }

        /** @var array<string, string> */
        return $block;
    }

    /**
     * Uma chave do bloco pelo nome, ou a recusa que nomeia a chave.
     *
     * É a única leitura de um endpoint em todo o módulo, e ela existe para duas
     * coisas: conferir o bloco inteiro antes de construir qualquer coisa
     * (`of()`) e, no caminho do fio, não confiar em quem entregou o array — o
     * transporte é a última linha antes do byte, e recusa por nome é o que o
     * resto do módulo trata como defeito de configuração.
     *
     * @param  array<string, mixed>  $block
     */
    public static function value(array $block, string $chave): string
    {
        $valor = $block[$chave] ?? null;

        if (! is_string($valor) || $valor === '') {
            throw new RuntimeException("Bloco de endpoint sem o parâmetro '{$chave}'.");
        }

        return $valor;
    }
}
