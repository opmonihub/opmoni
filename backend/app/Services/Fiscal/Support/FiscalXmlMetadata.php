<?php

namespace App\Services\Fiscal\Support;

use App\Enums\FiscalKind;
use App\Enums\FiscalModel;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use Throwable;

final class FiscalXmlMetadata
{
    /**
     * Modelos que ocupam as posições 21-22 da chave de acesso: NF-e, CT-e e
     * NFC-e. Usado só para descartar janelas candidatas do `Id` de um evento.
     */
    private const CHAVE_MODELS = ['55', '57', '65'];

    public function extract(string $xml, FiscalModel $model): FiscalXmlMetadataResult
    {
        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = false;

        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new RuntimeException('O documento capturado não é um XML legível.');
        }

        $xpath = new DOMXPath($dom);
        $schema = $this->schemaOf($dom);

        $chave = $this->firstText($xpath, ['chNFe', 'chCTe'])
            ?? $this->chaveFromId($xpath)
            ?? throw new RuntimeException('O documento capturado não expõe chave de acesso.');

        if (! self::isValidChave($chave)) {
            throw new RuntimeException("Chave de acesso com dígito verificador inválido: {$chave}.");
        }

        $tpEvento = $this->firstText($xpath, ['tpEvento']);
        $isEvent = $tpEvento !== null;

        return new FiscalXmlMetadataResult(
            chave: $chave,
            model: $model,
            kind: $isEvent ? FiscalKind::Event : FiscalKind::Document,
            eventId: $isEvent ? $tpEvento.'-'.($this->firstText($xpath, ['nSeqEvento']) ?? '1') : '',
            schema: $schema,
            emitenteCnpj: $this->firstText($xpath, ['emit/CNPJ', 'prest/CNPJ', 'CNPJ']),
            destinatarioCnpj: $this->firstText($xpath, ['dest/CNPJ', 'toma/CNPJ', 'destinatario/CNPJ']),
            valorTotal: $this->firstText($xpath, ['vNF', 'vTPrest', 'vLiq']),
            emissaoAt: $this->toDate($this->firstText($xpath, ['dhEmi', 'dhRecbto'])),
            eventoOcorridoEmAt: $this->toDate($this->firstText($xpath, ['dhEvento'])),
        );
    }

    /**
     * Dígito verificador módulo 11 sobre os 43 primeiros dígitos, pesos
     * cíclicos de 2 a 9 da direita para a esquerda.
     */
    public static function isValidChave(string $chave): bool
    {
        if (preg_match('/^\d{44}$/', $chave) !== 1) {
            return false;
        }

        $weights = [2, 3, 4, 5, 6, 7, 8, 9];
        $sum = 0;

        for ($i = 42, $w = 0; $i >= 0; $i--, $w++) {
            $sum += ((int) $chave[$i]) * $weights[$w % 8];
        }

        $mod = $sum % 11;
        $expected = $mod < 2 ? 0 : 11 - $mod;

        return $expected === (int) $chave[43];
    }

    private function schemaOf(DOMDocument $dom): string
    {
        $root = $dom->documentElement;

        return $root === null ? '' : $root->nodeName;
    }

    /**
     * @param  list<string>  $paths
     */
    private function firstText(DOMXPath $xpath, array $paths): ?string
    {
        foreach ($paths as $path) {
            $node = $xpath->query($this->expressionFor($path))->item(0);

            if ($node !== null && trim($node->textContent) !== '') {
                return trim($node->textContent);
            }
        }

        return null;
    }

    /**
     * `emit/CNPJ` vira `//*[local-name()="emit"]/*[local-name()="CNPJ"]`: o
     * nome local casa em qualquer namespace, mas cada segmento tem de estar
     * dentro do anterior. Comparar o nome local com a alternativa inteira
     * (`"emit/CNPJ"`) nunca casaria, e com isso morreria todo caminho por
     * modelo — `toma` no CT-e, `dest` na NF-e. Os segmentos são literais
     * desta classe, nunca texto do documento.
     */
    private function expressionFor(string $path): string
    {
        $steps = array_map(
            static fn (string $segment): string => '*[local-name()="'.$segment.'"]',
            explode('/', $path),
        );

        return '//'.implode('/', $steps);
    }

    /**
     * O `Id` de um evento é `ID` + `tpEvento` (6) + `CNPJ` (14) + chave (44) +
     * `nSeqEvento`, então a chave é a janela de 44 dígitos logo após as 20
     * primeiras; o `Id` de um documento é a chave pura. Os 44 primeiros
     * dígitos — o que uma expressão gulosa entregaria — carregariam o
     * `tpEvento` junto e reprovariam no dígito verificador.
     */
    private function chaveFromId(DOMXPath $xpath): ?string
    {
        $node = $xpath->query('//*[@Id]')->item(0);

        if (! $node instanceof DOMElement) {
            return null;
        }

        if (preg_match('/\d+/', $node->getAttribute('Id'), $matches) !== 1) {
            return null;
        }

        $digits = $matches[0];

        if (strlen($digits) === 44) {
            return $digits;
        }

        // O `Id` do evento põe `tpEvento` (e, na NT, o CNPJ) antes da chave e
        // `nSeqEvento` depois, em largura variável — fixar um deslocamento
        // erra. A chave é a última janela antes da sequência, então as janelas
        // são varridas do fim para o começo; cada uma é conferida pelo modelo
        // (`mod` nas posições 21-22) e pelo DV antes de ser aceita.
        for ($offset = strlen($digits) - 44; $offset >= 0; $offset--) {
            $candidate = substr($digits, $offset, 44);

            if (in_array(substr($candidate, 20, 2), self::CHAVE_MODELS, true) && self::isValidChave($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function toDate(?string $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
