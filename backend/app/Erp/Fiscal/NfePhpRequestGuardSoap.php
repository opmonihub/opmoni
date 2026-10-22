<?php

namespace App\Erp\Fiscal;

use App\Models\Erp\Fiscal\ErpFiscalAttempt;
use DOMDocument;
use DOMElement;
use DOMXPath;
use NFePHP\Common\Certificate;
use NFePHP\Common\Soap\SoapInterface;
use RuntimeException;

final class NfePhpRequestGuardSoap implements SoapInterface
{
    private ?string $submittedRequest = null;

    /**
     * @param  array<string, mixed>  $source
     */
    public function __construct(
        private readonly SoapInterface $delegate,
        private readonly ErpFiscalAttempt $attempt,
        private readonly array $source,
        private readonly string $signedXml,
        private readonly ErpFiscalXmlSigner $signer,
        private readonly string $certificatePem,
    ) {}

    public function loadCertificate(?Certificate $certificate = null)
    {
        return $this->delegate->loadCertificate($certificate);
    }

    public function timeout($timesecs)
    {
        return $this->delegate->timeout($timesecs);
    }

    public function protocol($protocol = SoapInterface::SSL_DEFAULT)
    {
        return $this->delegate->protocol($protocol);
    }

    public function proxy($ip, $port, $user, $password)
    {
        return $this->delegate->proxy($ip, $port, $user, $password);
    }

    public function httpVersion($version = null)
    {
        return $this->delegate->httpVersion($version);
    }

    public function disableSecurity($flag = false)
    {
        return $this->delegate->disableSecurity($flag);
    }

    public function disableCertValidation($flag = true)
    {
        return $this->delegate->disableCertValidation($flag);
    }

    public function setSecurityLevel(bool $param = false)
    {
        return $this->delegate->setSecurityLevel($param);
    }

    public function setEncriptPrivateKey($encript = true)
    {
        return $this->delegate->setEncriptPrivateKey($encript);
    }

    public function setTemporaryFolder($folderRealPath = null)
    {
        return $this->delegate->setTemporaryFolder($folderRealPath);
    }

    public function setDebugMode($value = false)
    {
        return $this->delegate->setDebugMode($value);
    }

    public function setSoapPrefix($prefixes = [])
    {
        return $this->delegate->setSoapPrefix($prefixes);
    }

    public function send($url, $operation = '', $action = '', $soapver = SOAP_1_2, $parameters = [], $namespaces = [], $request = '', $soapheader = null): string
    {
        $this->assertNativeRequestMatchesAttempt((string) $url, (string) $request, is_array($parameters) ? $parameters : []);

        return (string) $this->delegate->send($url, $operation, $action, $soapver, $parameters, $namespaces, $request, $soapheader);
    }

    public function submittedRequestXml(): ?string
    {
        return $this->submittedRequest;
    }

    /** @param array<string, mixed> $parameters */
    private function assertNativeRequestMatchesAttempt(string $url, string $request, array $parameters): void
    {
        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            throw new RuntimeException('Endpoint SEFAZ não seguro.');
        }
        if ($this->assertConsultationMatchesAttempt($request, $parameters)) {
            return;
        }
        $expected = $this->loadXml($this->signedXml);
        $expectedInfoName = $this->attempt->action === 'event:submit' ? 'infEvento' : ($this->attempt->action === 'invalidation:submit' ? 'infInut' : null);
        $expectedRoot = $expected->documentElement;
        if (! $expectedRoot instanceof DOMElement) {
            throw new RuntimeException('Solicitação fiscal local inválida.');
        }

