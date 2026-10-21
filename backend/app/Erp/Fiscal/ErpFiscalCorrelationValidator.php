<?php

namespace App\Erp\Fiscal;

use DOMDocument;
use DOMElement;
use DOMXPath;
use InvalidArgumentException;

final class ErpFiscalCorrelationValidator
{
    public function assertDocumentMatches(string $xml, array $expected): void
    {
        $document = $this->load($xml);
        $model = (string) ($expected['model'] ?? '');
        $root = $this->documentRoot($model);
        $allowedRoots = [$root, $this->processedRoot($model)];
        if (! in_array($document->documentElement?->localName, $allowedRoots, true) || $document->documentElement?->namespaceURI !== $this->namespace($model)) {
            throw new InvalidArgumentException('XML fiscal não corresponde ao modelo da tentativa.');
        }
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('f', $this->namespace($model));
        $identityPath = match ($model) {
            '55', '65' => '//f:infNFe', '57' => '//f:infCte', '58' => '//f:infMDFe', default => ''
        };
        $identity = $xpath->query($identityPath)->item(0);
        if (! $identity instanceof DOMElement) {
            throw new InvalidArgumentException('XML fiscal sem identificação do documento.');
        }
        $key = $identity->getAttribute('Id');
        $prefix = match ($model) {
            '55', '65' => 'NFe', '57' => 'CTe', '58' => 'MDFe'
        };
        $key = str_starts_with((string) $key, $prefix)
            ? substr((string) $key, strlen($prefix))
            : '';
        $issuerNode = $xpath->query($identityPath.'/f:emit/f:CNPJ')->item(0);
        $issuer = $issuerNode?->textContent;
        $expectedEnvironment = (string) ($expected['environment'] ?? '');
        $ide = $xpath->query($identityPath.'/f:ide')->item(0);
        $tpAmb = $ide instanceof DOMElement ? $this->childValue($ide, 'tpAmb') : null;
        $actualModel = $ide instanceof DOMElement ? $this->childValue($ide, 'mod') : null;
        $actualEnvironment = $tpAmb === '1' ? 'production' : ($tpAmb === '2' ? 'homologation' : null);
        $numberField = match ($model) {
            '55', '65' => 'nNF', '57' => 'nCT', '58' => 'nMDF'
        };
        if (! $ide instanceof DOMElement || $xpath->query($identityPath)->length !== 1
            || (int) $this->childValue($ide, 'serie') !== (int) substr($key, 22, 3)
            || (int) $this->childValue($ide, $numberField) !== (int) substr($key, 25, 9)
            || $this->childValue($ide, 'cUF') !== substr($key, 0, 2)
            || $this->childValue($ide, 'tpEmis') !== substr($key, 34, 1)
            || $this->childValue($ide, 'cDV') !== substr($key, 43, 1)) {
            throw new InvalidArgumentException('Campos de identificação do XML divergem dos dígitos da chave fiscal.');
        }

        if ($key !== (string) ($expected['access_key'] ?? '') || $issuer !== (string) ($expected['issuer_cnpj'] ?? '') || $actualEnvironment !== $expectedEnvironment || $actualModel !== $model
            || substr($key, 6, 14) !== (string) ($expected['issuer_cnpj'] ?? '') || substr($key, 20, 2) !== $model
            || isset($expected['series']) && (int) substr($key, 22, 3) !== (int) $expected['series']
            || isset($expected['number']) && (int) substr($key, 25, 9) !== (int) $expected['number']) {
            throw new InvalidArgumentException('XML fiscal não corresponde à chave, emissor ou ambiente da tentativa.');
        }
    }

    /** @param array<string, mixed> $expected */
    public function assertProcessedMatches(string $xml, array $expected): void
    {
        $this->assertDocumentMatches($xml, $expected);
        $model = (string) ($expected['model'] ?? '');
        $document = $this->load($xml);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('f', $this->namespace($model));
        $protocolName = match ($model) {
            '55', '65' => 'protNFe', '57' => 'protCTe', '58' => 'protMDFe', default => ''
        };
        $keyName = match ($model) {
            '55', '65' => 'chNFe', '57' => 'chCTe', '58' => 'chMDFe', default => ''
        };
        if ($xpath->query('//f:'.$protocolName.'/f:infProt')->length !== 1) {
            throw new InvalidArgumentException('XML processado exige um único protocolo correlacionado.');
        }
        $protocol = $xpath->query('//f:'.$protocolName.'/f:infProt')->item(0);
        $receivedKey = $protocol instanceof DOMElement ? $this->childValue($protocol, $keyName) : null;
        $receipt = $protocol instanceof DOMElement ? $this->childValue($protocol, 'nProt') : null;
        $status = $protocol instanceof DOMElement ? $this->childValue($protocol, 'cStat') : null;
        $environmentCode = $protocol instanceof DOMElement ? $this->childValue($protocol, 'tpAmb') : null;
        $expectedAmb = ($expected['environment'] ?? null) === 'production' ? '1' : '2';

        if ($receivedKey !== (string) ($expected['access_key'] ?? '') || $receipt !== (string) ($expected['protocol'] ?? '') || $status !== '100' || $environmentCode !== $expectedAmb) {
            throw new InvalidArgumentException('XML processado não traz protocolo autorizado correlacionado à tentativa.');
        }
        if ($document->documentElement?->localName !== $this->processedRoot($model)) {
            throw new InvalidArgumentException('XML autorizado não é o envelope processado esperado.');
        }
    }

    private function load(string $xml): DOMDocument
    {
        if ($xml === '' || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            throw new InvalidArgumentException('XML fiscal inválido para correlação.');
        }
        $dom = new DOMDocument;
        $dom->resolveExternals = false;
        $dom->substituteEntities = false;
        if (! $dom->loadXML($xml, LIBXML_NONET) || ! $dom->documentElement instanceof DOMElement) {
            throw new InvalidArgumentException('XML fiscal inválido para correlação.');
        }

        return $dom;
    }

    private function documentRoot(string $model): string
    {
        return match ($model) {
            '55', '65' => 'NFe', '57' => 'CTe', '58' => 'MDFe', default => throw new InvalidArgumentException('Modelo fiscal desconhecido.')
        };
    }

    private function namespace(string $model): string
    {
        return match ($model) {
            '55', '65' => 'http://www.portalfiscal.inf.br/nfe', '57' => 'http://www.portalfiscal.inf.br/cte', '58' => 'http://www.portalfiscal.inf.br/mdfe', default => throw new InvalidArgumentException('Modelo fiscal desconhecido.')
        };
    }

    private function processedRoot(string $model): string
    {
        return match ($model) {
            '55', '65' => 'nfeProc', '57' => 'cteProc', '58' => 'mdfeProc', default => throw new InvalidArgumentException('Modelo fiscal desconhecido.')
        };
    }

    private function childValue(DOMElement $parent, string $name): ?string
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $name) {
                return trim($child->textContent);
            }
        }

        return null;
    }
}
