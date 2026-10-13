<?php

namespace Tests\Unit;

use App\Services\Fiscal\Exceptions\FiscalRequestNotSent;
use App\Services\Fiscal\Manifestacao\EventSignatureFailed;
use App\Services\Fiscal\Manifestacao\EventSigner;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Tests\Concerns\BuildsManifestationEvent;
use Tests\Concerns\BuildsThrowawayPkcs12;
use Tests\TestCase;

/**
 * A assinatura XMLDSig do evento de manifestação, no padrão que o Ambiente
 * Nacional valida.
 *
 * O que o serviço `nfeRecepcaoEvento` aceita está fixado no XSD oficial
 * `xmldsig-core-schema_v1.01.xsd` (PL_009): canonicalização inclusiva
 * (`REC-xml-c14n-20010315`), `rsa-sha1`, digest `sha1`, exatamente dois
 * `Transform` (enveloped-signature e C14N), `Reference` com `URI="#Id"` e
 * `KeyInfo/X509Data/X509Certificate`. Os algoritmos são `fixed` no schema,
 * então SHA-1 aqui não é escolha nossa: é o que o fisco publica.
 *
 * O `<Signature>` sai em namespace default, sem prefixo `ds:`, porque o
 * `FiscalXmlValidator` recusa qualquer elemento prefixado antes do envio.
 *
 * A conferência da assinatura é feita **sem** a biblioteca que assinou —
 * `DOMNode::C14N()` mais `openssl_verify` —, para o teste não passar só porque
 * a biblioteca concorda consigo mesma.
 *
 * Nada de segredo em asserção: a senha, o PEM e o XML não entram em mensagem
 * de exceção, e um dos testes fixa exatamente isso.
 */
class AssinaturaEventoManifestacaoTest extends TestCase
{
    use BuildsManifestationEvent;
    use BuildsThrowawayPkcs12;

