<?php

namespace App\Erp\Fiscal;

use App\Erp\BrazilianStateCode;

final class ErpFiscalEventRegistry
{
    /** @var array<string, array<string, array<string, array{code: ?string, schema_model: string}>>> */
    private const EVENTS = [
        '55' => [
            'posted_sale' => ['cancel' => ['code' => '110111', 'schema_model' => '55'], 'correction' => ['code' => '110110', 'schema_model' => '55']],
            'return' => ['cancel' => ['code' => '110111', 'schema_model' => '55']],
            'complement' => ['cancel' => ['code' => '110111', 'schema_model' => '55']],
        ],
        '65' => [
            'posted_sale' => ['cancel' => ['code' => '110111', 'schema_model' => '65'], 'substitution_cancel' => ['code' => '110112', 'schema_model' => '65']],
        ],
        '57' => ['transport_service' => ['cancel' => ['code' => '110111', 'schema_model' => '57'], 'correction' => ['code' => '110110', 'schema_model' => '57']]],
        '58' => ['transport_manifest' => ['cancel' => ['code' => '110111', 'schema_model' => '58'], 'close' => ['code' => '110112', 'schema_model' => '58']]],
    ];

    /** @return list<string> */
    public function supportedEvents(string $model, string $documentKind): array
    {
        return array_keys(self::EVENTS[$model][$documentKind] ?? []);
    }

    public function supports(string $model, string $documentKind, string $eventType): bool
    {
        return isset(self::EVENTS[$model][$documentKind][$eventType]);
    }

    public function eventCode(string $model, string $documentKind, string $eventType): ?string
    {
        return self::EVENTS[$model][$documentKind][$eventType]['code'] ?? null;
    }

    public function supportsInvalidation(string $model, string $documentKind): bool
    {
        return in_array($model, ['55', '65'], true) && in_array($documentKind, ['posted_sale', 'return', 'complement'], true);
    }

    /** @return array{model: string, document_kind: string, event_type: string, event_code: ?string, schema_model: string} */
    public function definition(string $model, string $documentKind, string $eventType): array
    {
        $definition = self::EVENTS[$model][$documentKind][$eventType] ?? null;
        if ($definition === null) {
            throw new \InvalidArgumentException('Evento fiscal não permitido para modelo e tipo de documento.');
        }

        return ['model' => $model, 'document_kind' => $documentKind, 'event_type' => $eventType, 'event_code' => $definition['code'], 'schema_model' => $definition['schema_model']];
    }

