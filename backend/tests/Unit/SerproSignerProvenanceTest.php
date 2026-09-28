<?php

namespace Tests\Unit;

use App\Enums\SerproFailure;
use App\Services\SerproException;
use App\Support\SerproSigner;
use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * A assinatura do termo é superfície de correção, não de formatação: um XMLDSig
 * enveloped sutilmente errado só falha no provedor, com um código opaco, e um
 * termo é documento jurídico. Este teste existe para que a falha aconteça aqui.
 *
 * Ele prova quatro coisas, e a ordem delas é o argumento. Primeiro, que a
 * rotina registra de onde veio e com qual licença — uma cópia sem procedência é
 * código de terceiro que ninguém pode auditar. Segundo, que a assinatura
 * confere criptograficamente contra a chave pública do próprio certificado
 * assinado, o que é o que um validador faz. Terceiro, que a estrutura é a que o
 * XMLDSig manda: `SignedInfo`, `Reference URI=""` vazio, transform
 * `enveloped-signature`, RSA-SHA256, `X509Certificate`. Quarto, que nada do
 * componente oficial escapou para a aplicação: nenhuma das seis funções
 * globais, nenhum `echo`, nenhum fuso de processo mudado.
 *
 * **O componente oficial não é lido em tempo de execução, e o motivo é o ponto
 * deste arquivo.** Ele não passa no `php -l` como é distribuído, define globais,
 * imprime o documento assinado com `echo` e lança uma classe de exceção que o
 * próprio ZIP não declara. Ele é inspecionado, corrigido e isolado — a
 * proveniência e o SHA estão em `SerproSigner`, e o que foi portado e o que foi
 * reescrito estão no docblock daquela classe.
 *
 * **O que este arquivo não prova, e é importante que não pareça provar.** Ele
 * prova que a assinatura é bem-formada e criptograficamente válida. Não prova
 * que o provedor aceita o termo, que os papéis do documento são os que o
 * gateway espera, nem que o reenvio de um termo válido responde `304` com o
 * token. Dos três pontos do modelo que parecem erro, **um é corrigido** — o
 * espaço no nome `finalidade `, que não sobrevive ao documento assinado:
 * `createElement` lança `DOMException`, o `nodeName` do nó emitido pelo
 * SimpleXML já vem sem o espaço, e o `loadXML`/`saveXML` da própria
 * assinatura o apaga — e
 * **dois foram mantidos verbatim por decisão**, a vigência de 30 dias e a
 * canonicalização exclusiva do digest. O gate vale para os nomes e valores
 * resultantes: **nenhum termo pode ser emitido antes de um teste de contrato
 * provar a aceitação**. A decisão e o gate estão em `design.md` (D2) e na spec;
 * aqui o que cabe é provar a coincidência da canonicalização e falhar se ela
 * deixar de valer.
 *
 * O certificado é gerado em tempo de execução e não é versionado: `*.pfx` está
 * no `.gitignore` da raiz, e a regra é do arquivo inteiro, não do caso. Para
 * refazer à mão:
 *
 *     openssl req -x509 -newkey rsa:2048 -nodes -keyout key.pem \
 *         -out cert.pem -days 1 -subj '/CN=Escritorio de Teste:33683111000107'
 *     openssl pkcs12 -export -in cert.pem -inkey key.pem \
 *         -passout pass:senha-de-teste -out escritorio.pfx
 */
class SerproSignerProvenanceTest extends TestCase
{
    private const PASSWORD = 'senha-de-teste';

    private const XMLDSIG = 'http://www.w3.org/2000/09/xmldsig#';

    private const CANONICALIZATION = 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315';

    private const ENVELOPED_SIGNATURE = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';

    private const SIGNATURE_METHOD = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';

    private const DIGEST_METHOD = 'http://www.w3.org/2001/04/xmlenc#sha256';