    /** @var array{bytes: string, password: string, cert: string}|null */
    private static ?array $a1 = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$a1 = self::throwawayPkcs12();
    }

    public static function tearDownAfterClass(): void
    {
        self::$a1 = null;
        self::cleanupThrowawayPkcs12();

        parent::tearDownAfterClass();
    }

    public function test_assina_o_evento_com_signature_em_namespace_default_apos_o_inf_evento(): void
    {
        $signed = $this->signer()->sign($this->eventoDeCiencia(), self::$a1['bytes'], self::$a1['password']);

        $xpath = $this->xpath($signed);

        $signature = $xpath->query('/nfe:evento/ds:Signature');
        $this->assertSame(1, $signature->length, 'O Signature precisa ser filho direto de evento.');

        // Sem prefixo: o validador do módulo recusa `ds:` antes de qualquer byte.
        foreach ($xpath->query('//ds:*') as $element) {
            $this->assertSame('', (string) $element->prefix, "Elemento {$element->localName} saiu com prefixo.");
        }

        // A ordem é a do XSD: infEvento, depois Signature.
        $evento = $xpath->query('/nfe:evento')->item(0);
        $this->assertInstanceOf(DOMElement::class, $evento);
        $children = array_values(array_filter(
            iterator_to_array($evento->childNodes),
            fn ($node) => $node instanceof DOMElement,
        ));
        $this->assertSame(['infEvento', 'Signature'], array_map(fn (DOMElement $e) => $e->localName, $children));
    }

    public function test_a_assinatura_segue_os_algoritmos_fixados_pelo_xsd_do_fisco(): void
    {
        $signed = $this->signer()->sign($this->eventoDeCiencia(), self::$a1['bytes'], self::$a1['password']);

        $xpath = $this->xpath($signed);

        $this->assertSame(
            'http://www.w3.org/TR/2001/REC-xml-c14n-20010315',
            $this->attribute($xpath, '//ds:SignedInfo/ds:CanonicalizationMethod', 'Algorithm'),
        );
        $this->assertSame(
            'http://www.w3.org/2000/09/xmldsig#rsa-sha1',
            $this->attribute($xpath, '//ds:SignedInfo/ds:SignatureMethod', 'Algorithm'),
        );
        $this->assertSame('#'.self::ID_DO_EVENTO, $this->attribute($xpath, '//ds:Reference', 'URI'));
        $this->assertSame(
            'http://www.w3.org/2000/09/xmldsig#sha1',
            $this->attribute($xpath, '//ds:Reference/ds:DigestMethod', 'Algorithm'),
        );

        $transforms = $xpath->query('//ds:Reference/ds:Transforms/ds:Transform');
        $this->assertSame(2, $transforms->length, 'O XSD exige exatamente dois Transform.');
        $this->assertSame(
            [
                'http://www.w3.org/2000/09/xmldsig#enveloped-signature',
                'http://www.w3.org/TR/2001/REC-xml-c14n-20010315',
            ],
            array_map(fn (DOMElement $t) => $t->getAttribute('Algorithm'), iterator_to_array($transforms)),
        );

        $this->assertSame(1, $xpath->query('//ds:SignatureValue')->length);
        $this->assertSame(1, $xpath->query('//ds:KeyInfo/ds:X509Data/ds:X509Certificate')->length);
    }

    public function test_o_digest_e_o_sha1_do_inf_evento_canonizado_e_a_assinatura_confere_com_o_certificado(): void
    {
        $signed = $this->signer()->sign($this->eventoDeCiencia(), self::$a1['bytes'], self::$a1['password']);

        $xpath = $this->xpath($signed);

        // Digest: SHA-1 do infEvento em C14N inclusiva. O Signature é irmão e
        // não filho do infEvento, então a transformação enveloped não muda nada
        // aqui — o que faz do digest um cálculo direto no teste.
        $infEvento = $xpath->query('/nfe:evento/nfe:infEvento')->item(0);
        $this->assertInstanceOf(DOMElement::class, $infEvento);
        $this->assertSame(
            base64_encode(sha1($infEvento->C14N(false, false), true)),
            $this->text($xpath, '//ds:Reference/ds:DigestValue'),
        );

        // Assinatura: RSA-SHA1 sobre o SignedInfo canonizado, conferida com a
        // chave pública do certificado que foi para o KeyInfo — e que precisa
        // ser o mesmo certificado do A1 que assinou.
        $signedInfo = $xpath->query('//ds:SignedInfo')->item(0);
        $this->assertInstanceOf(DOMElement::class, $signedInfo);

        $x509 = $this->text($xpath, '//ds:X509Certificate');
        $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split($x509, 64, "\n").'-----END CERTIFICATE-----';
        $this->assertSame(
            openssl_x509_fingerprint(self::$a1['cert'], 'sha256'),
            openssl_x509_fingerprint($pem, 'sha256'),
            'O X509Certificate do KeyInfo não é o certificado do A1.',
        );

        $publicKey = openssl_pkey_get_public($pem);
        $this->assertNotFalse($publicKey);
        $this->assertSame(1, openssl_verify(
            $signedInfo->C14N(false, false),
            base64_decode($this->text($xpath, '//ds:SignatureValue'), true) ?: '',
            $publicKey,
            OPENSSL_ALGO_SHA1,
        ));
    }

    public function test_a_assinatura_nao_altera_o_inf_evento(): void
    {
        $original = $this->eventoDeCiencia();
        $signed = $this->signer()->sign($original, self::$a1['bytes'], self::$a1['password']);

        $before = $this->xpath($original)->query('/nfe:evento/nfe:infEvento')->item(0);
        $after = $this->xpath($signed)->query('/nfe:evento/nfe:infEvento')->item(0);

        $this->assertInstanceOf(DOMElement::class, $before);
        $this->assertInstanceOf(DOMElement::class, $after);
        $this->assertSame($before->C14N(false, false), $after->C14N(false, false));
    }

    public function test_senha_errada_falha_fechado_sem_vazar_senha_pem_ou_xml(): void
    {
        $evento = $this->eventoDeCiencia();

        try {
            $this->signer()->sign($evento, self::$a1['bytes'], 'senha-errada');
            $this->fail('Um A1 que não abre não pode produzir assinatura.');
        } catch (EventSignatureFailed $exception) {
            // "Sem enviar nada" é uma família de exceção, não uma frase: quem
            // chama trata `FiscalRequestNotSent` como requisição que não saiu.
            $this->assertInstanceOf(FiscalRequestNotSent::class, $exception);

            $message = $exception->getMessage();
            $this->assertStringNotContainsString('senha-errada', $message);
            $this->assertStringNotContainsString(self::$a1['password'], $message);
            $this->assertStringNotContainsString('PRIVATE KEY', $message);
            $this->assertStringNotContainsString('BEGIN CERTIFICATE', $message);
            $this->assertStringNotContainsString('<infEvento', $message);
            $this->assertStringNotContainsString(self::CHAVE_DE_ACESSO, $message);
        }
    }

    public function test_evento_sem_inf_evento_com_id_falha_fechado(): void
    {
        $semId = str_replace(' Id="'.self::ID_DO_EVENTO.'"', '', $this->eventoDeCiencia());

        $this->expectException(EventSignatureFailed::class);

        $this->signer()->sign($semId, self::$a1['bytes'], self::$a1['password']);
    }

    public function test_xml_malformado_falha_fechado(): void
    {
        $this->expectException(EventSignatureFailed::class);

        $this->signer()->sign('<evento><infEvento Id="x">', self::$a1['bytes'], self::$a1['password']);
    }

    public function test_bytes_que_nao_sao_pkcs12_falham_fechado(): void
    {
        $this->expectException(EventSignatureFailed::class);

        $this->signer()->sign($this->eventoDeCiencia(), 'isto nao e um pkcs12', self::$a1['password']);
    }

    private function signer(): EventSigner
    {
        return new EventSigner;
    }

    private function xpath(string $xml): DOMXPath
    {
        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = false;
        $this->assertTrue($dom->loadXML($xml), 'O XML devolvido precisa ser bem formado.');

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('nfe', self::NAMESPACE_NFE);
        $xpath->registerNamespace('ds', self::NAMESPACE_XMLDSIG);

        return $xpath;
    }

    private function attribute(DOMXPath $xpath, string $query, string $name): string
    {
        $node = $xpath->query($query)->item(0);
        $this->assertInstanceOf(DOMElement::class, $node, "Nenhum elemento em {$query}.");

        return $node->getAttribute($name);
    }

    private function text(DOMXPath $xpath, string $query): string
    {
        $node = $xpath->query($query)->item(0);
        $this->assertInstanceOf(DOMElement::class, $node, "Nenhum elemento em {$query}.");

        return preg_replace('/\s+/', '', $node->textContent) ?? '';
    }
}
