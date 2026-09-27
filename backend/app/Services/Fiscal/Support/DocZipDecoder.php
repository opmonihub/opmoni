<?php

namespace App\Services\Fiscal\Support;

use RuntimeException;
use ZipArchive;

final class DocZipDecoder
{
    /**
     * O formato documentado é gZip. O formato alternativo é aceito por
     * liberalidade de implementação observada em produção, então é detectado
     * por magic bytes em vez de assumido.
     */
    public function decode(string $payload): string
    {
        $binary = base64_decode(preg_replace('/\s+/', '', $payload) ?? '', true);

        if ($binary === false || $binary === '') {
            throw new RuntimeException('docZip: base64 inválido.');
        }

        if (str_starts_with($binary, "PK\x03\x04")) {
            return $this->fromZip($binary);
        }

        $xml = $this->inflate($binary);

        if ($xml === null) {
            throw new RuntimeException('docZip: falha ao descompactar (magic='.bin2hex(substr($binary, 0, 4)).').');
        }

        return $xml;
    }

    /**
     * `gzdecode()` só entende o container gZip e recusa o mesmo conteúdo
     * comprimido como zlib (`78 9c`), que é o que `gzcompress()` produz — os
     * dois formatos são distinguíveis, então ambos são aceitos, cada um pela
     * sua função. Deflate puro não tem magic bytes: chamá-lo para um payload
     * corrompido só trocaria a exceção por um aviso de biblioteca, então o
     * formato desconhecido é recusado aqui.
     */
    private function inflate(string $binary): ?string
    {
        if (str_starts_with($binary, "\x1f\x8b")) {
            $xml = @gzdecode($binary);

            return $xml === false ? null : $xml;
        }

        if ($this->isZlib($binary)) {
            $xml = @gzuncompress($binary);

            return $xml === false ? null : $xml;
        }

        return null;
    }

    /**
     * Cabeçalho zlib: método deflate (`CM = 8`), tamanho de janela não maior
     * que 32K (`CINFO <= 7`) e o par de bytes fechando um múltiplo de 31.
     */
    private function isZlib(string $binary): bool
    {
        if (strlen($binary) < 2) {
            return false;
        }

        $cmf = ord($binary[0]);
        $flg = ord($binary[1]);

        return ($cmf & 0x0F) === 0x08
            && ($cmf >> 4) <= 7
            && ((($cmf << 8) | $flg) % 31) === 0;
    }

    private function fromZip(string $binary): string
    {
        $path = tempnam(sys_get_temp_dir(), 'doczip');

        if ($path === false) {
            throw new RuntimeException('docZip: não foi possível criar arquivo temporário.');
        }

        try {
            file_put_contents($path, $binary);

            $zip = new ZipArchive;

            if ($zip->open($path) !== true) {
                throw new RuntimeException('docZip: arquivo compactado ilegível.');
            }

            $name = $zip->getNameIndex(0);

            if ($name === false) {
                $zip->close();

                throw new RuntimeException('docZip: arquivo compactado vazio.');
            }

            $xml = $zip->getFromName($name);
            $zip->close();

            if ($xml === false) {
                throw new RuntimeException('docZip: entrada ilegível no arquivo compactado.');
            }

            return $xml;
        } finally {
            @unlink($path);
        }
    }
}
