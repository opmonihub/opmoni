<?php

namespace App\Services\Fiscal\Manifestacao;

use App\Services\CertificatePkcs12;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Validation\ValidationException;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use Throwable;

/**
 * Assina um `<evento>` de manifestação do destinatário com o A1 do cliente.
 *
 * É o primeiro — e único — lugar do módulo fiscal com XMLDSig. A distribuição
 * (`DfeTransport`) não assina e documenta isso; a manifestação é um ato perante
 * o fisco e o Ambiente Nacional só aceita o evento com a assinatura do
 * destinatário. A biblioteca `robrichards/xmlseclibs` fica confinada aqui.
 *
 * **O formato não é escolha nossa.** O XSD oficial do serviço de eventos
 * (`resources/xsd/nfe/xmldsig-core-schema_v1.01.xsd`, do pacote PL_009) fixa
 * com `fixed` cada algoritmo: canonicalização **inclusiva**
 * (`REC-xml-c14n-20010315`, não a exclusiva), `rsa-sha1` e digest `sha1`,
 * exatamente dois `Transform` (enveloped-signature e C14N) e um `KeyInfo` com
 * `X509Data/X509Certificate`. SHA-1 é legado em qualquer outro contexto, mas
 * um `SignatureMethod` com `rsa-sha256` é recusado pelo próprio schema —
 * localmente, pelo `FiscalXmlValidator`, e no AN. É o mesmo perfil que o
 * sped-common produz há anos.
 *
 * **Sem prefixo `ds:`.** O `<Signature>` sai em namespace default porque o
 * `FiscalXmlValidator` recusa qualquer elemento prefixado antes do envio, e o
 * XSD do fisco aceita as duas formas.
 *
 * **Falha fechado.** Tudo o que impede a assinatura — A1 que não abre, evento
 * sem `infEvento` com `Id`, erro da biblioteca, assinatura que não confere com
 * a própria chave pública — sai como `EventSignatureFailed`, que é
 * `FiscalRequestNotSent`: a requisição não saiu, e quem chama sabe disso. A
 * assinatura produzida é conferida aqui mesmo, com o certificado que foi para
 * o `KeyInfo`, para um evento quebrado nunca chegar ao transporte.
 *
 * **Segredos.** A senha, o PEM da chave e o XML do evento não entram em
 * mensagem de exceção nem em log; o `finally` esvazia o que esta unidade
 * guardou da chave, com a mesma ressalva de `CertificatePkcs12`: atribuir não
 * apaga memória, e só isso é prometido.
 */
final class EventSigner
{
    private const NAMESPACE_NFE = 'http://www.portalfiscal.inf.br/nfe';

    private const TRANSFORM_ENVELOPED = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';

    public function __construct(private CertificatePkcs12 $pkcs12 = new CertificatePkcs12) {}

    /**
     * Recebe o `<evento>` montado (sem `Signature`) e os bytes do PKCS#12 do
     * cliente como o cofre os guarda; devolve o mesmo evento com o `Signature`
     * como último filho, do jeito que o XSD de manifestação ordena.
     *
     * @throws EventSignatureFailed quando a assinatura não pode ser produzida
     */
    public function sign(string $eventXml, string $pkcs12Bytes, string $password): string
    {
        $material = $this->openCertificate($pkcs12Bytes, $password);

        try {
            $dom = $this->load($eventXml);
            [$evento, $infEvento] = $this->locateSignableNodes($dom);

            $this->appendSignature($evento, $infEvento, $material['cert'], $material['pkey']);

            $signed = $dom->saveXML($dom->documentElement);

            if ($signed === false) {
                throw EventSignatureFailed::becauseSigningFailed();
            }

            // A conferência lê o texto que vai viajar, não o DOM que acabou de
            // ser assinado: é o que o AN vai receber, e a biblioteca destaca o
            // Signature do documento ao validar a referência — num clone isso é
            // inofensivo, no original apagaria a assinatura que acabou de nascer.
            $this->verifyAgainstOwnCertificate($signed, $material['cert']);

            return $signed;
        } finally {
            $material['pkey'] = str_repeat("\0", strlen($material['pkey']));
            unset($material);
        }
    }

    /**
     * @return array{cert: string, pkey: string}
     */
    private function openCertificate(string $pkcs12Bytes, string $password): array
    {
        try {
            $inspected = $this->pkcs12->inspect($pkcs12Bytes, $password);
        } catch (ValidationException) {
            // A unidade compartilhada já classificou a recusa (senha, arquivo
            // ilegível, RC2); para quem assina, as três são a mesma coisa: não
            // há material para assinar. A frase dela não sai daqui — é frase de
            // formulário, e levaria a chave do erro para um log de job.
            throw EventSignatureFailed::becauseTheCertificateDidNotOpen();
        }

        return ['cert' => $inspected['cert'], 'pkey' => $inspected['pkey']];
    }

