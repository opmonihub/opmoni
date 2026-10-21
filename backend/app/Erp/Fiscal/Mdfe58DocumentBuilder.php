<?php

namespace App\Erp\Fiscal;

use DOMDocument;
use DOMElement;
use InvalidArgumentException;

final class Mdfe58DocumentBuilder
{
    private const NS = 'http://www.portalfiscal.inf.br/mdfe';

    /** @param array<string, mixed> $source */
    public function validateSource(array $source): void
    {
        app(ErpFiscalSourceSnapshotFactory::class)->validateSource('58', $source);
        $manifest = $source['transport_manifest'];
        foreach (['issuer_type', 'rntrc', 'municipalities'] as $field) {
            if (empty($manifest[$field])) {
                throw new InvalidArgumentException('Dados MDF-e obrigatórios ausentes: transport_manifest.'.$field.'.');
            }
        }
        foreach (['plate', 'uf', 'registry', 'renavam', 'tare', 'capacity_kg', 'road_type', 'trailer_type'] as $field) {
            if (! array_key_exists($field, $manifest['vehicle'] ?? [])) {
                throw new InvalidArgumentException('Dados MDF-e obrigatórios ausentes: transport_manifest.vehicle.'.$field.'.');
            }
        }
        foreach (['name', 'cpf'] as $field) {
            if (empty($manifest['driver'][$field])) {
                throw new InvalidArgumentException('Dados MDF-e obrigatórios ausentes: transport_manifest.driver.'.$field.'.');
            }
        }
        foreach (['total_value', 'quantity', 'unit', 'product_description', 'product_type', 'ncm'] as $field) {
            if (! array_key_exists($field, $manifest['cargo'] ?? [])) {
                throw new InvalidArgumentException('Dados MDF-e obrigatórios ausentes: transport_manifest.cargo.'.$field.'.');
            }
        }
    }