    /** @param array<string, mixed> $source */
    public function buildEvent(array $source): string
    {
        $model = (string) ($source['model'] ?? '');
        $definition = $this->definition($model, (string) ($source['document_kind'] ?? ''), (string) ($source['event_type'] ?? ''));
        $type = $source['event_type'];
        $key = (string) ($source['access_key'] ?? '');
        $cnpj = (string) ($source['issuer']['cnpj'] ?? '');
        $sequence = $source['sequence'] ?? null;
        if (! app(ErpFiscalPayloadBuilder::class)->validatesAccessKey($key) || substr($key, 6, 14) !== $cnpj || substr($key, 20, 2) !== $model
            || ! in_array($source['environment'] ?? null, ['production', 'homologation'], true)
            || ! is_int($sequence) || $sequence < 1 || $sequence > ($model === '55' || $model === '65' ? 20 : 999)
            || strtotime((string) ($source['occurred_at'] ?? '')) === false || ! preg_match('/^\d{15}$/', (string) ($source['protocol'] ?? ''))) {
            throw new \InvalidArgumentException('Identidade, protocolo, ambiente, data ou sequência do evento inválidos.');
        }
        if (in_array($type, ['cancel', 'substitution_cancel'], true) && (mb_strlen(trim((string) ($source['justification'] ?? ''))) < 15 || mb_strlen($source['justification']) > 255)) {
            throw new \InvalidArgumentException('Cancelamento exige justificativa de 15 a 255 caracteres.');
        }
        if ($type === 'correction' && ($source['legal_scope_attested'] ?? false) !== true) {
            throw new \InvalidArgumentException('Confirme o escopo legal permitido para a carta de correção.');
        }
        $ns = $this->namespace($model);
        $version = match ($model) {
            '55', '65' => '1.00', '57' => '4.00', '58' => '3.00'
        };
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $root = $dom->createElementNS($ns, match ($model) {
            '55', '65' => 'envEvento', '57' => 'eventoCTe', '58' => 'eventoMDFe'
        });
        $root->setAttribute('versao', $version);
        $dom->appendChild($root);
        $event = $root;
        if (in_array($model, ['55', '65'], true)) {
            $this->node($dom, $root, 'idLote', 1);
            $event = $this->node($dom, $root, 'evento');
            $event->setAttribute('versao', $version);
        }
        $info = $this->node($dom, $event, 'infEvento');
        $sequenceWidth = $model === '57' ? 3 : 2;
        $info->setAttribute('Id', 'ID'.$definition['event_code'].$key.str_pad((string) $sequence, $sequenceWidth, '0', STR_PAD_LEFT));
        $this->nodes($dom, $info, ['cOrgao' => substr($key, 0, 2), 'tpAmb' => $source['environment'] === 'production' ? 1 : 2, 'CNPJ' => $cnpj,
            match ($model) {
                '55', '65' => 'chNFe', '57' => 'chCTe', '58' => 'chMDFe'
            } => $key,
            'dhEvento' => $source['occurred_at'], 'tpEvento' => $definition['event_code'], 'nSeqEvento' => $sequence]);
        if (in_array($model, ['55', '65'], true)) {
            $this->node($dom, $info, 'verEvento', $version);
        }
        $detail = $this->node($dom, $info, 'detEvento');
        $detail->setAttribute(in_array($model, ['55', '65'], true) ? 'versao' : 'versaoEvento', $version);
        if (in_array($model, ['57', '58'], true)) {
            $detail = $this->node($dom, $detail, match ([$model, $type]) {
                ['57', 'cancel'] => 'evCancCTe', ['57', 'correction'] => 'evCCeCTe', ['58', 'cancel'] => 'evCancMDFe', ['58', 'close'] => 'evEncMDFe'
            });
        }
        $this->node($dom, $detail, 'descEvento', match ($type) {
            'cancel' => 'Cancelamento', 'substitution_cancel' => 'Cancelamento por substituicao', 'correction' => 'Carta de Correcao', 'close' => 'Encerramento'
        });
        if ($type === 'substitution_cancel') {
            $replacement = (string) ($source['replacement_access_key'] ?? '');
            if (! app(ErpFiscalPayloadBuilder::class)->validatesAccessKey($replacement) || substr($replacement, 20, 2) !== '65' || substr($replacement, 6, 14) !== $cnpj || $replacement === $key) {
                throw new \InvalidArgumentException('Cancelamento por substituição exige outra NFC-e válida do mesmo emissor.');
            }
            $this->nodes($dom, $detail, ['cOrgaoAutor' => substr($key, 0, 2), 'tpAutor' => 1, 'verAplic' => 'Opmoni ERP']);
        }
        if (in_array($type, ['cancel', 'substitution_cancel', 'close'], true)) {
            $this->node($dom, $detail, 'nProt', $source['protocol']);
        }
        if (in_array($type, ['cancel', 'substitution_cancel'], true)) {
            $this->node($dom, $detail, 'xJust', $source['justification']);
        }
        if ($type === 'substitution_cancel') {
            $this->node($dom, $detail, 'chNFeRef', $source['replacement_access_key']);
        }
        if ($type === 'correction') {
            if ($model === '55') {
                $text = trim((string) ($source['correction'] ?? ''));
                if (mb_strlen($text) < 15 || mb_strlen($text) > 1000) {
                    throw new \InvalidArgumentException('Carta de correção NF-e exige texto de 15 a 1000 caracteres.');
                }
                $this->node($dom, $detail, 'xCorrecao', $text);
            } else {
                $corrections = $source['corrections'] ?? [];
                if (! is_array($corrections) || $corrections === [] || count($corrections) > 20) {
                    throw new \InvalidArgumentException('Carta de correção CT-e exige grupos de correção tipados.');
                }
                foreach ($corrections as $correction) {
                    if (($correction['group'] ?? null) !== 'compl' || ($correction['field'] ?? null) !== 'xObs' || ! is_string($correction['value'] ?? null) || mb_strlen($correction['value']) < 1 || mb_strlen($correction['value']) > 500) {
                        throw new \InvalidArgumentException('Correção CT-e suporta somente observação complementar sem alteração tributária ou de participantes.');
                    }
                    $group = $this->node($dom, $detail, 'infCorrecao');
                    $this->nodes($dom, $group, ['grupoAlterado' => $correction['group'], 'campoAlterado' => $correction['field'], 'valorAlterado' => $correction['value']]);
                    if (isset($correction['item'])) {
                        if (! is_int($correction['item']) || $correction['item'] < 1 || $correction['item'] > 99) {
                            throw new \InvalidArgumentException('Índice de correção CT-e inválido.');
                        }
                        $this->node($dom, $group, 'nroItemAlterado', $correction['item']);
                    }
                }
            }
            $this->node($dom, $detail, 'xCondUso', $this->correctionConditions($model));
        }
        if ($type === 'close') {
            $uf = BrazilianStateCode::ibgeFromUf((string) ($source['closing_uf'] ?? ''));
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($source['closed_on'] ?? ''));
            if (! $date || $date->format('Y-m-d') !== $source['closed_on'] || $uf === null || ! preg_match('/^'.$uf.'\d{5}$/', (string) ($source['closing_city_code'] ?? ''))) {
                throw new \InvalidArgumentException('Encerramento MDF-e exige data, UF e município reais e coerentes.');
            }
            $this->nodes($dom, $detail, ['dtEnc' => $source['closed_on'], 'cUF' => $uf, 'cMun' => $source['closing_city_code']]);
        }

