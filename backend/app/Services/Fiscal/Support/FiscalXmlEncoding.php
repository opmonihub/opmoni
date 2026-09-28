<?php

namespace App\Services\Fiscal\Support;

use RuntimeException;

/**
 * Projeção UTF-8 do XML gravado, só para exibição. O byte bruto continua
 * intocado: o que o fisco devolveu é o que está no banco, com o `sha256` que
 * foi calculado sobre ele, e reescrever isso para caber numa tela
 * corromperia a prova do documento.
 *
 * Por isso a conversão olha o byte antes da declaração. Um documento cujos
 * bytes já são UTF-8 não é convertido, mesmo que a declaração diga
 * ISO-8859-1: o `ã` UTF-8 são dois bytes (`0xC3 0xA3`), e o ISO-8859-1 leria
 * esse par como `Ã£`. Converter assim produziria mojibake, que é pior que a
 * etiqueta errada — que a tela não mostra. Quem decide é o byte; a
 * declaração, quando há conversão, é reescrita por coerência.
 *
 * A recusa é deliberada: converter `windows-1252` por heurística é o
 * caminho que troca cada byte ímpar por `?` e mostra um documento fiscal
 * adulterado sem nenhum erro visível. Uma codificação desconhecida não é
 * adivinhada, ela é recusada.
 */
final class FiscalXmlEncoding
{
    /**
     * @return string o XML como UTF-8, com a declaração reescrita
     *
     * @throws RuntimeException quando o byte não é UTF-8 e a declaração não
     *                          autoriza a conversão
     */
    public function forDisplay(string $raw): string
    {
        if (! mb_check_encoding($raw, 'UTF-8')) {
            if (preg_match('/^<\?xml\s+[^?]*encoding=["\']ISO-8859-1["\']/i', $raw) !== 1) {
                throw new RuntimeException('Codificação XML não suportada para prévia.');
            }

            $raw = mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
        }

        return preg_replace('/(<\?xml\s+[^?]*encoding=["\'])ISO-8859-1(["\'])/i', '$1UTF-8$2', $raw) ?? $raw;
    }
}