    /**
     * O PKCS#12 de descarte, gerado uma vez por classe. O motivo de não ser
     * versionado está no docblock desta classe.
     */
    private static ?string $pfx = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$pfx = self::pkcs12();
    }

    /**
     * O termo sem assinatura, no formato que o `SerproTermSigner` vai montar: um
     * documento só, sem namespace próprio, o que é o que faz a canonicalização
     * exclusiva e a inclusiva coincidirem — a divergência que sobrou do modelo
     * de referência e que está anotada no `SerproSigner`.
     */
    private function unsignedTerm(): string
    {
        return <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <termoDeAutorizacao><dados><sistema id="API Integra Contador"/><destinatario numero="33683111000107" nome="Escritorio de Teste" tipo="PJ" papel="contratante"/><assinadoPor numero="33683111000107" nome="Escritorio de Teste" tipo="PJ" papel="autor pedido de dados"/></dados></termoDeAutorizacao>
            XML;
    }

    public function test_a_rotina_registra_a_proveniencia_e_a_licenca_do_componente_oficial(): void
    {
        $source = (new ReflectionClass(SerproSigner::class))->getFileName();
        $this->assertIsString($source);

        $contents = (string) file_get_contents($source);

        // Os quatro dados que tornam a cópia auditável. Se alguém trocar a
        // rotina por uma reimplementação sem registrar de onde veio, o arquivo
        // perde a URL e o teste aqui para — que é o alarme que interessa.
        $this->assertStringContainsString(SerproSigner::SOURCE_URL, $contents);
        $this->assertStringContainsString(SerproSigner::SOURCE_VERSION, $contents);
        $this->assertStringContainsString(SerproSigner::SOURCE_SHA256, $contents);
        $this->assertStringContainsString('MIT License', $contents);
        $this->assertStringContainsString('Copyright (c) 2022 SERPRO', $contents);

        // E o SHA tem que ser mesmo um SHA: 64 hexadecimais, nada de placeholder
        // que passe pela assertSame de baixo.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', SerproSigner::SOURCE_SHA256);
    }

    public function test_a_assinatura_confere_com_a_chave_publica_e_com_o_digest_declarado(): void
    {
        $signed = $this->sign();

        $document = $this->parse($signed);
        $signature = $this->signature($document);

        $signedInfo = $this->child($signature, 'SignedInfo');
        $value = $this->child($signature, 'SignatureValue');
        $x509 = $this->child($this->child($this->child($signature, 'KeyInfo'), 'X509Data'), 'X509Certificate');

        // A assinatura é conferida contra a chave pública que o próprio
        // documento carrega, que é de onde um validador a tiraria.
        $publicKey = openssl_pkey_get_public(
            $this->pemFrom((string) $x509->textContent),
        );
        $this->assertInstanceOf(\OpenSSLAsymmetricKey::class, $publicKey);

        $this->assertSame(1, openssl_verify(
            $signedInfo->C14N(true, false),
            base64_decode(trim($value->textContent), true),
            $publicKey,
            OPENSSL_ALGO_SHA256,
        ));

        // E o digest é refeito do jeito que a `Reference` declara: tirando a
        // `Signature` do documento (transform `enveloped-signature`) e
        // canonicalizando em c14n inclusiva. É a conferência que prova que a
        // `Reference URI=""` não é decorativa.
        $enveloped = $this->parse($signed);
        $envelopedSignature = $this->signature($enveloped);
        $enveloped->documentElement?->removeChild($envelopedSignature);

        $declaredDigest = base64_encode(openssl_digest(
            (string) $enveloped->documentElement?->C14N(false, false),
            'sha256',
            true,
        ));

        $this->assertSame(
            $declaredDigest,
            trim($this->child($this->child($signedInfo, 'Reference'), 'DigestValue')->textContent),
        );
    }

    public function test_a_assinatura_tem_a_estrutura_xmldsig_enveloped_rsa_sha256(): void
    {
        $document = $this->parse($this->sign());
        $signature = $this->signature($document);

        $this->assertSame(1, $this->xpath($document)->query('//ds:Signature')->length);

        $signedInfo = $this->child($signature, 'SignedInfo');

        $this->assertSame(self::CANONICALIZATION, $this->child($signedInfo, 'CanonicalizationMethod')->getAttribute('Algorithm'));
        $this->assertSame(self::SIGNATURE_METHOD, $this->child($signedInfo, 'SignatureMethod')->getAttribute('Algorithm'));

        // `URI=""` é o documento inteiro, e é o que o transform seguinte diz para
        // canonicalizar. Uma `Reference` com fragmento aqui é outro formato.
        $reference = $this->child($signedInfo, 'Reference');
        $this->assertSame('', $reference->getAttribute('URI'));
        $this->assertTrue($reference->hasAttribute('URI'));

        $transforms = $reference->getElementsByTagName('Transform');
        $this->assertSame(2, $transforms->length);
        $this->assertSame(self::ENVELOPED_SIGNATURE, $transforms->item(0)?->attributes->getNamedItem('Algorithm')?->nodeValue);
        $this->assertSame(self::CANONICALIZATION, $transforms->item(1)?->attributes->getNamedItem('Algorithm')?->nodeValue);

        $this->assertSame(self::DIGEST_METHOD, $this->child($reference, 'DigestMethod')->getAttribute('Algorithm'));

        // O digest declarado é um base64 de 32 bytes, e não um placeholder.
        $decoded = base64_decode(trim($this->child($reference, 'DigestValue')->textContent), true);
        $this->assertIsString($decoded);
        $this->assertSame(32, strlen($decoded));

        $this->assertNotSame('', trim($this->child($signature, 'SignatureValue')->textContent));
    }

    public function test_a_divergencia_de_canonicalizacao_do_modelo_nao_altera_o_digest_do_termo(): void
    {
        // O modelo de referência calcula o digest com `C14N` exclusiva e declara
        // na `Reference` a inclusiva da REC 2001. A rotina mantém a exclusiva
        // **por decisão**: corrigir mudaria os bytes de `DigestValue` em relação
        // ao exemplo do provedor, e o modelo é a única autoridade disponível,
        // porque a documentação do termo responde 500 e não publica XSD. A
        // decisão e o gate estão em `design.md` (D2) e na spec.
        //
        // A pergunta que este teste responde é deliberada: para o formato de
        // termo que ele exercita, as duas canonicalizações coincidem?
        //
        // A resposta é sim, e a razão é estrutural: a única declaração de
        // namespace da árvore é a da própria `Signature`, que o transform
        // `enveloped-signature` manda retirar antes da conta, e o elemento raiz
        // do termo não declara namespace algum.
        //
        // **O gatilho da divergência é mais largo do que parece, e este comentário
        // é a medida dele.** exclusiva e inclusiva divergem assim que o
        // documento carrega qualquer declaração de namespace, usada ou não: a
        // exclusiva renderiza a declaração no elemento que a usa, a inclusiva
        // renderiza onde ela foi declarada, e em
        // `<termoDeAutorizacao xmlns:ns1="urn:x"><ns1:dados/></termoDeAutorizacao>`
        // as duas produzem bytes diferentes. Dizer apenas "não declarar prefixo
        // próprio" seria mais fraco do que a verdade e deixaria de fora o termo
        // aninhado em um elemento que declara.
        //
        // Por isso a garantia é a que está escrita: **o elemento raiz do termo não
        // declara namespace algum, e o termo não é aninhado.** É o único dos
        // valores preservados que pode derivar sem ninguém tocar em código, e é
        // por isso que ele tem teste: um termo que declarasse namespace passaria
        // a divergir, e o digest gravado deixaria de ser o que um validador
        // recalcula. O documento real ainda não existe: quando o
        // `SerproTermSigner` montar o seu, é este teste que precisa passar a
        // exercitá-lo, e ele é o que avisa se a coincidência deixar de valer.
        $term = $this->parse($this->unsignedTerm());

        $exclusive = openssl_digest((string) $term->documentElement?->C14N(true, false), 'sha256', true);
        $inclusive = openssl_digest((string) $term->documentElement?->C14N(false, false), 'sha256', true);

        $this->assertSame($exclusive, $inclusive);

        // E o que a rotina gravou no documento assinado é esse mesmo valor, o
        // que fecha a conta entre o que o modelo faz e o que um validador faz.
        $document = $this->parse($this->sign());
        $signature = $this->signature($document);

        $this->assertSame(
            base64_encode($inclusive),
            trim($this->child($this->child($this->child($signature, 'SignedInfo'), 'Reference'), 'DigestValue')->textContent),
        );
    }

    public function test_o_termo_assinado_conserva_o_conteudo_do_documento_original(): void
    {
        $document = $this->parse($this->sign());
        $termo = $this->parse($this->unsignedTerm());

        // Assinar não pode reescrever o termo: a renovação reenvia exatamente
        // estes bytes, e um `saveXML` reformatado seria outro documento.
        $this->assertSame(
            $termo->documentElement?->C14N(false, false),
            $this->withoutSignature($document)->documentElement?->C14N(false, false),
        );
    }

    public function test_nenhuma_rotina_global_do_componente_oficial_chega_a_aplicacao(): void
    {
        // As seis funções que o script oficial define no escopo global — as
        // cinco do modelo de documento mais a de assinatura. Se alguém rodar
        // um dia `include` do arquivo do provedor, ou colar o script em vez da
        // rotina isolada, estas voltam a existir e colidem com o resto da
        // aplicação.
        foreach (['montarTermoAutorizacao', 'carregarCertificados', 'obterPrivateKey', 'obterCertificado', 'obterx509Certificate', 'assinar'] as $function) {
            $this->assertFalse(function_exists($function), sprintf('A função global `%s` do componente oficial não pode existir.', $function));
        }

        $before = array_keys($GLOBALS);
        $this->sign();

        $this->assertSame($before, array_keys($GLOBALS));
    }

    public function test_a_rotina_nao_muda_o_fuso_do_processo_nem_escreve_na_saida(): void
    {
        $timezone = date_default_timezone_get();

        // O buffer mede o `echo`: o modelo oficial imprimia o documento
        // assinado e o mesmo documento em base64 na saída padrão, e uma
        // impressão nessa chamada é exatamente o defeito.
        $levelBefore = ob_get_level();
        ob_start();

        try {
            $signed = $this->sign();
        } finally {
            $written = (string) ob_get_clean();
        }

        // Nenhum buffer ficou aberto: um `ob_start()` sem par correspondente
        // empurra a escrita para o buffer de dentro do PHPUnit e faz o teste
        // seguinte medir a saída de outro teste.
        $this->assertSame($levelBefore, ob_get_level());
        $this->assertSame('', $written);
        $this->assertNotSame('', $signed);

        // O `date_default_timezone_set('America/Sao_Paulo')` vinha na linha 2 do
        // arquivo oficial e mudava o fuso do processo inteiro como efeito
        // colateral de carregar o arquivo. Aqui o fuso é de quem chamou.
        $this->assertSame($timezone, date_default_timezone_get());
    }

    public function test_documento_que_nao_e_xml_e_recusado(): void
    {
        // A entrada da assinatura é um documento que o `SerproTermSigner` montou;
        // se o que chegou não é XML, a falha é de montagem e ela é nomeada como
        // tal, e não como um termo que o provedor recusaria.
        $this->expectException(SerproException::class);

        (new SerproSigner)->sign('isto não é um termo', (string) self::$pfx, self::PASSWORD);
    }

    public function test_certificado_que_nao_abre_com_a_senha_e_recusado_sem_nomear_a_senha(): void
    {
        try {
            (new SerproSigner)->sign($this->unsignedTerm(), (string) self::$pfx, 'senha-errada');
            $this->fail('Uma senha que não abre o certificado não deveria chegar à assinatura.');
        } catch (SerproException $exception) {
            $this->assertSame(SerproFailure::NotSent, $exception->failure);
            $this->assertSame(0, $exception->status);
            $this->assertStringNotContainsString('senha-errada', $exception->getMessage());
            $this->assertStringNotContainsString('Escritorio de Teste', $exception->getMessage());
        }
    }

    public function test_documento_sem_elemento_raiz_e_recusado(): void
    {
        // A `Reference` do termo aponta para o documento inteiro, e um documento
        // sem elemento raiz não tem o que ter assinatura.
        $this->expectException(SerproException::class);

        (new SerproSigner)->sign('<?xml version="1.0"?>', (string) self::$pfx, self::PASSWORD);
    }

    private function sign(): string
    {
        return (new SerproSigner)->sign($this->unsignedTerm(), (string) self::$pfx, self::PASSWORD);
    }

    private function parse(string $xml): DOMDocument
    {
        $document = new DOMDocument;
        $document->preserveWhiteSpace = true;
        $document->formatOutput = false;

        $this->assertTrue($document->loadXML($xml, LIBXML_NONET), 'O termo assinado deveria ser XML legível.');

        return $document;
    }

    private function signature(DOMDocument $document): DOMElement
    {
        $nodes = $this->xpath($document)->query('//ds:Signature');

        $this->assertSame(1, $nodes->length, 'O termo assinado deveria ter exatamente uma Signature.');

        $signature = $nodes->item(0);
        $this->assertInstanceOf(DOMElement::class, $signature);

        return $signature;
    }

    private function child(DOMElement $parent, string $name): DOMElement
    {
        $child = $parent->getElementsByTagNameNS(self::XMLDSIG, $name)->item(0);

        $this->assertInstanceOf(DOMElement::class, $child, sprintf('Faltou `%s` na assinatura.', $name));

        return $child;
    }

    private function withoutSignature(DOMDocument $document): DOMDocument
    {
        $signature = $this->signature($document);
        $document->documentElement?->removeChild($signature);

        return $document;
    }

    /**
     * A XPath com o prefixo `ds` registrado. Registrar o prefixo no objeto
     * importa porque o `Signature` do XMLDSig é criado sem namespace e ganha o
     * `xmlns` como atributo — a resolução por prefixo é o que um validador faz
     * com o documento já serializado, e é o que este teste precisa fazer.
     */
    private function xpath(DOMDocument $document): DOMXPath
    {
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('ds', self::XMLDSIG);

        return $xpath;
    }

    private function pemFrom(string $base64): string
    {
        return "-----BEGIN CERTIFICATE-----\n"
            .chunk_split(trim($base64), 64, "\n")
            .'-----END CERTIFICATE-----'."\n";
    }

    /**
     * PKCS#12 de descarte, gerado uma vez por classe. O motivo de não ser
     * versionado está no docblock desta classe.
     */
    private static function pkcs12(): string
    {
        $config = file_exists('/etc/ssl/openssl.cnf') ? ['config' => '/etc/ssl/openssl.cnf'] : [];

        $key = openssl_pkey_new(array_merge(
            ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA],
            $config
        ));
        $csr = $key === false
            ? false
            : openssl_csr_new(['CN' => 'Escritorio de Teste:33683111000107'], $key, array_merge(['digest_alg' => 'sha256'], $config));
        $certificate = $csr === false
            ? false
            : openssl_csr_sign($csr, null, $key, 1, array_merge(['digest_alg' => 'sha256'], $config));

        $contents = '';
        $exported = $certificate !== false && openssl_pkcs12_export($certificate, $contents, $key, self::PASSWORD);

        if (! $exported || $contents === '') {
            throw new \RuntimeException('Não foi possível gerar o certificado de teste.');
        }

        return $contents;
    }
}
