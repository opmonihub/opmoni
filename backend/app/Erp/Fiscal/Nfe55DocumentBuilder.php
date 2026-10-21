<?php

namespace App\Erp\Fiscal;

use App\Erp\BrazilianStateCode;
use DOMDocument;
use DOMElement;
use InvalidArgumentException;

class Nfe55DocumentBuilder
{
    protected const MODEL = '55';

    protected const NS = 'http://www.portalfiscal.inf.br/nfe';

    /** @param array<string, mixed> $source */
    public function validateSource(array $source): void
    {
        app(ErpFiscalSourceSnapshotFactory::class)->validateSource(static::MODEL, $source);
        if (! isset($source['identity']['operation_nature'], $source['identity']['operation_type'], $source['identity']['destination_type'], $source['identity']['presence_indicator'], $source['identity']['print_type'])) {
            throw new InvalidArgumentException('Dados fiscais obrigatórios ausentes: identity.operation_nature/type/destination/presence/print.');
        }
    }

    /** @param array<string, mixed> $source */
    public function build(array $source): string
    {
        $this->validateSource($source);
        $identity = $source['identity'];
        $issuer = $source['issuer'];
        $key = $identity['access_key'];
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;
        $root = $dom->createElementNS(self::NS, 'NFe');
        $dom->appendChild($root);
        $info = $this->node($dom, $root, 'infNFe', null);
        $info->setAttribute('Id', 'NFe'.$key);
        $info->setAttribute('versao', '4.00');

        $ide = $this->node($dom, $info, 'ide', null);
        foreach ([
            'cUF' => $this->ufCode((string) $identity['uf']), 'cNF' => substr($key, 35, 8), 'natOp' => $identity['operation_nature'],
            'mod' => static::MODEL, 'serie' => $identity['series'], 'nNF' => $identity['number'], 'dhEmi' => $identity['issued_at'],
            'tpNF' => $identity['operation_type'], 'idDest' => $identity['destination_type'], 'cMunFG' => $issuer['address']['city_code'],
            'tpImp' => $identity['print_type'], 'tpEmis' => substr($key, 34, 1), 'cDV' => substr($key, 43, 1),
            'tpAmb' => $identity['environment'] === 'production' ? 1 : 2, 'finNFe' => $identity['purpose'] ?? 1,
            'indFinal' => $identity['final_consumer'] ?? 1, 'indPres' => $identity['presence_indicator'], 'procEmi' => 0, 'verProc' => 'Opmoni ERP',
        ] as $name => $value) {
            $this->node($dom, $ide, $name, $value);
        }
        if (static::MODEL === '65' && substr($key, 34, 1) === '9') {
            $this->node($dom, $ide, 'dhCont', $identity['contingency_started_at']);
            $this->node($dom, $ide, 'xJust', $identity['contingency_justification']);
        }
        foreach ($source['references'] ?? [] as $reference) {
            $group = $this->node($dom, $ide, 'NFref', null);
            $this->node($dom, $group, 'refNFe', $reference['access_key']);
        }

        $this->writeParty($dom, $info, 'emit', $issuer, true);
        $this->writeParty($dom, $info, 'dest', $source['recipient'], false);
        foreach ($source['items'] as $index => $item) {
            $det = $this->node($dom, $info, 'det', null);
            $det->setAttribute('nItem', (string) ($index + 1));
            $prod = $this->node($dom, $det, 'prod', null);
            foreach ([
                'cProd' => $item['code'], 'cEAN' => $item['commercial_gtin'] ?? 'SEM GTIN', 'xProd' => $item['description'],
                'NCM' => $item['ncm'], 'CFOP' => $item['cfop'], 'uCom' => $item['unit'], 'qCom' => $item['quantity'],
                'vUnCom' => $item['unit_price'], 'vProd' => $item['gross_total'], 'cEANTrib' => $item['tax_gtin'] ?? $item['commercial_gtin'] ?? 'SEM GTIN',
                'uTrib' => $item['tax_unit'] ?? $item['unit'], 'qTrib' => $item['tax_quantity'] ?? $item['quantity'],
                'vUnTrib' => $item['tax_unit_price'] ?? $item['unit_price'], 'indTot' => $item['ind_total'],
            ] as $name => $value) {
                $this->node($dom, $prod, $name, $value);
            }
            $this->writeTax($dom, $this->node($dom, $det, 'imposto', null), $item['tax']);
        }
        $this->writeTotals($dom, $info, $source['totals']);
        $transp = $this->node($dom, $info, 'transp', null);
        $this->node($dom, $transp, 'modFrete', $source['freight']['modality']);
        $payments = $this->node($dom, $info, 'pag', null);
        foreach ($source['payment'] as $payment) {
            $detail = $this->node($dom, $payments, 'detPag', null);
            $this->node($dom, $detail, 'tPag', $payment['method']);
            $this->node($dom, $detail, 'vPag', $payment['amount']);
        }
        if (static::MODEL === '65') {
            $this->writeQr($dom, $root, [...$source['nfce_qr'], 'invoice_total' => $source['totals']['invoice']], $key, $identity['environment']);
        }

        $xml = $dom->saveXML();
        if (! is_string($xml)) {
            throw new InvalidArgumentException('Não foi possível montar o XML fiscal.');
        }

        return $xml;
    }

