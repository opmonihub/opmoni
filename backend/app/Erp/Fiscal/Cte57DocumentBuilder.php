<?php

namespace App\Erp\Fiscal;

use DOMDocument;
use DOMElement;
use InvalidArgumentException;

final class Cte57DocumentBuilder
{
    private const NS = 'http://www.portalfiscal.inf.br/cte';

    /** @param array<string, mixed> $source */
    public function validateSource(array $source): void
    {
        app(ErpFiscalSourceSnapshotFactory::class)->validateSource('57', $source);
        $service = $source['transport_service'];
        foreach (['origin_city_code', 'destination_city_code', 'origin_uf', 'destination_uf'] as $field) {
            if (empty($service['route'][$field])) {
                throw new InvalidArgumentException('Dados CT-e obrigatórios ausentes: transport_service.route.'.$field.'.');
            }
        }
        foreach (['origin_city', 'destination_city', 'modal', 'pickup'] as $field) {
            if (! array_key_exists($field, $service['route'] ?? []) && ! array_key_exists($field, $service)) {
                throw new InvalidArgumentException('Dados CT-e obrigatórios ausentes: transport_service.route.'.$field.'.');
            }
        }
        foreach (['sender', 'receiver'] as $role) {
            foreach (['cnpj', 'legal_name', 'city_code', 'uf'] as $field) {
                if (! array_key_exists($field, $service[$role] ?? [])) {
                    throw new InvalidArgumentException('Dados CT-e obrigatórios ausentes: transport_service.'.$role.'.'.$field.'.');
                }
            }
            foreach (['street', 'number', 'district', 'city_code', 'city', 'uf', 'postal_code'] as $field) {
                if (empty($service[$role]['address'][$field])) {
                    throw new InvalidArgumentException('Dados CT-e obrigatórios ausentes: transport_service.'.$role.'.address.'.$field.'.');
                }
            }
        }
        foreach (['description', 'quantity', 'unit', 'value'] as $field) {
            if (! array_key_exists($field, $service['cargo'] ?? [])) {
                throw new InvalidArgumentException('Dados CT-e obrigatórios ausentes: transport_service.cargo.'.$field.'.');
            }
        }
        if (! in_array((int) ($service['taker']['role'] ?? -1), [0, 1, 2, 3, 4], true)) {
            throw new InvalidArgumentException('Papel do tomador CT-e sem suporte.');
        }
        if ((int) $service['taker']['role'] === 4) {
            foreach (['cnpj', 'legal_name'] as $field) {
                if (empty($service['taker'][$field])) {
                    throw new InvalidArgumentException('Dados CT-e obrigatórios ausentes: transport_service.taker.'.$field.'.');
                }
            }
            foreach (['street', 'number', 'district', 'city_code', 'city', 'uf', 'postal_code'] as $field) {
                if (empty($service['taker']['address'][$field])) {
                    throw new InvalidArgumentException('Dados CT-e obrigatórios ausentes: transport_service.taker.address.'.$field.'.');
                }
            }
        }
        if (in_array((int) ($service['cte_type'] ?? 0), [1, 3], true) && empty($service['original_cte_access_key'])) {
            throw new InvalidArgumentException('CT-e substituto exige a chave de acesso do CT-e original.');
        }
    }

