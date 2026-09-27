<?php

namespace App\Services\Fiscal\Support;

use App\Enums\FiscalKind;
use App\Enums\FiscalModel;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use RuntimeException;
use Throwable;

final class FiscalXmlMetadata
{
    /**
     * Tamanho do prefixo que o `Id` de um evento carrega antes da chave: `ID` +
     * `tpEvento` (6 dígitos), com ou sem o CNPJ do emitente (14 dígitos) no
     * meio. Nenhuma das duas layouts é assumida — as duas são conferidas
     * contra o `nSeqEvento` do próprio documento.
     */
    private const ID_PREFIX_LENGTHS = [6, 20];

    /**
     * Largura máxima do campo `nSeqEvento` dentro do `Id`, pela tipagem da NT.
     * É o que separa as duas layouts: na de 52 dígitos a sequência ocupa 1 ou
     * 2 posições, e na de 66 o prefixo sem o CNPJ deixaria uma sobra de 16.
     */
    private const ID_SEQUENCE_MAX_LENGTH = 10;

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

        $tpEvento = $this->firstText($xpath, ['tpEvento']);
        $nSeqEvento = $this->firstText($xpath, ['nSeqEvento']);

        $chave = $this->firstText($xpath, ['chNFe', 'chCTe'])
            ?? $this->chaveFromId($xpath, $nSeqEvento)
            ?? throw new RuntimeException('O documento capturado não expõe chave de acesso.');

        if (! self::isValidChave($chave)) {
            throw new RuntimeException("Chave de acesso com dígito verificador inválido: {$chave}.");
        }

        $this->guardModel($chave, $model);

        $isEvent = $tpEvento !== null;

        return new FiscalXmlMetadataResult(
            chave: $chave,
            model: $model,
            kind: $isEvent ? FiscalKind::Event : FiscalKind::Document,
            eventId: $isEvent ? $tpEvento.'-'.($nSeqEvento ?? '1') : '',
            schema: $schema,
            emitenteCnpj: $this->firstText($xpath, ['emit/CNPJ', 'prest/CNPJ', 'CNPJ']),
            destinatarioCnpj: $this->firstText($xpath, ['dest/CNPJ', 'toma/CNPJ', 'destinatario/CNPJ']),
            valorTotal: $this->firstText($xpath, ['vNF', 'vTPrest', 'vLiq']),
            // O mesmo digest nas duas etapas da distribuição, em lugares
            // diferentes: o resumo o traz no topo, o documento autorizado no
            // protocolo. O caminho específico vem primeiro porque é o protocolo
            // que o ambiente nacional escreveu, e o topo é o que sobra para o
            // resumo. Evento não tem digest, e aí a coluna fica nula.
            digVal: $this->firstText($xpath, ['protNFe/infProt/digVal', 'protCTe/infProt/digVal', 'digVal']),
            emissaoAt: $this->toDate($this->firstText($xpath, ['dhEmi', 'dhRecbto'])),
            eventoOcorridoEmAt: $this->toDate($this->firstText($xpath, ['dhEvento'])),
        );
    }

    /**
     * A chave carrega o modelo do documento nas posições 21-22, então ele sai
     * dali e não de quem chamou. Um `resCTe` entregue ao conector da NF-e é
     * um documento real e uma etiqueta errada: a unicidade de
     * `(client_id, chave_acesso, event_id)` não o protegeria, porque a chave é
     * de outro documento e entraria sem conflito. Recusar aqui é o que impede
     * que ele seja gravado; a decisão de pular o documento em vez de falhar o
     * lote é do conector, e este erro é nomeado para que ele possa classificá-lo.
     */
    private function guardModel(string $chave, FiscalModel $expected): void
    {
        $code = substr($chave, 20, 2);
        $found = FiscalModel::fromDocumentModel($code);

        if ($found === null) {
            throw new RuntimeException("Chave de acesso com modelo fora do catálogo ({$code}): {$chave}.");
        }

        if ($found !== $expected) {
            throw new RuntimeException("Chave de acesso de {$found->label()} onde se esperava {$expected->label()}: {$chave}.");
        }
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
            $node = XmlQuery::first($xpath, $path);

            if ($node !== null && trim($node->textContent) !== '') {
                return trim($node->textContent);
            }
        }

        return null;
    }

    /**
     * A chave não vem em elemento nenhum do evento: ela é a parte do `Id` que
     * não é `ID`, `tpEvento` nem `nSeqEvento`. O `nSeqEvento` do próprio
     * documento é a âncora — a chave são os 44 dígitos imediatamente antes
     * dele — e é ela que decide entre as duas layouts publicadas do `Id` (com
     * e sem o CNPJ do emitente entre o `tpEvento` e a chave), porque a sobra
     * de uma não pode parecer a sequência da outra.
     *
     * Nenhuma das duas é assumida, e nenhuma janela é testada "até uma
     * fechar": a chave é a identidade do documento, e uma janela vizinha que
     * passa no DV (~1,3% das vezes, com o modelo ainda legível) faria o
     * documento ser gravado sob uma identidade que não existe, sem erro em
     * lugar nenhum. Layout desconhecido recusa, com o mesmo erro de chave
     * ausente.
     */
    private function chaveFromId(DOMXPath $xpath, ?string $nSeqEvento): ?string
    {
        $node = XmlQuery::firstBy($xpath, '//*[@Id]');

        if ($node === null || preg_match('/\d+/', $node->getAttribute('Id'), $matches) !== 1) {
            return null;
        }

        $digits = $matches[0];

        // O `Id` de um documento é a chave pura; o de um evento nunca é.
        if (strlen($digits) === 44) {
            return $digits;
        }

        if ($nSeqEvento === null) {
            return null;
        }

        foreach (self::ID_PREFIX_LENGTHS as $prefix) {
            $candidate = substr($digits, $prefix, 44);
            $sequence = substr($digits, $prefix + 44);

            if (strlen($candidate) !== 44 || $sequence === '' || strlen($sequence) > self::ID_SEQUENCE_MAX_LENGTH) {
                continue;
            }

            // A sequência no `Id` é o valor do XML, com ou sem zero à
            // esquerda — `01` para o `nSeqEvento` 1 que o brief traz.
            if (ltrim($sequence, '0') !== ltrim($nSeqEvento, '0')) {
                continue;
            }

            if (FiscalModel::fromDocumentModel(substr($candidate, 20, 2)) !== null && self::isValidChave($candidate)) {
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
