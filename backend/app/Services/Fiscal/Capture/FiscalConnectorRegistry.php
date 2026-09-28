<?php

namespace App\Services\Fiscal\Capture;

use App\Enums\FiscalSource;
use App\Services\Fiscal\Contracts\FiscalConnector;
use App\Services\Fiscal\Cte\CteDistributionConnector;
use App\Services\Fiscal\Nfe\NfeDistributionConnector;
use RuntimeException;

/**
 * O único lugar do módulo que sabe qual conector serve cada fonte.
 *
 * Ele existe porque a pergunta tem resposta errada fácil de alcançar: com dois
 * serviços de distribuição, qualquer código que pegue "o conector" em vez de
 * "o conector **desta** fonte" fala com o fisco pelo serviço errado e grava a
 * resposta na fonte errada. Um CT-e consultado pelo `.asmx` da NF-e voltaria
 * recusado, e um lote de NF-e arquivado como CT-e seria a linha pior possível na
 * tabela de documentos — sem erro, sem aviso, sem nada para denunciá-la.
 *
 * Por isso o catálogo é declarado aqui e em nenhum outro lugar, e ele tem **uma
 * entrada por fonte**: uma fonte que ninguém cadastrou não recebe o conector de
 * outra — ela recebe a recusa nomeada, tanto no `for()` (que lança) quanto no
 * `has()` (que devolve falso). Um `match` sobre o enum daria o mesmo e ainda erro
 * de compilação para uma fonte nova; o mapa foi escolhido porque é dado, e é o
 * que permite a um teste montar um registro sem uma das fontes.
 *
 * Nenhum valor de `FiscalSource` pode ser chave de array em constante de classe
 * (o enum é um objeto, e chave de objeto não é expressão constante), e é por isso
 * que a chave do catálogo é o `->value` da fonte — o mesmo texto que o cursor, a
 * lacuna e o `--source` do comando carregam.
 */
final class FiscalConnectorRegistry
{
    /**
     * Fonte (o `->value` de `FiscalSource`) => classe do conector. Um conector
     * por fonte, e nenhum conector em duas fontes.
     *
     * @var array<string, class-string<FiscalConnector>>
     */
    private const CATALOGO = [
        'nfe_distribuicao' => NfeDistributionConnector::class,
        'cte_distribuicao' => CteDistributionConnector::class,
    ];

    /**
     * O catálogo é dado, e o de produção é o de cima. A assinatura existe para
     * que um teste consiga construir um registro **sem** uma das fontes — que é
     * a única forma de exercitar a recusa depois que os dois serviços têm
     * conector, já que a recusa é justamente o que nenhum `FiscalSource` do
     * enum consegue provocar mais.
     *
     * O valor do catálogo é a **classe** do conector em produção, resolvida pelo
     * container a cada chamada. Um dublê já pronto também é aceito no lugar da
     * classe, e é por isso que os testes de captura e de reconciliação trocam o
     * conector sem ocupar no container o nome de uma classe `final`.
     *
     * @param  array<string, class-string<FiscalConnector>|FiscalConnector>  $connectors
     */
    public function __construct(private readonly array $connectors = self::CATALOGO) {}

    public function for(FiscalSource $source): FiscalConnector
    {
        $connector = $this->connectors[$source->value] ?? null;

        if ($connector === null) {
            // Alto e nomeado: um ramo padrão devolveria um conector qualquer para
            // uma fonte sem conector, que é a falha que esta classe existe para
            // impedir.
            throw new RuntimeException("A fonte {$source->value} não tem conector registrado.");
        }

        // A classe é o valor de produção e o dublê pronto é o dos testes; a
        // resolução é o container fazendo o que ele faz em todo o resto.
        return $connector instanceof FiscalConnector ? $connector : app($connector);
    }

    /**
     * A mesma pergunta, respondida para quem precisa decidir sem resolver: quem
     * despacha em lote pergunta antes de encher a fila, e quem executa pergunta
     * de novo antes de gastar o orçamento do CNPJ.
     */
    public function has(FiscalSource $source): bool
    {
        return isset($this->connectors[$source->value]);
    }
}