        $expectedInfo = $expectedInfoName === null ? $expectedRoot : $this->query($expected, '//*[local-name()="'.$expectedInfoName.'"]')->item(0);
        if (! $expectedInfo instanceof DOMElement) {
            throw new RuntimeException('Solicitação fiscal fixada sem identidade.');
        }
        $expectedSigned = $this->findSignedPayload($expected, $expectedInfoName);
        if ($expectedSigned === null) {
            throw new RuntimeException('Solicitação fiscal fixada sem conteúdo assinado.');
        }
        $candidates = $this->xmlCandidates($request, $parameters);
        $actualPayloads = $this->operationPayloadsFromCandidates($candidates);
        if (count($actualPayloads) !== 1) {
            throw new RuntimeException('Solicitação SEFAZ exige um único conteúdo fiscal por tentativa ('.count($actualPayloads).').');
        }
        $actualPayload = reset($actualPayloads);
        if (! $actualPayload instanceof DOMElement || $actualPayload->C14N() !== $expectedSigned) {
            throw new RuntimeException('Conteúdo assinado NFePHP diverge da tentativa fiscal fixada.');
        }
        $actualInfo = $expectedInfoName === null ? $actualPayload : $this->query($actualPayload->ownerDocument, '//*[local-name()="'.$expectedInfoName.'"]')->item(0);
        if (! $actualInfo instanceof DOMElement || $actualInfo->C14N() !== $expectedInfo->C14N()) {
            throw new RuntimeException('Identidade NFePHP diverge da tentativa fiscal fixada.');
        }
        if (! $this->signer->verify($expectedSigned, $this->certificatePem)) {
            throw new RuntimeException('Assinatura NFePHP diverge do certificado da tentativa.');
        }
        $this->submittedRequest = $expectedSigned;

    }

    /** @param array<string, mixed> $parameters */
    private function assertConsultationMatchesAttempt(string $request, array $parameters): bool
    {
        if (! in_array($this->attempt->action, ['document:consult', 'document:reconcile', 'document:transmit'], true)) {
            return false;
        }
        $expectedKey = (string) ($this->source['identity']['access_key'] ?? '');
        if (! preg_match('/^\d{44}$/', $expectedKey) || substr($expectedKey, 20, 2) !== $this->attempt->model
            || ($this->source['identity']['environment'] ?? null) !== $this->attempt->environment) {
            return false;
        }
        $expectedEnvironment = $this->attempt->environment === 'production' ? '1' : '2';
        $receipt = $this->attempt->provider_receipt;
        $payloads = $this->operationPayloadsFromCandidates($this->xmlCandidates($request, $parameters));
        if ($payloads === []) {
            return false;
        }
        if (count($payloads) !== 1) {
            throw new RuntimeException('Consulta SEFAZ exige uma única identidade fixada.');
        }
        $query = reset($payloads);
        if (! $query instanceof DOMElement || ! in_array($query->localName, $this->consultationRoots(), true)) {
            return false;
        }
        $expectedRoot = match ($this->attempt->model) {
            '55', '65' => is_string($receipt) && preg_match('/^\d{15}$/', $receipt) ? 'consReciNFe' : 'consSitNFe',
            '57' => 'consSitCTe',
            '58' => is_string($receipt) && preg_match('/^\d{15}$/', $receipt) ? 'consReciMDFe' : 'consSitMDFe',
        };
        if ($query->localName !== $expectedRoot) {
            throw new RuntimeException('Consulta SEFAZ usa operação incompatível com modelo ou recibo.');
        }
        if ($query->namespaceURI !== $this->consultationNamespace($query->localName)) {
            throw new RuntimeException('Consulta SEFAZ usa namespace divergente.');
        }
        $dom = $query->ownerDocument;
        $environment = $this->query($dom, '//*[local-name()="tpAmb"]')->item(0)?->textContent;
        if ($environment !== $expectedEnvironment) {
            throw new RuntimeException('Consulta SEFAZ usa ambiente divergente.');
        }
        $keyName = match ($query->localName) {
            'consSitNFe' => 'chNFe', 'consSitCTe' => 'chCTe', 'consSitMDFe' => 'chMDFe', default => null,
        };
        if ($keyName !== null) {
            $key = $this->query($dom, '//*[local-name()="'.$keyName.'"]')->item(0)?->textContent;
            if ($key !== $expectedKey) {
                throw new RuntimeException('Consulta SEFAZ usa chave divergente da tentativa.');
            }

            return true;
        }
        $receiptValue = $this->query($dom, '//*[local-name()="nRec"]')->item(0)?->textContent;
        if (! is_string($receipt) || ! preg_match('/^\d{15}$/', $receipt) || $receiptValue !== $receipt) {
            throw new RuntimeException('Consulta SEFAZ usa recibo divergente da tentativa.');
        }

        return true;
    }

    /** @return list<DOMElement> */
    private function operationPayloadNodes(DOMDocument $dom): array
    {
        $names = [
            'NFe', 'CTe', 'MDFe', 'evento', 'eventoCTe', 'eventoMDFe', 'inutNFe',
            ...$this->consultationRoots(),
        ];
        $nodes = [];
        foreach ($dom->getElementsByTagName('*') as $node) {
            if ($node instanceof DOMElement && in_array($node->localName, $names, true)) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /** @param list<string> $candidates @return array<string, DOMElement> */
    private function operationPayloadsFromCandidates(array $candidates): array
    {
        $payloads = [];
        $multiplicities = [];
        foreach ($candidates as $candidate) {
            try {
                $dom = $this->loadXml($candidate);
            } catch (\Throwable) {
                continue;
            }
            $candidateCounts = [];
            $candidateNodes = [];
            foreach ($this->operationPayloadNodes($dom) as $payload) {
                $canonical = $payload->C14N();
                $candidateCounts[$canonical] = ($candidateCounts[$canonical] ?? 0) + 1;
                $candidateNodes[$canonical] = $payload;
            }
            foreach ($candidateCounts as $canonical => $count) {
                if ($count > ($multiplicities[$canonical] ?? 0)) {
                    $multiplicities[$canonical] = $count;
                    $payloads[$canonical] = $candidateNodes[$canonical];
                }
            }
        }
        foreach ($multiplicities as $count) {
            if ($count > 1) {
                $payloads['__duplicate_operation_payload__'] = $payloads['__duplicate_operation_payload__'] ?? reset($payloads);
            }
        }

        return $payloads;
    }

    /** @return list<string> */
    private function consultationRoots(): array
    {
        return ['consSitNFe', 'consReciNFe', 'consSitCTe', 'consSitMDFe', 'consReciMDFe'];
    }

    private function consultationNamespace(string $root): string
    {
        return match ($root) {
            'consSitNFe', 'consReciNFe' => 'http://www.portalfiscal.inf.br/nfe',
            'consSitCTe' => 'http://www.portalfiscal.inf.br/cte',
            'consSitMDFe', 'consReciMDFe' => 'http://www.portalfiscal.inf.br/mdfe',
        };
    }

    /** @param array<string, mixed> $parameters @return list<string> */
    private function xmlCandidates(string $request, array $parameters): array
    {
        $candidates = [];
        $pending = [$request, ...array_values($parameters)];
        for ($iteration = 0; $pending !== [] && $iteration < 16; $iteration++) {
            $current = array_shift($pending);
            if (! is_string($current) || $current === '' || strlen($current) > 8_000_000) {
                continue;
            }
            if (str_contains($current, '<')) {
                $candidates[] = $current;
                try {
                    $dom = $this->loadXml($current);
                    foreach ($dom->getElementsByTagName('*') as $node) {
                        if ($node instanceof DOMElement && $node->childElementCount === 0 && $node->textContent !== '') {
                            $pending[] = $node->textContent;
                        }
                    }
                } catch (\Throwable) {
                }
            }
            $decoded = base64_decode($current, true);
            if (is_string($decoded) && $decoded !== '' && $decoded !== $current) {
                $pending[] = $decoded;
            }
            $unzipped = @gzdecode($current);
            if (is_string($unzipped) && $unzipped !== '') {
                $pending[] = $unzipped;
            }
        }

        return array_values(array_unique($candidates));
    }

    private function findDocumentNode(DOMDocument $dom): ?DOMElement
    {
        foreach (['NFe', 'CTe', 'MDFe'] as $name) {
            $node = $this->query($dom, '//*[local-name()="'.$name.'"]')->item(0);
            if ($node instanceof DOMElement) {
                return $node;
            }
        }

        return null;
    }

    private function findSignedPayload(DOMDocument $dom, ?string $identityName): ?string
    {
        if ($identityName === null) {
            $node = $this->findDocumentNode($dom);

            return $node?->C14N();
        }
        $rootName = $identityName === 'infEvento' ? match ($this->attempt->model) {
            '55', '65' => 'evento',
            '57' => 'eventoCTe',
            '58' => 'eventoMDFe',
        } : 'inutNFe';
        $node = $this->query($dom, '//*[local-name()="'.$rootName.'"]')->item(0);

        return $node instanceof DOMElement ? $node->C14N() : null;
    }

    private function loadXml(string $xml): DOMDocument
    {
        if ($xml === '' || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            throw new RuntimeException('XML fiscal inválido.');
        }
        $dom = new DOMDocument;
        $dom->resolveExternals = false;
        $dom->substituteEntities = false;
        if (! $dom->loadXML($xml, LIBXML_NONET) || ! $dom->documentElement instanceof DOMElement) {
            throw new RuntimeException('XML fiscal inválido.');
        }

        return $dom;
    }

    private function query(DOMDocument $dom, string $query): \DOMNodeList
    {
        $result = (new DOMXPath($dom))->query($query);
        if ($result === false) {
            throw new RuntimeException('Consulta XML fiscal inválida.');
        }

        return $result;
    }
}