    protected function node(DOMDocument $dom, DOMElement $parent, string $name, mixed $value): DOMElement
    {
        $node = $dom->createElementNS(self::NS, $name);
        if ($value !== null) {
            $node->appendChild($dom->createTextNode((string) $value));
        }
        $parent->appendChild($node);

        return $node;
    }

    /** @param array<string, mixed> $party */
    protected function writeParty(DOMDocument $dom, DOMElement $parent, string $name, array $party, bool $issuer): void
    {
        $node = $this->node($dom, $parent, $name, null);
        $identifier = (string) $party['cnpj'];
        if (! preg_match('/^(?:\d{11}|\d{14})$/', $identifier)) {
            throw new InvalidArgumentException('Identificador do participante fiscal deve conter somente CPF ou CNPJ numérico.');
        }
        $this->node($dom, $node, strlen($identifier) === 14 ? 'CNPJ' : 'CPF', $identifier);
        $this->node($dom, $node, 'xNome', $party['legal_name']);
        $address = $this->node($dom, $node, $issuer ? 'enderEmit' : 'enderDest', null);
        foreach ([
            'xLgr' => 'street', 'nro' => 'number', 'xBairro' => 'district', 'cMun' => 'city_code', 'xMun' => 'city',
            'UF' => 'uf', 'CEP' => 'postal_code', 'cPais' => 'country_code', 'xPais' => 'country',
        ] as $xmlName => $field) {
            if (isset($party['address'][$field])) {
                $this->node($dom, $address, $xmlName, $party['address'][$field]);
            }
        }
        if ($issuer) {
            $this->node($dom, $node, 'IE', $party['state_registration']);
            $this->node($dom, $node, 'CRT', $party['tax_regime']);
        } else {
            $this->node($dom, $node, 'indIEDest', $party['state_registration_indicator']);
            if (isset($party['state_registration'])) {
                $this->node($dom, $node, 'IE', $party['state_registration']);
            }
        }
    }

    /** @param array<string, mixed> $tax */
    protected function writeTax(DOMDocument $dom, DOMElement $parent, array $tax): void
    {
        $icms = $this->node($dom, $parent, 'ICMS', null);
        $code = (string) ($tax['icms_cst'] ?? '');
        $group = $this->node($dom, $icms, $code === '00' ? 'ICMS00' : 'ICMS'.$code, null);
        $this->node($dom, $group, 'orig', $tax['origin']);
        $this->node($dom, $group, 'CST', $code);
        $this->node($dom, $group, 'modBC', $tax['icms_base_mode'] ?? 3);
        $this->node($dom, $group, 'vBC', $tax['icms_base']);
        $this->node($dom, $group, 'pICMS', $tax['icms_rate']);
        $this->node($dom, $group, 'vICMS', $tax['icms_value']);
        foreach (['PIS' => 'pis', 'COFINS' => 'cofins'] as $taxName => $prefix) {
            $wrapper = $this->node($dom, $parent, $taxName, null);
            $cst = (string) $tax[$prefix.'_cst'];
            $groupName = in_array($cst, ['04', '05', '06', '07', '08', '09'], true) ? $taxName.'NT' : $taxName.'Outr';
            $group = $this->node($dom, $wrapper, $groupName, null);
            $this->node($dom, $group, 'CST', $cst);
            if ($groupName === $taxName.'Outr') {
                $this->node($dom, $group, 'vBC', $tax[$prefix.'_base'] ?? $tax['icms_base']);
                $this->node($dom, $group, 'p'.$taxName, $tax[$prefix.'_rate'] ?? '0.00');
                $this->node($dom, $group, 'v'.$taxName, $tax[$prefix.'_value']);
            }
        }
    }

