<?php

namespace App\Services;

/**
 * Texto legível extraído do PDF do SITFIS, sem persistir o documento.
 *
 * O provedor devolve o relatório só em base64; certidão, emissão, validade e
 * situação fiscal moram no corpo do PDF. A extração tenta literais entre
 * parênteses e fluxos FlateDecode — o suficiente para os rótulos que a Receita
 * repete no modelo publicado, sem carregar uma biblioteca de PDF inteira.
 */
final class SerproSitfisPdfText
{
    /**
     * @return non-empty-string|null
     */
    public function extract(string $pdfBase64): ?string
    {
        $pdfBase64 = preg_replace('/\s+/', '', $pdfBase64) ?? '';

        if ($pdfBase64 === '') {
            return null;
        }

        $pdf = base64_decode($pdfBase64, true);

        if ($pdf === false || $pdf === '') {
            return null;
        }

        $partes = [];
        $literais = $this->literaisEntreParenteses($pdf);

        if ($literais !== '') {
            $partes[] = $literais;
        }

        $descompactado = $this->fluxosFlate($pdf);

        if ($descompactado !== '') {
            $partes[] = $descompactado;
        }

        $texto = trim(preg_replace('/\s+/u', ' ', implode(' ', $partes)) ?? '');

        return $texto === '' ? null : $texto;
    }

    private function literaisEntreParenteses(string $pdf): string
    {
        $trechos = [];

        if (preg_match_all('/\((?:\\\\.|[^\\\\)])*+\)/s', $pdf, $matches)) {
            foreach ($matches[0] as $match) {
                $conteudo = substr($match, 1, -1);
                $conteudo = preg_replace('/\\\\([nrtbf()\\\\])/', "\n", $conteudo) ?? $conteudo;
                $conteudo = str_replace(['\\n', '\\r', '\\t'], [' ', ' ', ' '], $conteudo);
                // O relatório SITFIS é escrito em Latin-1 pelo gerador iText —
                // um `preg_match` `iu` quebraria nos bytes `\xC9`/`\xC7`.
                $conteudo = mb_convert_encoding($conteudo, 'UTF-8', 'ISO-8859-1');
                $trechos[] = $conteudo;
            }
        }

        return trim(implode(' ', $trechos));
    }

    private function fluxosFlate(string $pdf): string
    {
        $texto = '';

        if (! preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $matches)) {
            return '';
        }

        foreach ($matches[1] as $stream) {
            if (! str_contains($pdf, 'FlateDecode')) {
                continue;
            }

            $inflado = @gzuncompress($stream);

            if ($inflado === false) {
                $inflado = @zlib_decode($stream);
            }

            if ($inflado === false) {
                continue;
            }

            $texto .= ' '.$this->literaisEntreParenteses($inflado);
        }

        return trim($texto);
    }
}