        return (string) $dom->saveXML();
    }

    public function buildInvalidation(array $source): string
    {
        $model = (string) ($source['model'] ?? '');
        $cnpj = (string) ($source['issuer']['cnpj'] ?? '');
        $uf = BrazilianStateCode::ibgeFromUf((string) ($source['issuer']['address']['uf'] ?? ''));
        if (! in_array($model, ['55', '65'], true) || ! preg_match('/^\d{14}$/', $cnpj) || $uf === null
            || ! in_array($source['environment'] ?? null, ['homologation', 'production'], true)
            || ! is_int($source['year'] ?? null) || $source['year'] < 2006 || $source['year'] > (int) date('Y')
            || ! is_int($source['series'] ?? null) || $source['series'] < 0 || $source['series'] > 999
            || ! is_int($source['number_from'] ?? null) || ! is_int($source['number_to'] ?? null)
            || $source['number_from'] < 1 || $source['number_to'] < $source['number_from'] || $source['number_to'] > 999999999
            || mb_strlen(trim((string) ($source['justification'] ?? ''))) < 15 || mb_strlen($source['justification']) > 255) {
            throw new \InvalidArgumentException('Faixa de inutilização NF-e/NFC-e inválida ou sem dados obrigatórios.');
        }
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $root = $dom->createElementNS($this->namespace($model), 'inutNFe');
        $root->setAttribute('versao', '4.00');
        $dom->appendChild($root);
        $info = $this->node($dom, $root, 'infInut');
        $year = substr((string) $source['year'], -2);
        $info->setAttribute('Id', 'ID'.$uf.$year.$cnpj.$model.str_pad((string) $source['series'], 3, '0', STR_PAD_LEFT).str_pad((string) $source['number_from'], 9, '0', STR_PAD_LEFT).str_pad((string) $source['number_to'], 9, '0', STR_PAD_LEFT));
        $this->nodes($dom, $info, ['tpAmb' => $source['environment'] === 'production' ? 1 : 2, 'xServ' => 'INUTILIZAR', 'cUF' => $uf, 'ano' => $year, 'CNPJ' => $cnpj, 'mod' => $model, 'serie' => $source['series'], 'nNFIni' => $source['number_from'], 'nNFFin' => $source['number_to'], 'xJust' => $source['justification']]);

        return (string) $dom->saveXML();
    }

    public function signOfflineQr(array $source, string $privateKey): array
    {
        $key = $source['identity']['access_key'];
        $recipient = (string) $source['recipient']['cnpj'];
        $type = strlen($recipient) === 14 ? '1' : '2';
        $parts = [$key, '3', $source['identity']['environment'] === 'production' ? '1' : '2', substr($source['identity']['issued_at'], 8, 2), $source['totals']['invoice'], $type, $recipient];
        $signature = '';
        if (! openssl_sign(implode('|', $parts), $signature, $privateKey, OPENSSL_ALGO_SHA1)) {
            throw new \InvalidArgumentException('Assinatura offline QR v3 indisponível.');
        }
        $source['nfce_qr'] = [...$source['nfce_qr'], 'offline_signature' => base64_encode($signature), 'emitted_at' => $source['identity']['issued_at'], 'recipient_type' => $type, 'recipient_id' => $recipient];

        return $source;
    }

    public function assertOperationResultMatches(string $processedXml, string $signedRequest, array $source, string $protocol): void
    {
        $processed = new \DOMDocument;
        $request = new \DOMDocument;
        foreach ([$processedXml, $signedRequest] as $xml) {
            if ($xml === '' || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
                throw new \InvalidArgumentException('XML de operação inválido.');
            }
        }
        if (! $processed->loadXML($processedXml, LIBXML_NONET) || ! $request->loadXML($signedRequest, LIBXML_NONET)) {
            throw new \InvalidArgumentException('XML de operação inválido.');
        }
        $xpath = new \DOMXPath($processed);
        $xpath->registerNamespace('f', $this->namespace($source['model']));
        $isEvent = isset($source['event_type']);
        $requestName = $isEvent ? match ($source['model']) {
            '55', '65' => 'evento', '57' => 'eventoCTe', '58' => 'eventoMDFe'
        } : 'inutNFe';
        $retName = $isEvent ? match ($source['model']) {
            '55', '65' => 'retEvento', '57' => 'retEventoCTe', '58' => 'retEventoMDFe'
        } : 'retInutNFe';
        $receivedRequest = $xpath->query('/*/f:'.$requestName);
        $returns = $xpath->query('/*/f:'.$retName.'/f:'.($isEvent ? 'infEvento' : 'infInut'));
        $originalXpath = new \DOMXPath($request);
        $originalXpath->registerNamespace('f', $this->namespace($source['model']));
        $originalRequest = $request->documentElement->localName === 'envEvento' ? $originalXpath->query('/f:envEvento/f:evento')->item(0) : $request->documentElement;
        if ($receivedRequest->length !== 1 || $returns->length !== 1 || ! $originalRequest instanceof \DOMElement || $receivedRequest->item(0)->C14N() !== $originalRequest->C14N()) {
            throw new \InvalidArgumentException('Resposta de operação não preserva a solicitação assinada fixada.');
        }
        $return = $returns->item(0);
        $value = static fn (string $name): ?string => $xpath->query('./f:'.$name, $return)->item(0)?->textContent;
        if ($value('nProt') !== $protocol || ! preg_match('/^\d{15}$/', $protocol) || $value('tpAmb') !== ($source['environment'] === 'production' ? '1' : '2')
            || ! in_array($value('cStat'), $isEvent ? ['135'] : ['102'], true)) {
            throw new \InvalidArgumentException('Protocolo, ambiente ou status da operação não correlacionado.');
        }
        $matches = $isEvent
            ? [match ($source['model']) {
                '55', '65' => 'chNFe', '57' => 'chCTe', '58' => 'chMDFe'
            } => $source['access_key'], 'tpEvento' => $this->eventCode($source['model'], $source['document_kind'], $source['event_type']), 'nSeqEvento' => (string) $source['sequence']]
            : ['CNPJ' => $source['issuer']['cnpj'], 'mod' => $source['model'], 'serie' => (string) $source['series'], 'ano' => substr((string) $source['year'], -2), 'nNFIni' => (string) $source['number_from'], 'nNFFin' => (string) $source['number_to']];
        foreach ($matches as $field => $expected) {
            if ($value($field) !== (string) $expected) {
                throw new \InvalidArgumentException('Identidade da operação não corresponde à intenção fixada.');
            }
        }
    }

    private function correctionConditions(string $model): string
    {
        $path = app(ErpFiscalSchemaValidator::class)->entrypoint($model, 'event_payload', 'correction');
        $schema = new \DOMDocument;
        if (! $schema->load(base_path('resources/xsd/erp-fiscal/'.$path), LIBXML_NONET)) {
            throw new \RuntimeException('Condições oficiais de correção indisponíveis.');
        }
        $xpath = new \DOMXPath($schema);
        $xpath->registerNamespace('xs', 'http://www.w3.org/2001/XMLSchema');
        $condition = $xpath->query('//xs:element[@name="xCondUso"]//xs:enumeration')->item(0);
        if (! $condition instanceof \DOMElement) {
            throw new \RuntimeException('Condições oficiais de correção ausentes.');
        }

        return $condition->getAttribute('value');
    }

    private function namespace(string $model): string
    {
        return match ($model) {
            '55', '65' => 'http://www.portalfiscal.inf.br/nfe', '57' => 'http://www.portalfiscal.inf.br/cte', '58' => 'http://www.portalfiscal.inf.br/mdfe', default => throw new \InvalidArgumentException('Modelo sem namespace fiscal.')
        };
    }

    private function nodes(\DOMDocument $dom, \DOMElement $parent, array $values): void
    {
        foreach ($values as $name => $value) {
            $this->node($dom, $parent, $name, $value);
        }
    }

    private function node(\DOMDocument $dom, \DOMElement $parent, string $name, mixed $value = null): \DOMElement
    {
        $element = $dom->createElementNS($parent->namespaceURI, $name);
        if ($value !== null) {
            $element->appendChild($dom->createTextNode((string) $value));
        }
        $parent->appendChild($element);

        return $element;
    }
}
