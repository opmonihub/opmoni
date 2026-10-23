<?php

namespace App\Erp\Fiscal;

use DOMDocument;
use DOMElement;
use InvalidArgumentException;
use RuntimeException;

final class ErpFiscalSchemaValidator
{
    private const ROOT = 'resources/xsd/erp-fiscal';

    /** Validate against an exact request, processed, event, or invalidation entrypoint from the pinned manifest. */
    public function assertValid(string $xml, string $model, string $purpose, ?string $eventType = null): void
    {
        if ($xml === '' || strlen($xml) > 5_000_000 || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            throw new InvalidArgumentException('XML fiscal vazio, excessivo ou com declaração externa.');
        }
        $path = $this->entrypoint($model, $purpose, $eventType);
        $schema = base_path(self::ROOT.'/'.$path);
        if (! is_file($schema)) {
            throw new RuntimeException('Esquema fiscal selecionado não está instalado.');
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $document = new DOMDocument;
            $document->preserveWhiteSpace = true;
            $document->resolveExternals = false;
            $document->substituteEntities = false;
            if (! $document->loadXML($xml, LIBXML_NONET) || ! $document->schemaValidate($schema, LIBXML_NONET)) {
                $diagnostics = [];
                foreach (array_slice(libxml_get_errors(), 0, 5) as $error) {
                    $diagnostics[] = 'libxml '.$error->code.' na linha '.$error->line;
                }
                throw new InvalidArgumentException('XML fiscal não atende ao esquema oficial selecionado. '.implode('; ', $diagnostics));
            }
            if ($purpose === 'event') {
                $payload = $this->eventPayloadDocument($document, $model, (string) $eventType);
                $payloadSchema = base_path(self::ROOT.'/'.$this->entrypoint($model, 'event_payload', $eventType));
                if (! $payload->schemaValidate($payloadSchema, LIBXML_NONET)) {
                    throw new InvalidArgumentException('Conteúdo do evento não atende ao esquema oficial específico.');
                }
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** Validate the road modal subtree embedded in a signed or processed CT-e/MDF-e. */
    public function assertEmbeddedRoadModalValid(string $xml, string $model): void
    {
        $namespace = match ($model) {
            '57' => 'http://www.portalfiscal.inf.br/cte',
            '58' => 'http://www.portalfiscal.inf.br/mdfe',
            default => throw new InvalidArgumentException('Modal rodoviário sem suporte para este modelo.'),
        };
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $document = new DOMDocument;
            $document->resolveExternals = false;
            $document->substituteEntities = false;
            if (! $document->loadXML($xml, LIBXML_NONET)) {
                throw new InvalidArgumentException('XML fiscal inválido para validar o modal rodoviário.');
            }
            $xpath = new \DOMXPath($document);
            $xpath->registerNamespace('f', $namespace);
            $declaredModal = $xpath->query('//f:ide/f:modal')->item(0)?->textContent;
            $roadCode = $model === '57' ? '01' : '1';
            $modalPath = $model === '57' ? '//f:infCte/f:infCTeNorm/f:infModal/f:rodo' : '//f:infMDFe/f:infModal/f:rodo';
            $nodes = $xpath->query($modalPath);
            if ($model === '57' && $xpath->query('//f:ide/f:tpCTe')->item(0)?->textContent === '1') {
                if ($nodes === false || $nodes->length !== 0 || $xpath->query('//f:infCte/f:infCteComp')->length !== 1) {
                    throw new InvalidArgumentException('CT-e complementar exige referência própria sem modal normal.');
                }

                return;
            }
            if ($declaredModal !== $roadCode || $nodes === false || $nodes->length !== 1) {
                throw new InvalidArgumentException('Modal rodoviário declarado não possui um único grupo tipado.');
            }
            $subtree = new DOMDocument('1.0', 'UTF-8');
            $root = $subtree->createElementNS($namespace, 'rodo');
            $subtree->appendChild($root);
            foreach ($nodes->item(0)->childNodes as $child) {
                $root->appendChild($subtree->importNode($child, true));
            }
            $xml = $subtree->saveXML();
            if (! is_string($xml)) {
                throw new InvalidArgumentException('Não foi possível extrair o modal rodoviário tipado.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $this->assertValid($xml, $model, 'road_modal');
    }

    public function entrypoint(string $model, string $purpose, ?string $eventType = null): string
    {
        $files = $this->entrypoints();
        $selected = match ([$model, $purpose, $eventType]) {
            ['55', 'request', null] => 'nfe/55/010f/PL_010f_v1.04/nfe_v4.00.xsd',
            ['55', 'processed', null] => 'nfe/55/010f/PL_010f_v1.04/procNFe_v4.00.xsd',
            ['65', 'request', null] => 'nfce/65/010e/PL_010e_v1.02/NFe/nfe_v4.00.xsd',
            ['65', 'processed', null] => 'nfce/65/010e/PL_010e_v1.02/NFe/procNFe_v4.00.xsd',
            ['55', 'invalidation', null], ['65', 'invalidation', null] => 'shared/nfe/010d/PL_010d_v1.03/NFe/inutNFe_v4.00.xsd',
            ['55', 'processed_invalidation', null], ['65', 'processed_invalidation', null] => 'shared/nfe/010d/PL_010d_v1.03/NFe/procInutNFe_v4.00.xsd',
            ['55', 'event', 'cancel'] => 'events/nfe-cancel/Evento_Canc_PL_v1.01/envEventoCancNFe_v1.00.xsd',
            ['65', 'event', 'cancel'] => 'events/nfe-cancel/Evento_Canc_PL_v1.01/envEventoCancNFe_v1.00.xsd',
            ['55', 'processed_event', 'cancel'], ['65', 'processed_event', 'cancel'] => 'events/nfe-cancel/Evento_Canc_PL_v1.01/procEventoCancNFe_v1.00.xsd',
            ['55', 'event', 'correction'] => 'events/nfe-cce/Evento_CCe_PL_v1.01/envCCe_v1.00.xsd',
            ['55', 'processed_event', 'correction'] => 'events/nfe-cce/Evento_CCe_PL_v1.01/procCCeNFe_v1.00.xsd',
            ['65', 'event', 'substitution_cancel'] => 'events/nfce-substitution-cancel/Evento_CancSubst_v1.01/envEventoCancSubst_v1.00.xsd',
            ['65', 'processed_event', 'substitution_cancel'] => 'events/nfce-substitution-cancel/Evento_CancSubst_v1.01/procEventoCancSubst_v1.00.xsd',
            ['57', 'request', null] => 'cte/57/PL_CTe_400_NT2026.002 RTC_1.01_corr/cte_v4.00.xsd',
            ['57', 'processed', null] => 'cte/57/PL_CTe_400_NT2026.002 RTC_1.01_corr/procCTe_v4.00.xsd',
            ['57', 'road_modal', null] => 'cte/57/PL_CTe_400_NT2026.002 RTC_1.01_corr/cteModalRodoviario_v4.00.xsd',
            ['57', 'event', 'cancel'] => 'cte/57/PL_CTe_400_NT2026.002 RTC_1.01_corr/eventoCTe_v4.00.xsd',
            ['57', 'event', 'correction'] => 'cte/57/PL_CTe_400_NT2026.002 RTC_1.01_corr/eventoCTe_v4.00.xsd',
            ['57', 'processed_event', 'cancel'], ['57', 'processed_event', 'correction'] => 'cte/57/PL_CTe_400_NT2026.002 RTC_1.01_corr/procEventoCTe_v4.00.xsd',
            ['58', 'request', null] => 'mdfe/58/PL_MDFe_300b_NT012025_1.05/mdfe_v3.00.xsd',
            ['58', 'processed', null] => 'mdfe/58/PL_MDFe_300b_NT012025_1.05/procMDFe_v3.00.xsd',
            ['58', 'road_modal', null] => 'mdfe/58/PL_MDFe_300b_NT012025_1.05/mdfeModalRodoviario_v3.00.xsd',
            ['58', 'event', 'cancel'], ['58', 'event', 'close'] => 'mdfe/58/PL_MDFe_300b_NT012025_1.05/eventoMDFe_v3.00.xsd',
            ['58', 'processed_event', 'cancel'], ['58', 'processed_event', 'close'] => 'mdfe/58/PL_MDFe_300b_NT012025_1.05/procEventoMDFe_v3.00.xsd',
            ['55', 'event_payload', 'cancel'], ['65', 'event_payload', 'cancel'] => 'events/nfe-cancel/Evento_Canc_PL_v1.01/e110111_v1.00.xsd',
            ['55', 'event_payload', 'correction'] => 'events/nfe-cce/Evento_CCe_PL_v1.01/e110110_v1.00.xsd',
            ['65', 'event_payload', 'substitution_cancel'] => 'events/nfce-substitution-cancel/Evento_CancSubst_v1.01/e110112_v1.00.xsd',
            ['57', 'event_payload', 'cancel'] => 'cte/57/PL_CTe_400_NT2026.002 RTC_1.01_corr/evCancCTe_v4.00.xsd',
            ['57', 'event_payload', 'correction'] => 'cte/57/PL_CTe_400_NT2026.002 RTC_1.01_corr/evCCeCTe_v4.00.xsd',
            ['58', 'event_payload', 'cancel'] => 'mdfe/58/PL_MDFe_300b_NT012025_1.05/evCancMDFe_v3.00.xsd',
            ['58', 'event_payload', 'close'] => 'mdfe/58/PL_MDFe_300b_NT012025_1.05/evEncMDFe_v3.00.xsd',
            default => throw new InvalidArgumentException('Modelo, evento ou finalidade fiscal sem esquema oficial selecionado.'),
        };
        if (! in_array($selected, $files, true)) {
            throw new RuntimeException('Entry point fiscal não consta do manifesto fixado.');
        }

        return $selected;
    }

    /** @return list<string> */
    private function entrypoints(): array
    {
        static $entrypoints;
        if (is_array($entrypoints)) {
            return $entrypoints;
        }
        $manifestPath = base_path(self::ROOT.'/manifest.json');
        $manifest = is_file($manifestPath) ? json_decode((string) file_get_contents($manifestPath), true) : null;
        if (! is_array($manifest) || ! is_array($manifest['packages'] ?? null)) {
            throw new RuntimeException('Manifesto local de esquemas fiscais ausente ou inválido.');
        }
        $entrypoints = [];
        foreach ($manifest['packages'] as $package) {
            foreach ($package['entrypoints'] ?? [] as $path) {
                if (is_string($path)) {
                    $entrypoints[] = $path;
                }
            }
        }

        return $entrypoints;
    }

    private function eventPayloadDocument(DOMDocument $event, string $model, string $eventType): DOMDocument
    {
        $namespace = match ($model) {
            '55', '65' => 'http://www.portalfiscal.inf.br/nfe', '57' => 'http://www.portalfiscal.inf.br/cte', '58' => 'http://www.portalfiscal.inf.br/mdfe', default => throw new InvalidArgumentException('Modelo de evento desconhecido.')
        };
        $payloadRoot = match ([$model, $eventType]) {
            ['55', 'cancel'], ['65', 'cancel'], ['55', 'correction'], ['65', 'substitution_cancel'] => 'detEvento',
            ['57', 'cancel'] => 'evCancCTe', ['57', 'correction'] => 'evCCeCTe', ['58', 'cancel'] => 'evCancMDFe', ['58', 'close'] => 'evEncMDFe',
            default => throw new InvalidArgumentException('Evento fiscal sem payload XSD selecionado.'),
        };
        $xpath = new \DOMXPath($event);
        $xpath->registerNamespace('f', $namespace);
        $detail = $xpath->query('//*[local-name()="detEvento" and namespace-uri()="'.$namespace.'"]')->item(0);
        if (! $detail instanceof DOMElement) {
            throw new InvalidArgumentException('Envelope de evento não contém conteúdo tipado.');
        }
        $payload = new DOMDocument('1.0', 'UTF-8');
        if (in_array($model, ['57', '58'], true)) {
            $children = [];
            foreach ($detail->childNodes as $child) {
                if ($child instanceof DOMElement) {
                    $children[] = $child;
                }
            }
            if (count($children) !== 1 || $children[0]->localName !== $payloadRoot || $children[0]->namespaceURI !== $namespace) {
                throw new InvalidArgumentException('Evento não contém um único payload oficial esperado.');
            }
            $payload->appendChild($payload->importNode($children[0], true));
        } else {
            $payload->appendChild($payload->importNode($detail, true));
        }

        return $payload;
    }
}