    /** @param array<string, mixed> $source */
    public function build(array $source): string
    {
        $this->validateSource($source);
        $identity = $source['identity'];
        $issuer = $source['issuer'];
        $manifest = $source['transport_manifest'];
        $key = (string) $identity['access_key'];
        $dom = new DOMDocument('1.0', 'UTF-8');
        $root = $dom->createElementNS(self::NS, 'MDFe');
        $dom->appendChild($root);
        $info = $this->node($dom, $root, 'infMDFe', null);
        $info->setAttribute('Id', 'MDFe'.$key);
        $info->setAttribute('versao', '3.00');
        $ide = $this->node($dom, $info, 'ide', null);
        $this->children($dom, $ide, [
            'cUF' => substr($key, 0, 2), 'tpAmb' => $identity['environment'] === 'production' ? 1 : 2,
            'tpEmit' => $manifest['issuer_type'], 'mod' => '58', 'serie' => $identity['series'], 'nMDF' => $identity['number'],
            'cMDF' => substr($key, 35, 8), 'cDV' => substr($key, 43, 1), 'modal' => $manifest['modal'], 'dhEmi' => $identity['issued_at'],
            'tpEmis' => $manifest['emission_type'], 'procEmi' => 0, 'verProc' => 'Opmoni ERP',
            'UFIni' => $manifest['route_ufs'][0], 'UFFim' => $manifest['route_ufs'][count($manifest['route_ufs']) - 1],
        ]);
        foreach ($manifest['municipalities'] as $municipality) {
            $group = $this->node($dom, $ide, 'infMunCarrega', null);
            $this->children($dom, $group, ['cMunCarrega' => $municipality['code'], 'xMunCarrega' => $municipality['name']]);
        }
        foreach (array_slice($manifest['route_ufs'], 1, -1) as $uf) {
            $this->node($dom, $ide, 'infPercurso', null)->appendChild($dom->createElementNS(self::NS, 'UFPer', $uf));
        }
        if (isset($manifest['trip_starts_at'])) {
            $this->node($dom, $ide, 'dhIniViagem', $manifest['trip_starts_at']);
        }
        $emit = $this->node($dom, $info, 'emit', null);
        $this->children($dom, $emit, ['CNPJ' => $issuer['cnpj'], 'IE' => $issuer['state_registration'], 'xNome' => $issuer['legal_name']]);
        $address = $this->node($dom, $emit, 'enderEmit', null);
        foreach (['xLgr' => 'street', 'nro' => 'number', 'xBairro' => 'district', 'cMun' => 'city_code', 'xMun' => 'city', 'CEP' => 'postal_code', 'UF' => 'uf', 'fone' => 'phone'] as $name => $field) {
            if (array_key_exists($field, $issuer['address'])) {
                $this->node($dom, $address, $name, $issuer['address'][$field]);
            }
        }
        $modal = $this->node($dom, $info, 'infModal', null);
        $modal->setAttribute('versaoModal', '3.00');
        $rodo = $this->node($dom, $modal, 'rodo', null);
        $antt = $this->node($dom, $rodo, 'infANTT', null);
        $this->node($dom, $antt, 'RNTRC', $manifest['rntrc']);
        $traction = $this->node($dom, $rodo, 'veicTracao', null);
        $this->children($dom, $traction, [
            'cInt' => $manifest['vehicle']['registry'], 'placa' => $manifest['vehicle']['plate'], 'RENAVAM' => $manifest['vehicle']['renavam'],
            'tara' => $manifest['vehicle']['tare'], 'capKG' => $manifest['vehicle']['capacity_kg'],
        ]);
        $driver = $this->node($dom, $traction, 'condutor', null);
        $this->children($dom, $driver, ['xNome' => $manifest['driver']['name'], 'CPF' => $manifest['driver']['cpf']]);
        $this->children($dom, $traction, ['tpRod' => $manifest['vehicle']['road_type'], 'tpCar' => $manifest['vehicle']['trailer_type'], 'UF' => $manifest['vehicle']['uf']]);
        $doc = $this->node($dom, $info, 'infDoc', null);
        foreach ($manifest['documents'] as $document) {
            $unload = $this->node($dom, $doc, 'infMunDescarga', null);
            $municipality = $this->municipalityFor($manifest['municipalities'], (string) $document['municipality_code']);
            $this->children($dom, $unload, ['cMunDescarga' => $municipality['code'], 'xMunDescarga' => $municipality['name']]);
            $groupName = match ((string) $document['model']) {
                '55', '65' => 'infNFe', '57' => 'infCTe', default => throw new InvalidArgumentException('Documento vinculado não suportado no MDF-e.')
            };
            $this->node($dom, $unload, $groupName, null)->appendChild($dom->createElementNS(self::NS, $groupName === 'infNFe' ? 'chNFe' : 'chCTe', $document['access_key']));
        }
        $product = $this->node($dom, $info, 'prodPred', null);
        $this->children($dom, $product, ['tpCarga' => $manifest['cargo']['product_type'], 'xProd' => $manifest['cargo']['product_description'], 'NCM' => $manifest['cargo']['ncm']]);
        $total = $this->node($dom, $info, 'tot', null);
        $this->children($dom, $total, [
            'qCTe' => $manifest['total_cte'], 'qNFe' => $manifest['total_nfe'], 'qMDFe' => $manifest['total_mdfe'],
            'vCarga' => $manifest['cargo']['total_value'], 'cUnid' => $manifest['cargo']['unit'], 'qCarga' => $manifest['cargo']['quantity'],
        ]);
        $xml = $dom->saveXML();
        if (! is_string($xml)) {
            throw new InvalidArgumentException('Não foi possível montar o XML MDF-e.');
        }

        return $xml;
    }

    /** @param list<array{code: string, name: string}> $municipalities @return array{code: string, name: string} */
    private function municipalityFor(array $municipalities, string $code): array
    {
        foreach ($municipalities as $municipality) {
            if ((string) $municipality['code'] === $code) {
                return $municipality;
            }
        }
        throw new InvalidArgumentException('Município de descarga não foi configurado no manifesto.');
    }

    /** @param array<string, mixed> $values */
    private function children(DOMDocument $dom, DOMElement $parent, array $values): void
    {
        foreach ($values as $name => $value) {
            $this->node($dom, $parent, $name, $value);
        }
    }

    private function node(DOMDocument $dom, DOMElement $parent, string $name, mixed $value): DOMElement
    {
        $node = $dom->createElementNS(self::NS, $name);
        if ($value !== null) {
            $node->appendChild($dom->createTextNode((string) $value));
        }
        $parent->appendChild($node);

        return $node;
    }
}
