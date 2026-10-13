<?php

namespace App\Services\Fiscal\Support;

/**
 * Verificação de integridade do módulo: os dois SHA-1 em base64 que o ambiente
 * nacional calcula sobre o XML do documento.
 *
 * O resumo (`resNFe`) traz um e o documento autorizado
 * (`protNFe/infProt/digVal`) traz o outro; quando batem, o XML completo é o que
 * o ambiente catalogou. Custa zero código de cripto e cobre a classe real de
 * corrupção — resposta truncada, payload misturado, documento trocado.
 */
final class DigValComparison
{
    /**
     * `true` quando os dois digests são iguais, `false` quando são diferentes e
     * `null` quando **não dá para dizer** — um dos lados ausente.
     *
     * A ausência não é divergência: evento não tem `digVal`, e uma captura que
     * começa no meio da fila nunca vê o resumo com que comparar. Ler a ausência
     * como "não confere" marcaria de corrompido todo documento de etapa única.
     *
     * A comparação é simétrica — os dois lados estão no mesmo plano, é a ordem
     * que muda.
     */
    public static function compare(?string $esperado, ?string $encontrado): ?bool
    {
        if ($esperado === null || $esperado === '' || $encontrado === null || $encontrado === '') {
            return null;
        }

        // Base64 diferencia maiúscula de minúscula, então a comparação é
        // estrita: afrouxar aqui trocaria uma divergência real por um veredito
        // de conformidade.
        return $esperado === $encontrado;
    }
}
