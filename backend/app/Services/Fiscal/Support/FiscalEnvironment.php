<?php

namespace App\Services\Fiscal\Support;

use App\Services\Fiscal\Exceptions\FiscalRequestNotSent;

/**
 * A seleção de ambiente do `fiscal.environment`, fail-closed.
 *
 * A config aceita só `producao` e `homologacao`, e qualquer outro valor —
 * typo, `production`, caixa errada — não pode cair em homologação em
 * silêncio: o evento sairia assinado com o A1 real contra o ambiente errado,
 * e nada acusaria o desvio. Um valor fora da lista é `FiscalRequestNotSent`:
 * o pedido não chega a existir para o fisco, que é a mesma taxonomia das
 * outras recusas pré-envio.
 *
 * Vive em `Support` porque a mesma decisão aparece em pontos diferentes da
 * manifestação — o `tpAmb` que o builder escreve no evento e a URL que o
 * transporte escolhe — e dois ternários independentes poderiam divergir.
 */
final class FiscalEnvironment
{
    /**
     * @return 'producao'|'homologacao'
     *
     * @throws FiscalRequestNotSent quando o valor configurado não é um dos
     *                              dois ambientes conhecidos
     */
    public static function selecionar(): string
    {
        $environment = config('fiscal.environment');

        if ($environment === 'producao' || $environment === 'homologacao') {
            return $environment;
        }

        throw new FiscalRequestNotSent(
            'Ambiente fiscal fora da lista conhecida (fiscal.environment).',
        );
    }

    /**
     * O `tpAmb` do leiaute NF-e: `1` em produção, `2` em homologação.
     */
    public static function tpAmb(): string
    {
        return self::selecionar() === 'producao' ? '1' : '2';
    }
}
