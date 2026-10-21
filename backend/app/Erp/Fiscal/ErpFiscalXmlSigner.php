<?php

namespace App\Erp\Fiscal;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use RuntimeException;
use Throwable;

final class ErpFiscalXmlSigner
{
    private const NFE = 'http://www.portalfiscal.inf.br/nfe';

    private const CTE = 'http://www.portalfiscal.inf.br/cte';

    private const MDFE = 'http://www.portalfiscal.inf.br/mdfe';

    public function sign(string $xml, string $privateKeyPem, string $certificatePem): string
    {
        $dom = $this->load($xml);
        $namespace = $this->namespace($dom->documentElement?->namespaceURI ?? '');
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('f', $namespace);
        $xpath->registerNamespace('ds', XMLSecurityDSig::XMLDSIGNS);
        $localName = $this->signableName($dom->documentElement?->localName ?? '');
        $target = $xpath->query('//f:'.$localName.'[@Id]')->item(0);
        if (! $target instanceof DOMElement || $target->getAttribute('Id') === '' || $xpath->query('//f:'.$localName.'[@Id]')->length !== 1 || $xpath->query('//*[@Id or @ID or @id or @xml:id]')->length !== 1 || $xpath->query('//ds:Signature')->length > 0) {
            throw new RuntimeException('Documento fiscal sem identidade signável ou já assinado.');
        }
        if ($privateKeyPem === '' || $certificatePem === '') {
            throw new RuntimeException('Certificado fiscal ou chave privada ausente.');
        }
        $this->assertKeyMatchesCertificate($privateKeyPem, $certificatePem);

        try {
            $dsig = new XMLSecurityDSig('');
            $this->dropIndentation($dsig);
            $dsig->setCanonicalMethod(XMLSecurityDSig::C14N);
            $dsig->addReference($target, XMLSecurityDSig::SHA1, ['http://www.w3.org/2000/09/xmldsig#enveloped-signature', XMLSecurityDSig::C14N], ['id_name' => 'Id', 'overwrite' => false]);
            $key = new XMLSecurityKey(XMLSecurityKey::RSA_SHA1, ['type' => 'private']);
            $key->loadKey($privateKeyPem);
            $dsig->sign($key, $target->parentNode);
            $dsig->add509Cert($certificatePem, true, false, ['subjectName' => false]);
            $signed = $dom->saveXML();
            if (! is_string($signed) || ! $this->verify($signed, $certificatePem)) {
                throw new RuntimeException('Assinatura XML fiscal não passou pela conferência local.');
            }

            return $signed;
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException) {
                throw $exception;
            }
            throw new RuntimeException('Falha ao assinar XML fiscal.');
        }
    }

    public function verify(string $signedXml, string $certificatePem): bool
    {
        try {
            $dom = $this->load($signedXml);
            $verifier = new XMLSecurityDSig('');
            $root = $dom->documentElement;
            if (! $root instanceof DOMElement) {
                return false;
            }
            $namespace = $this->namespace($root->namespaceURI ?? '');
            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('f', $namespace);
            $xpath->registerNamespace('ds', XMLSecurityDSig::XMLDSIGNS);
            $documentRoot = $this->signedDocumentRoot($root->localName);
            if ($documentRoot === null) {
                return false;
            }
            $localName = $this->signableName($documentRoot);
            $target = $xpath->query('//f:'.$localName.'[@Id]')->item(0);
            $signatures = $xpath->query('//ds:Signature');
            $ids = $xpath->query('//*[@Id or @ID or @id or @xml:id]');
            if (! $target instanceof DOMElement || $target->getAttribute('Id') === '' || $signatures->length !== 1 || $ids->length !== 1) {
                return false;
            }
            $signatureNode = $signatures->item(0);
            $reference = $xpath->query('./ds:SignedInfo/ds:Reference', $signatureNode)->item(0);
            if ($xpath->query('./ds:SignedInfo/ds:Reference', $signatureNode)->length !== 1 || ! $reference instanceof DOMElement || $reference->getAttribute('URI') !== '#'.$target->getAttribute('Id') || $target->parentNode !== $signatureNode->parentNode) {
                return false;
            }
            if ($verifier->locateSignature($dom) === null) {
                return false;
            }
            $verifier->canonicalizeSignedInfo();
            if ($verifier->validateReference() !== true) {
                return false;
            }
            $public = new XMLSecurityKey(XMLSecurityKey::RSA_SHA1, ['type' => 'public']);
            $public->loadKey($certificatePem, false, true);

            return $verifier->verify($public) === 1;
        } catch (Throwable) {
            return false;
        }
    }

    private function load(string $xml): DOMDocument
    {
        if ($xml === '' || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            throw new RuntimeException('XML fiscal inválido.');
        }
        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = false;
        $dom->resolveExternals = false;
        $dom->substituteEntities = false;
        if (! $dom->loadXML($xml, LIBXML_NONET) || ! $dom->documentElement instanceof DOMElement) {
            throw new RuntimeException('XML fiscal inválido.');
        }

        return $dom;
    }

    private function namespace(string $namespace): string
    {
        if (! in_array($namespace, [self::NFE, self::CTE, self::MDFE], true)) {
            throw new RuntimeException('Namespace fiscal não suportado para assinatura.');
        }

        return $namespace;
    }

    private function signableName(string $root): string
    {
        return match ($root) {
            'NFe' => 'infNFe',
            'CTe', 'CTeSimp', 'CTeOS' => 'infCte',
            'MDFe' => 'infMDFe',
            'envEvento', 'evento', 'eventoCTe', 'eventoMDFe' => 'infEvento',
            'inutNFe' => 'infInut',
            default => throw new RuntimeException('Raiz fiscal não suportada para assinatura.'),
        };
    }

    private function signedDocumentRoot(string $root): ?string
    {
        return match ($root) {
            'NFe', 'nfeProc' => 'NFe',
            'CTe', 'cteProc' => 'CTe',
            'MDFe', 'mdfeProc' => 'MDFe',
            'envEvento', 'evento', 'procEventoNFe' => 'evento',
            'eventoCTe', 'procEventoCTe' => 'eventoCTe',
            'eventoMDFe', 'procEventoMDFe' => 'eventoMDFe',
            'inutNFe', 'ProcInutNFe' => 'inutNFe',
            default => null,
        };
    }

    private function assertKeyMatchesCertificate(string $privateKeyPem, string $certificatePem): void
    {
        $private = openssl_pkey_get_private($privateKeyPem);
        $certificate = openssl_x509_read($certificatePem);
        $public = $certificate === false ? false : openssl_pkey_get_public($certificate);
        if ($private === false || $public === false) {
            throw new RuntimeException('Chave privada ou certificado fiscal inválido.');
        }
        $privateDetails = openssl_pkey_get_details($private);
        $publicDetails = openssl_pkey_get_details($public);
        if (! is_array($privateDetails) || ! is_array($publicDetails)
            || ! hash_equals(hash('sha256', (string) ($privateDetails['key'] ?? '')), hash('sha256', (string) ($publicDetails['key'] ?? '')))) {
            throw new RuntimeException('Chave privada não corresponde ao certificado fiscal fixado.');
        }
    }

    private function dropIndentation(XMLSecurityDSig $dsig): void
    {
        $xpath = new DOMXPath($dsig->sigNode->ownerDocument);
        foreach ($xpath->query('//text()[normalize-space(.) = ""]', $dsig->sigNode) as $whitespace) {
            $whitespace->parentNode?->removeChild($whitespace);
        }
    }
}