    private function load(string $eventXml): DOMDocument
    {
        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;

        // O libxml acumula erro em buffer global; ele é capturado e o estado
        // anterior devolvido, como faz o `FiscalXmlValidator`.
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            if ($eventXml === '' || ! $dom->loadXML($eventXml)) {
                throw EventSignatureFailed::becauseTheEventIsNotSignable();
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $dom;
    }

    /**
     * O `<evento>` é a raiz e o `<infEvento>` com `Id` é o alvo da referência.
     * O `Id` precisa já estar no evento — é quem monta que sabe a regra
     * `ID` + tpEvento + chave + nSeqEvento —, e um evento sem ele não é
     * assinável: inventar um identificador aqui produziria uma assinatura
     * válida sobre um evento que o AN recusaria.
     *
     * @return array{0: DOMElement, 1: DOMElement}
     */
    private function locateSignableNodes(DOMDocument $dom): array
    {
        $evento = $dom->documentElement;

        if (! $evento instanceof DOMElement
            || $evento->localName !== 'evento'
            || $evento->namespaceURI !== self::NAMESPACE_NFE) {
            throw EventSignatureFailed::becauseTheEventIsNotSignable();
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('nfe', self::NAMESPACE_NFE);
        $xpath->registerNamespace('ds', XMLSecurityDSig::XMLDSIGNS);

        $infEvento = $xpath->query('/nfe:evento/nfe:infEvento[@Id]')->item(0);

        if (! $infEvento instanceof DOMElement || $infEvento->getAttribute('Id') === '') {
            throw EventSignatureFailed::becauseTheEventIsNotSignable();
        }

        // Um evento que já traz Signature não é reassinado: ou é bug de quem
        // chamou, ou é um evento alheio — e os dois casos param aqui.
        if ($xpath->query('/nfe:evento/ds:Signature')->length > 0) {
            throw EventSignatureFailed::becauseTheEventIsNotSignable();
        }

        return [$evento, $infEvento];
    }

    private function appendSignature(
        DOMElement $evento,
        DOMElement $infEvento,
        string $certificatePem,
        string $privateKeyPem,
    ): void {
        try {
            // Prefixo vazio: o template da biblioteca vira `<Signature xmlns=...>`
            // em namespace default, sem `ds:`.
            $dsig = new XMLSecurityDSig('');
            $this->dropIndentation($dsig);
            $dsig->setCanonicalMethod(XMLSecurityDSig::C14N);

            // `overwrite => false` preserva o `Id` que o montador escreveu; a
            // referência sai como `URI="#ID..."`.
            $dsig->addReference(
                $infEvento,
                XMLSecurityDSig::SHA1,
                [self::TRANSFORM_ENVELOPED, XMLSecurityDSig::C14N],
                ['id_name' => 'Id', 'overwrite' => false],
            );

            $key = new XMLSecurityKey(XMLSecurityKey::RSA_SHA1, ['type' => 'private']);
            $key->loadKey($privateKeyPem);

            // Assinar com o nó pai anexa o Signature ao `<evento>` antes da
            // canonicalização, para o SignedInfo ser canonizado no contexto em
            // que vai viajar.
            $dsig->sign($key, $evento);
            $dsig->add509Cert($certificatePem, isPEMFormat: true, isURL: false, options: ['subjectName' => false]);
        } catch (EventSignatureFailed $exception) {
            throw $exception;
        } catch (Throwable) {
            // A biblioteca lança `Exception` genérica com texto que pode ecoar
            // trechos do documento; a causa não viaja na mensagem.
            throw EventSignatureFailed::becauseSigningFailed();
        }
    }

    /**
     * O template da biblioteca vem indentado, e a indentação são nós de texto
     * dentro do `SignedInfo` — que entram na canonicalização e viajariam no
     * evento. Removê-los antes de assinar deixa o `Signature` compacto, como o
     * fisco está acostumado a receber, e faz a conferência independer de como
     * o leitor trata espaço em branco.
     */
    private function dropIndentation(XMLSecurityDSig $dsig): void
    {
        $xpath = new DOMXPath($dsig->sigNode->ownerDocument);

        foreach ($xpath->query('//text()[normalize-space(.) = ""]', $dsig->sigNode) as $whitespace) {
            $whitespace->parentNode?->removeChild($whitespace);
        }
    }

    /**
     * A conferência é com o certificado que foi para o `KeyInfo`, não com a
     * chave privada: é o que o AN vai fazer, e é o que pega uma chave que não
     * casa com o certificado dentro do mesmo PKCS#12.
     */
    private function verifyAgainstOwnCertificate(string $signedXml, string $certificatePem): void
    {
        try {
            $copy = new DOMDocument;
            $copy->preserveWhiteSpace = false;

            if (! $copy->loadXML($signedXml)) {
                throw EventSignatureFailed::becauseTheSignatureDoesNotVerify();
            }

            $verifier = new XMLSecurityDSig('');
            $signature = $verifier->locateSignature($copy);

            if ($signature === null) {
                throw EventSignatureFailed::becauseTheSignatureDoesNotVerify();
            }

            $verifier->canonicalizeSignedInfo();

            if ($verifier->validateReference() !== true) {
                throw EventSignatureFailed::becauseTheSignatureDoesNotVerify();
            }

            $publicKey = new XMLSecurityKey(XMLSecurityKey::RSA_SHA1, ['type' => 'public']);
            $publicKey->loadKey($certificatePem, isFile: false, isCert: true);

            if ($verifier->verify($publicKey) !== 1) {
                throw EventSignatureFailed::becauseTheSignatureDoesNotVerify();
            }
        } catch (EventSignatureFailed $exception) {
            throw $exception;
        } catch (Throwable) {
            throw EventSignatureFailed::becauseTheSignatureDoesNotVerify();
        }
    }
}