    /** @param array<string, mixed> $source */
    public function build(array $source): string
    {
        $this->validateSource($source);
        $identity = $source['identity'];
        $issuer = $source['issuer'];
        $service = $source['transport_service'];
        $dom = new DOMDocument('1.0', 'UTF-8');
        $root = $dom->createElementNS(self::NS, 'CTe');
        $dom->appendChild($root);
        $info = $this->node($dom, $root, 'infCte', null);
        $info->setAttribute('Id', 'CTe'.$identity['access_key']);
        $info->setAttribute('versao', '4.00');
        $ide = $this->node($dom, $info, 'ide', null);
        $key = (string) $identity['access_key'];
        $this->children($dom, $ide, [
            'cUF' => substr($key, 0, 2), 'cCT' => substr($key, 35, 8), 'CFOP' => $service['cfop'], 'natOp' => $service['operation_nature'],
            'mod' => '57', 'serie' => $identity['series'], 'nCT' => $identity['number'], 'dhEmi' => $identity['issued_at'],
            'tpImp' => $identity['print_type'] ?? 1, 'tpEmis' => $service['emission_type'], 'cDV' => substr($key, 43, 1),
            'tpAmb' => $identity['environment'] === 'production' ? 1 : 2, 'tpCTe' => $service['cte_type'], 'procEmi' => 0, 'verProc' => 'Opmoni ERP',
            'cMunEnv' => $issuer['address']['city_code'], 'xMunEnv' => $issuer['address']['city'], 'UFEnv' => $issuer['address']['uf'],
            'modal' => $service['modal'], 'tpServ' => $service['service_type'], 'cMunIni' => $service['route']['origin_city_code'],
            'xMunIni' => $service['route']['origin_city'], 'UFIni' => $service['route']['origin_uf'], 'cMunFim' => $service['route']['destination_city_code'],
            'xMunFim' => $service['route']['destination_city'], 'UFFim' => $service['route']['destination_uf'], 'retira' => $service['route']['pickup'] ?? $service['pickup'],
            'indIEToma' => $service['taker']['state_registration_indicator'] ?? 1,
        ]);
        if ((int) $service['taker']['role'] === 4) {
            $taker = $this->node($dom, $ide, 'toma4', null);
            $identifier = (string) $service['taker']['cnpj'];
            if (! preg_match('/^(?:\d{11}|\d{14})$/', $identifier)) {
                throw new InvalidArgumentException('Identificador do tomador CT-e deve conter somente CPF ou CNPJ numérico.');
            }
            $this->children($dom, $taker, ['toma' => 4, strlen($identifier) === 14 ? 'CNPJ' : 'CPF' => $identifier, 'IE' => $service['taker']['state_registration'] ?? null, 'xNome' => $service['taker']['legal_name']]);
            $address = $this->node($dom, $taker, 'enderToma', null);
            $this->address($dom, $address, $service['taker']['address']);
        } else {
            $taker = $this->node($dom, $ide, 'toma3', null);
            $this->node($dom, $taker, 'toma', (int) $service['taker']['role']);
        }
        $this->party($dom, $info, 'emit', $issuer, true);
        foreach (['sender' => 'rem', 'receiver' => 'dest'] as $keyName => $xmlName) {
            $this->party($dom, $info, $xmlName, $service[$keyName]);
        }
        $prest = $this->node($dom, $info, 'vPrest', null);
        $this->children($dom, $prest, ['vTPrest' => $service['service_value'], 'vRec' => $service['received_value']]);
        foreach ($service['components'] ?? [] as $component) {
            $row = $this->node($dom, $prest, 'Comp', null);
            $this->children($dom, $row, ['xNome' => $component['name'], 'vComp' => $component['value']]);
        }
        $imp = $this->node($dom, $info, 'imp', null);
        $icms = $this->node($dom, $imp, 'ICMS', null);
        $tax = $service['tax'];
        if (($tax['cst'] ?? null) !== '00') {
            throw new InvalidArgumentException('Grupo ICMS de CT-e sem builder validado para o CST informado.');
        }
        $group = $this->node($dom, $icms, 'ICMS00', null);
        $this->children($dom, $group, ['CST' => $tax['cst'], 'vBC' => $tax['base'], 'pICMS' => $tax['rate'], 'vICMS' => $tax['value']]);
        if ((int) $service['cte_type'] === 1) {
            $complement = $this->node($dom, $info, 'infCteComp', null);
            $this->node($dom, $complement, 'chCTe', $service['original_cte_access_key']);

            return (string) $dom->saveXML();
        }
        $norm = $this->node($dom, $info, 'infCTeNorm', null);
        $load = $this->node($dom, $norm, 'infCarga', null);
        $this->children($dom, $load, ['vCarga' => $service['cargo']['value'], 'proPred' => $service['cargo']['description']]);
        $quantity = $this->node($dom, $load, 'infQ', null);
        $this->children($dom, $quantity, ['cUnid' => $service['cargo']['unit'], 'tpMed' => $service['cargo']['measure_type'] ?? $service['cargo']['description'], 'qCarga' => $service['cargo']['quantity']]);
        if (($service['documents'] ?? []) !== []) {
            $documents = $this->node($dom, $norm, 'infDoc', null);
            foreach ($service['documents'] as $document) {
                if (($document['type'] ?? null) !== 'nfe') {
                    throw new InvalidArgumentException('Documento originário CT-e sem grupo tipado suportado.');
                }
                $reference = $this->node($dom, $documents, 'infNFe', null);
                $this->node($dom, $reference, 'chave', $document['access_key']);
            }
        }
        $modal = $this->node($dom, $norm, 'infModal', null);
        $modal->setAttribute('versaoModal', '4.00');
        $road = $this->node($dom, $modal, 'rodo', null);
        $this->node($dom, $road, 'RNTRC', $service['rntrc']);
        if ((int) $service['cte_type'] === 3) {
            $substitution = $this->node($dom, $norm, 'infCteSub', null);
            $this->node($dom, $substitution, 'chCte', $service['original_cte_access_key']);
        }
        $doc = $dom->saveXML();
        if (! is_string($doc)) {
            throw new InvalidArgumentException('Não foi possível montar o XML CT-e.');
        }

        return $doc;
    }

    /** @param array<string, mixed> $party */
    private function party(DOMDocument $dom, DOMElement $parent, string $name, array $party, bool $issuer = false): void
    {
        $node = $this->node($dom, $parent, $name, null);
        $identifier = (string) $party['cnpj'];
        if (! preg_match('/^(?:\d{11}|\d{14})$/', $identifier)) {
            throw new InvalidArgumentException('Identificador do participante CT-e deve conter somente CPF ou CNPJ numérico.');
        }
        $this->children($dom, $node, [strlen($identifier) === 14 ? 'CNPJ' : 'CPF' => $identifier, 'IE' => $party['state_registration'] ?? null, 'xNome' => $party['legal_name']]);
        $addressName = match ($name) {
            'emit' => 'enderEmit', 'rem' => 'enderReme', 'dest' => 'enderDest', 'exped' => 'enderExped', 'receb' => 'enderReceb', default => throw new InvalidArgumentException('Grupo de participante CT-e desconhecido.')
        };
        $address = $this->node($dom, $node, $addressName, null);
        $addressData = $party['address'] ?? [];
        $this->address($dom, $address, $addressData, $issuer);
        if ($issuer) {
            $this->node($dom, $node, 'CRT', $party['tax_regime']);
        }
    }

    /** @param array<string, mixed> $address */
    private function address(DOMDocument $dom, DOMElement $parent, array $address, bool $issuer = false): void
    {
        $this->children($dom, $parent, ['xLgr' => $address['street'], 'nro' => $address['number'], 'xBairro' => $address['district'], 'cMun' => $address['city_code'], 'xMun' => $address['city'], 'CEP' => $address['postal_code'], 'UF' => $address['uf']]);
        if (! $issuer) {
            $this->children($dom, $parent, ['cPais' => $address['country_code'] ?? '1058', 'xPais' => $address['country'] ?? 'Brasil']);
        }
        if (isset($address['phone'])) {
            $this->node($dom, $parent, 'fone', $address['phone']);
        }
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