    /** @param array<string, mixed> $totals */
    protected function writeTotals(DOMDocument $dom, DOMElement $parent, array $totals): void
    {
        $total = $this->node($dom, $parent, 'total', null);
        $icms = $this->node($dom, $total, 'ICMSTot', null);
        foreach ([
            'vBC' => 'icms_base', 'vICMS' => 'icms', 'vICMSDeson' => 'icms_desonerated', 'vFCP' => 'fcp', 'vBCST' => 'st_base',
            'vST' => 'st', 'vFCPST' => 'fcp_st', 'vFCPSTRet' => 'fcp_st_retained', 'vProd' => 'products', 'vFrete' => 'freight',
            'vSeg' => 'insurance', 'vDesc' => 'discount', 'vII' => 'ii', 'vIPI' => 'ipi', 'vIPIDevol' => 'ipi_returned',
            'vPIS' => 'pis', 'vCOFINS' => 'cofins', 'vOutro' => 'other', 'vNF' => 'invoice', 'vTotTrib' => 'taxes_total',
        ] as $xmlName => $field) {
            $this->node($dom, $icms, $xmlName, $totals[$field]);
        }
    }

    /** @param array<string, mixed> $qr */
    protected function writeQr(DOMDocument $dom, DOMElement $root, array $qr, string $key, string $environment): void
    {
        $supplement = $this->node($dom, $root, 'infNFeSupl', null);
        $amb = $environment === 'production' ? '1' : '2';
        $parts = [$key, '3', $amb];
        if (($qr['mode'] ?? null) === 'offline') {
            $timestamp = strtotime((string) ($qr['emitted_at'] ?? ''));
            if ($timestamp === false || ! isset($qr['offline_signature'], $qr['recipient_type'], $qr['recipient_id'])) {
                throw new InvalidArgumentException('NFC-e offline exige data, destinatário e assinatura QR v3 calculada com o certificado fixado.');
            }
            $recipientType = (string) $qr['recipient_type'];
            $recipientId = (string) $qr['recipient_id'];
            if (! in_array($recipientType, ['1', '2'], true)
                || ! preg_match($recipientType === '2' ? '/^\d{11}$/' : '/^\d{14}$/', $recipientId)) {
                throw new InvalidArgumentException('Destinatário inválido para NFC-e offline.');
            }
            $sourceTotal = (string) ($qr['invoice_total'] ?? '');
            if (! preg_match('/^\d{1,12}\.\d{2}$/', $sourceTotal)) {
                throw new InvalidArgumentException('Valor da NFC-e offline inválido para QR v3.');
            }
            $parts = [...$parts, substr((string) $qr['emitted_at'], 8, 2), $sourceTotal, $recipientType, $recipientId, (string) $qr['offline_signature']];
        } elseif (($qr['mode'] ?? null) !== 'online') {
            throw new InvalidArgumentException('Modo de QR code NFC-e sem suporte.');
        }
        $query = implode('|', $parts);
        $separator = str_contains((string) $qr['consultation_url'], '?') ? '&' : '?';
        // QR v3 defines a pipe-delimited query value; encoding separators changes the protocol payload.
        $this->node($dom, $supplement, 'qrCode', rtrim((string) $qr['consultation_url'], '?&').$separator.'p='.$query);
        $this->node($dom, $supplement, 'urlChave', $qr['consultation_url']);
    }

    private function ufCode(string $uf): string
    {
        return BrazilianStateCode::ibgeFromUf($uf) ?? throw new InvalidArgumentException('UF inválida para emissão fiscal.');
    }
}
