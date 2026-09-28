<?php

namespace Tests\Unit;

use App\Enums\SerproFailure;
use App\Models\Account;
use App\Models\AccountCertificate;
use App\Models\SerproConnection;
use App\Services\SerproException;
use App\Services\SerproTermSigner;
use Carbon\Carbon;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use OpenSSLAsymmetricKey;
use RuntimeException;
use Tests\TestCase;

/**
 * O termo de autorização é documento jurídico, e a assinatura dele é a única
 * coisa que um terceiro valida sem nunca ver o original. Por isso o que este
 * teste prova é **a assinatura**, e não a forma do XML: um documento que
 * contém um elemento `Signature` prova que alguém escreveu a palavra, e a
 * prova de que a assinatura vale é `openssl_verify()` devolver `1` contra a
 * chave pública do próprio certificado.
 *
 * **O que os bytes têm que garantir.** O termo é renovado reenviando exatamente
 * os mesmos bytes, e é esse reenvio que devolve `304` com o token no `ETag`
 * sem re-assinar nada. Um documento que mudasse de byte entre a persistência e
 * o reenvio seria outro documento, e a renovação seria uma emissão disfarçada.
 * Por isso a normalização acontece **antes** da assinatura, nenhuma
 * normalização acontece depois, e nada reserializa o resultado.
 *
 * **As quatro constantes de formato são declarações, e declaração não é
 * derivação.** O digest do gate lê o template do termo concatenado com o
 * período de vigência, o algoritmo de canonicalização, a regra de normalização
 * e o fuso das datas — porque nenhuma delas aparece no documento, e por isso
 * um gate que só lesse o template ficaria aberto depois de uma mudança que o
 * mudaria. Ler as constantes é o necessário e não é o suficiente: o que
 * interessa é que a assinatura **use** o valor declarado, e é isso que os casos
 * de vínculo aqui testam — a vigência emitida sai da constante de período, o
 * algoritmo declarado no `SignedInfo` é a constante de canonicalização, a regra
 * nomeia exatamente os caracteres que saem do documento, e a data sai no fuso
 * declarado.
 *
 * O e-CNPJ é gerado em tempo de execução e não é versionado: `*.pfx` e `*.p12`
 * estão no `.gitignore` da raiz, e a regra é do arquivo inteiro, não do caso.
 */
class SerproTermSignerTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'senha-de-teste';

    private const ESCRITORIO = '33683111000107';

    private const PLATAFORMA = '12345678000195';

    private const RAZAO_SOCIAL_PLATAFORMA = 'Plataforma de Teste';

    private const XMLDSIG = 'http://www.w3.org/2000/09/xmldsig#';

    private const ENVELOPED_SIGNATURE = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';

    private const RSA_SHA256 = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';

    private const SHA256 = 'http://www.w3.org/2001/04/xmlenc#sha256';

    /** O instante de referência dos casos de data, em fuso de São Paulo. */
    private const REFERENCIA = '2026-03-10 09:30:00';

    private static ?string $pfx = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$pfx = self::pkcs12();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // A credencial de plataforma é o que dá ao termo o nome e o documento do
        // contratante, e é uma linha só — o índice único de `singleton` garante
        // que não há segunda.
        SerproConnection::factory()->create([
            'certificate_subject' => 'CN='.self::RAZAO_SOCIAL_PLATAFORMA.':'.self::PLATAFORMA,
            'contratante_numero' => self::PLATAFORMA,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---------------------------------------------------------------- estrutura

    public function test_a_assinatura_confere_com_a_chave_publica_do_certificado_do_escritorio(): void
    {
        [$conta, $certificado] = $this->escritorio('Escritório de Teste');

        $assinada = $this->assinar($conta, $certificado);
        $documento = $this->parse($assinada);
        $assinatura = $this->signature($documento);

        // A chave sai do PKCS#12 que a assinatura usou, e não de um par de
        // chaves separado: é de onde um validador a tiraria, e é a única forma de
        // a afirmação "a assinatura vale" dizer algo.
        $lido = [];
        $this->assertTrue(openssl_pkcs12_read((string) self::$pfx, $lido, self::PASSWORD));

        $chave = openssl_pkey_get_public($lido['cert']);
        $this->assertInstanceOf(OpenSSLAsymmetricKey::class, $chave);

        $signedInfo = $this->child($assinatura, 'SignedInfo');
        $valor = $this->child($assinatura, 'SignatureValue');

        $this->assertSame(1, openssl_verify(
            (string) $signedInfo->C14N(true, false),
            base64_decode(trim($valor->textContent), true),
            $chave,
            OPENSSL_ALGO_SHA256,
        ), 'A assinatura do termo não confere com a chave pública do e-CNPJ do escritório.');

        // E o digest é refeito do jeito que a `Reference` declara: tirando a
        // `Signature` do documento e canonicalizando em c14n da REC 2001. É a
        // conferência que prova que a `Reference URI=""` não é decorativa.
        $this->assertSame(
            base64_encode(openssl_digest($this->hasheado($assinada), 'sha256', true)),
            trim($this->child($this->child($signedInfo, 'Reference'), 'DigestValue')->textContent),
        );
    }

    public function test_a_estrutura_xmldsig_enveloped_rsa_sha256_e_a_esperada(): void
    {
        [$conta, $certificado] = $this->escritorio('Escritório de Teste');

        $documento = $this->parse($this->assinar($conta, $certificado));
        $assinatura = $this->signature($documento);

        $this->assertSame(1, $this->xpath($documento)->query('//ds:Signature')->length);

        $signedInfo = $this->child($assinatura, 'SignedInfo');
        $this->assertTrue($assinatura->isSameNode($signedInfo->parentNode), 'O SignedInfo é filho da Signature.');

        $reference = $this->child($signedInfo, 'Reference');
        $this->assertTrue($reference->hasAttribute('URI'));
        $this->assertSame('', $reference->getAttribute('URI'));

        $transforms = $reference->getElementsByTagName('Transform');
        $this->assertSame(2, $transforms->length);
        $this->assertSame(self::ENVELOPED_SIGNATURE, $transforms->item(0)?->attributes->getNamedItem('Algorithm')?->nodeValue);
        $this->assertSame(SerproTermSigner::ALGORITMO_CANONICALIZACAO, $transforms->item(1)?->attributes->getNamedItem('Algorithm')?->nodeValue);

        $this->assertSame(self::RSA_SHA256, $this->child($signedInfo, 'SignatureMethod')->getAttribute('Algorithm'));
        $this->assertSame(self::SHA256, $this->child($reference, 'DigestMethod')->getAttribute('Algorithm'));

        $decodificado = base64_decode(trim($this->child($reference, 'DigestValue')->textContent), true);
        $this->assertIsString($decodificado);
        $this->assertSame(32, strlen($decodificado));

        $certificadoNoDocumento = $this->child(
            $this->child($this->child($assinatura, 'KeyInfo'), 'X509Data'),
            'X509Certificate',
        );

        $this->assertStringNotContainsString('-----BEGIN', $certificadoNoDocumento->textContent);
    }

    public function test_o_termo_nomeia_a_plataforma_como_contratante_e_o_escritorio_como_autor_do_pedido(): void
    {
        [$conta, $certificado] = $this->escritorio('Escritório de Teste');

        $documento = $this->parse($this->assinar($conta, $certificado));

        // O modelo de referência do provedor dá ao elemento `destinatario` o
        // papel `contratante` e ao `assinadoPor` o papel
        // `autor pedido de dados`, e o `CONTEXT.md` diz que o contratante
        // daqui é a plataforma, nunca a conta. O escritório é quem assina — é de
        // quem é o e-CNPJ —, então ele é o `assinadoPor`.
        $destinatario = $this->elemento($documento, 'destinatario');
        $this->assertSame(self::PLATAFORMA, $destinatario->getAttribute('numero'));
        $this->assertSame('contratante', $destinatario->getAttribute('papel'));
        $this->assertSame('PJ', $destinatario->getAttribute('tipo'));
        $this->assertSame(self::RAZAO_SOCIAL_PLATAFORMA, $destinatario->getAttribute('nome'));

        $assinadoPor = $this->elemento($documento, 'assinadoPor');
        $this->assertSame(self::ESCRITORIO, $assinadoPor->getAttribute('numero'));
        $this->assertSame('autor pedido de dados', $assinadoPor->getAttribute('papel'));
        $this->assertSame('PJ', $assinadoPor->getAttribute('tipo'));
        $this->assertSame('Escritório de Teste', $assinadoPor->getAttribute('nome'));
    }

    public function test_o_nome_do_escritorio_sobrevive_a_uma_razao_social_com_ampersand(): void
    {
        // `&` é letra normal em nome de empresa, e o atributo do documento é o
        // único lugar onde ela passa. A construção com `createAttribute` e
        // depois `$attr->value` emite aviso de entidade malformada e **grava o
        // atributo vazio** — o termo sairia com o nome em branco sem nenhuma
        // exceção, que é o pior formato de defeito para documento jurídico.
        [$conta, $certificado] = $this->escritorio('Silva & Consultoria <Ltda>');

        $documento = $this->parse($this->assinar($conta, $certificado));

        $this->assertSame('Silva & Consultoria <Ltda>', $this->elemento($documento, 'assinadoPor')->getAttribute('nome'));
    }

    public function test_o_termo_traz_o_texto_do_modelo_de_referencia_e_a_finalidade_sem_o_espaco(): void
    {
        [$conta, $certificado] = $this->escritorio('Escritório de Teste');

        $documento = $this->parse($this->assinar($conta, $certificado));

        $this->assertSame('API Integra Contador', $this->elemento($documento, 'sistema')->getAttribute('id'));
        $this->assertStringContainsString('AUTOR DO PEDIDO DE DADOS', $this->elemento($documento, 'termo')->getAttribute('texto'));
        $this->assertStringContainsString('13.709', $this->elemento($documento, 'avisoLegal')->getAttribute('texto'));

        // O modelo traz `addChild('finalidade ')` com espaço no nome. O nome com
        // espaço não sobrevive ao documento assinado — o `createElement` o recusa
        // e o round trip da assinatura consome o espaço como separador de tag
        // —, e o elemento se chama `finalidade`.
        $this->assertSame(1, $this->xpath($documento)->query('//finalidade')->length);
        $this->assertSame(0, $this->xpath($documento)->query("//*[local-name()='finalidade ']")->length);
        $this->assertStringContainsString(
            'A finalidade única e exclusiva desse TERMO DE AUTORIZAÇÃO',
            $this->elemento($documento, 'finalidade')->getAttribute('texto'),
        );
    }

    /**
     * O `KeyInfo` é onde um terceiro decide em quem confiar.
     *
     * A assinatura já é conferida contra a chave do PKCS#12 que a rotina usou, e
     * isso prova que a conta fecha. Não prova que o **documento** leva o
     * certificado certo: um validador não tem os parâmetros de quem assinou — ele
     * lê o `X509Certificate` que está no documento e confere a assinatura contra
     * a chave pública que encontrou ali. Se o `KeyInfo` trouxesse outro
     * certificado, a assinatura deixaria de fechar para quem valida, e o
     * documento é enviado ao provedor justamente para ser validado por ele.
     */
    public function test_o_x509_do_termo_assinado_e_o_certificado_do_escritorio(): void
    {
        [$conta, $certificado] = $this->escritorio('Escritório de Teste');

        $documento = $this->parse($this->assinar($conta, $certificado));

        $x509 = $this->child(
            $this->child($this->child($this->signature($documento), 'KeyInfo'), 'X509Data'),
            'X509Certificate',
        );

        $doTermo = openssl_x509_parse($this->pemFrom(trim($x509->textContent)));
        $this->assertIsArray($doTermo, 'O X509Certificate do termo deveria ser um certificado legível.');

        $lido = [];
        $this->assertTrue(openssl_pkcs12_read((string) self::$pfx, $lido, self::PASSWORD));
        $doPfx = openssl_x509_parse($lido['cert']);
        $this->assertIsArray($doPfx);

        // O par que decide é o titular e a chave pública. O número de série
        // entra como confirmação de que é o mesmo certificado e não outro com o
        // mesmo titular — e o que realmente amarra é a chave, que é a que a
        // assinatura foi conferida: é o vínculo que um validador faz sem ter os
        // parâmetros de quem assinou.
        $this->assertSame($doPfx['serialNumber'], $doTermo['serialNumber']);
        $this->assertSame($doPfx['subject'], $doTermo['subject']);
        $this->assertSame($doPfx['name'], $doTermo['name']);

        $this->assertSame(
            openssl_pkey_get_details(openssl_pkey_get_public($lido['cert']))['key'],
            openssl_pkey_get_details(openssl_pkey_get_public($this->pemFrom(trim($x509->textContent))))['key'],
        );
    }

    /**
     * A `Signature` declara o namespace dela e **nada mais**.
     *
     * A rotina declara `CanonicalizationMethod` como a inclusiva da REC 2001 e
     * canonicaliza o `SignedInfo` com a **exclusiva** — a divergência do modelo
     * de referência, que só não aparece porque o termo não declara namespace
     * algum. Um `xmlns` a mais, declarado e não usado, basta para as duas formas
     * divergirem, e quem sofre com isso é o validador do provedor, que
     * canonicaliza em inclusiva como manda a `Reference`: a falha aparece lá,
     * com um código opaco, e não aqui.
     */
    public function test_a_signature_declara_o_seu_namespace_e_nenhum_outro_atributo(): void
    {
        [$conta, $certificado] = $this->escritorio('Escritório de Teste');

        $documento = $this->parse($this->assinar($conta, $certificado));
        $assinatura = $this->signature($documento);

        // **A asserção é sobre os bytes serializados, e não sobre a visão do
        // DOM.** A declaração de namespace padrão não aparece em
        // `$assinatura->attributes` — o libxml a consome como namespace e só a
        // devolve na serialização —, e é na serialização que o validador do
        // provedor a lê. Um `xmlns:algo` a mais, declarado e não usado, é
        // suficiente para a inclusiva e a exclusiva divergirem, e essa é
        // exatamente a divergência que a rotina de propósito não pode ter.
        $serializado = (string) $documento->saveXML($assinatura);

        $this->assertSame(1, preg_match('/^<Signature\s+([^>]*)>/', $serializado, $achado), 'A tag de abertura da Signature deveria ser legível.');
        $this->assertSame('xmlns="'.self::XMLDSIG.'"', trim($achado[1]));

        // E o `SignedInfo` — o que a rotina de fato assina — está no namespace,
        // o que é o que faz a `Reference` funcionar para quem valida.
        $this->assertSame(self::XMLDSIG, $assinatura->namespaceURI);
    }

    public function test_a_raiz_do_termo_nao_declara_namespace_nenhum(): void
    {
        [$conta, $certificado] = $this->escritorio('Escritório de Teste');

        $documento = $this->parse($this->assinar($conta, $certificado));

        // A única declaração de namespace do documento é a da própria
        // `Signature`, que o transform `enveloped-signature` manda retirar antes
        // da conta do digest. Sem esta garantia a canonicalização exclusiva do
        // modelo e a inclusiva que a `Reference` declara divergem, e o
        // `DigestValue` gravado deixa de ser o que um validador recalcula.
        $this->assertFalse((bool) $documento->documentElement?->hasAttribute('xmlns'));

        foreach ((new DOMXPath($documento))->query('//*') as $no) {
            $this->assertInstanceOf(DOMElement::class, $no);

            if ($no->localName === 'Signature') {
                continue;
            }

            $this->assertFalse($no->hasAttribute('xmlns'), sprintf('O elemento `%s` declara namespace.', $no->nodeName));
        }
    }

    // ------------------------------------------------------------ normalização

    public function test_o_unicode_invisivel_sai_antes_de_assinar_e_nao_dos_bytes_assinados(): void
    {
        // O U+200B é o caso que o provedor devolve como
        // `AcessoNegado-AUTENTICAPROCURADOR-013`: ele sobrevive a qualquer
        // comparação de string e quebra a validação do nome. O nome do
        // escritório vem do cadastro da conta, que é texto digitado.
        [$conta, $certificado] = $this->escritorio("Escritório\u{200B} de Teste");
        [$limpa, $certificadoLimpo] = $this->escritorio('Escritório de Teste');

        $assinada = $this->assinar($conta, $certificado);
        $this->assertStringNotContainsString("\u{200B}", $assinada);

        // A afirmação que importa é sobre os **bytes que foram hasheados**, e
        // não sobre a string de entrada: o documento é aberto, a `Signature` é
        // retirada — que é o transform `enveloped-signature` — e o que resta é
        // canonicalizado do mesmo jeito que a rotina fez.
        $hasheado = $this->hasheado($assinada);

        $this->assertStringNotContainsString("\u{200B}", $hasheado);
        $this->assertStringContainsString('Escritório de Teste', $hasheado);

        // E a prova de que a remoção é **antes** da assinatura, e não uma
        // limpeza de depois: o mesmo termo com e sem o caractere invisível
        // produz exatamente os mesmos bytes assinados. Se a normalização
        // rodasse depois, o digest — e com ele a assinatura — divergiria.
        $this->assertSame($assinada, $this->assinar($limpa, $certificadoLimpo));
    }

    public function test_a_regra_de_normalizacao_nomeia_exatamente_os_caracteres_que_saem(): void
    {
        $declarado = array_map('trim', explode(',', substr(SerproTermSigner::REGRA_NORMALIZACAO, strlen('remove:'))));

        $this->assertNotSame([], $declarado);

        // **A regra é uma afirmação sobre o que quebra do lado do provedor**, e
        // por isso o conjunto é conferido, não só percorrido. Percorrer o que a
        // constante declara provaria que a constante é coerente consigo mesma:
        // estreitar a regra para um único ponto passaria por esse teste, deixaria
        // de remover três e mudaria o documento sem mudar o digest de ninguém.
        $this->assertSame(['U+200B', 'U+200C', 'U+200D', 'U+FEFF'], $declarado);

        foreach ($declarado as $ponto) {
            $this->assertMatchesRegularExpression('/^U\+[0-9A-F]{4,6}$/', $ponto, 'A regra nomeia pontos de código, e não texto solto.');

            $caractere = mb_chr((int) hexdec(substr($ponto, 2)), 'UTF-8');

            [$conta, $certificado] = $this->escritorio("Escritório{$caractere}de Teste");

            $this->assertStringNotContainsString($caractere, $this->assinar($conta, $certificado));
        }

        // O contra-teste: um caractere que a regra **não** nomeia fica no
        // documento. Uma regra que removesse tudo seria invariante por acaso e
        // não diria nada sobre o que ela cobre.
        [$conta, $certificado] = $this->escritorio("Escritório\u{00A0}de Teste");

        $this->assertStringContainsString("\u{00A0}", $this->assinar($conta, $certificado));
    }

    // ------------------------------------------------- constantes e comportamento

    public function test_a_vigencia_emitida_e_o_periodo_declarado_aplicado_ao_instante_de_referencia(): void
    {
        Carbon::setTestNow(Carbon::parse(self::REFERENCIA, 'America/Sao_Paulo'));

        [$conta, $certificado] = $this->escritorio('Escritório de Teste');

        $documento = $this->parse($this->assinar($conta, $certificado));
        $referencia = Carbon::parse(self::REFERENCIA, 'America/Sao_Paulo');

        $this->assertSame($referencia->format('Ymd'), $this->elemento($documento, 'dataAssinatura')->getAttribute('data'));
        $this->assertSame(
            $referencia->copy()->addDays(SerproTermSigner::PERIODO_VIGENCIA_DAYS)->format('Ymd'),
            $this->elemento($documento, 'vigencia')->getAttribute('data'),
        );

        // A vigência é a que o modelo de referência traz — trinta dias —, e a
        // constante é a que o código usa. Se alguém escrevesse `addDays(45)` sem
        // tocar na constante, esta é a asserção que quebra: o gate continuaria
        // batendo com um documento que ninguém testou.
        $this->assertSame(30, SerproTermSigner::PERIODO_VIGENCIA_DAYS);
    }

    public function test_a_data_do_termo_e_escrita_no_fuso_de_sao_paulo(): void
    {
        // Meia-noite em São Paulo ainda é o dia anterior em UTC. Um `now()` sem
        // fuso declararia a assinatura no dia errado da fronteira.
        Carbon::setTestNow(Carbon::parse('2026-03-10 00:30:00', 'UTC'));

        [$conta, $certificado] = $this->escritorio('Escritório de Teste');

        $documento = $this->parse($this->assinar($conta, $certificado));

        $this->assertSame('20260309', $this->elemento($documento, 'dataAssinatura')->getAttribute('data'));
    }

    public function test_o_algoritmo_declarado_no_termo_assinado_e_a_constante_de_canonicalizacao(): void
    {
        [$conta, $certificado] = $this->escritorio('Escritório de Teste');

        $documento = $this->parse($this->assinar($conta, $certificado));
        $signedInfo = $this->child($this->signature($documento), 'SignedInfo');

        $this->assertSame(
            SerproTermSigner::ALGORITMO_CANONICALIZACAO,
            $this->child($signedInfo, 'CanonicalizationMethod')->getAttribute('Algorithm'),
        );

        // E o que a rotina usou para canonicalizar é o mesmo: um documento sem
        // declaração de namespace é byte a byte igual nas duas formas, e é isso
        // que permite manter a exclusiva do modelo sem divergir do que a
        // `Reference` declara.
        $termo = $this->parse(SerproTermSigner::template());

        $this->assertSame(
            (string) $termo->documentElement?->C14N(false, false),
            (string) $termo->documentElement?->C14N(true, false),
        );
    }

    public function test_o_digest_do_formato_le_o_template_e_as_quatro_constantes(): void
    {
        $template = $this->canonicalTemplate();
        $periodo = (string) SerproTermSigner::PERIODO_VIGENCIA_DAYS;
        $algoritmo = SerproTermSigner::ALGORITMO_CANONICALIZACAO;
        $regra = SerproTermSigner::REGRA_NORMALIZACAO;
        $fuso = SerproTermSigner::FUSO;

        $this->assertSame(hash('sha256', $this->juncao([$template, $periodo, $algoritmo, $regra, $fuso])), SerproTermSigner::formatDigest());

        // O gate é auto-invalidante porque as quatro constantes e o template
        // estão **dentro** da entrada hasheada. Cada uma tem aqui a prova de que
        // a conta muda quando ela muda — que é o contrário do que um teste que
        // só lê o rótulo da constante provaria.
        $this->assertNotSame(SerproTermSigner::formatDigest(), hash('sha256', $this->juncao([$template, '31', $algoritmo, $regra, $fuso])));
        $this->assertNotSame(SerproTermSigner::formatDigest(), hash('sha256', $this->juncao([$template, $periodo, $algoritmo.' ', $regra, $fuso])));
        $this->assertNotSame(SerproTermSigner::formatDigest(), hash('sha256', $this->juncao([$template, $periodo, $algoritmo, $regra.' ', $fuso])));
        $this->assertNotSame(SerproTermSigner::formatDigest(), hash('sha256', $this->juncao([$template.'<dados/>', $periodo, $algoritmo, $regra, $fuso])));
    }

    /**
     * O fuso entra no digest, e a razão é a mesma das outras constantes.
     *
     * O fuso não aparece em lugar nenhum do template, e o que ele muda é a
     * `dataAssinatura` — que é valor por termo, com placeholder. Trocá-lo
     * mudaria o documento que o provedor recebe sem mudar uma byte do que o
     * gate lê, e uma prova de contrato já gravada continuaria autorizando o
     * termo novo. É a mesma falha que a spec descreve para o período e para a
     * normalização, e por isso a mesma resposta: a constante vai para dentro da
     * entrada hasheada.
     */
    public function test_o_fuso_entra_no_digest_e_nao_via_por_ele(): void
    {
        $template = $this->canonicalTemplate();
        $partes = [
            $template,
            (string) SerproTermSigner::PERIODO_VIGENCIA_DAYS,
            SerproTermSigner::ALGORITMO_CANONICALIZACAO,
            SerproTermSigner::REGRA_NORMALIZACAO,
            SerproTermSigner::FUSO,
        ];

        $this->assertSame(SerproTermSigner::formatDigest(), hash('sha256', $this->juncao($partes)));

        // Um fuso diferente é um documento diferente — na fronteira da meia-noite
        // a `dataAssinatura` cai no dia anterior — e o digest precisa dizer isso.
        $outro = array_merge(array_slice($partes, 0, -1), ['America/Belem']);

        $this->assertNotSame(SerproTermSigner::formatDigest(), hash('sha256', $this->juncao($outro)));
    }

    /**
     * O fuso declarado é o fuso que a data do termo sai, e não o do processo.
     *
     * Um `now()` sem fuso declararia a assinatura no fuso do servidor, que em
     * produção não é o de São Paulo — e o dia da `dataAssinatura` é a
     * informação que o provedor compara com a vigência.
     */
    public function test_a_data_do_termo_sai_no_fuso_declarado_e_nao_no_do_processo(): void
    {
        // 23:30 em São Paulo são 02:30 do dia seguinte em UTC: com o relógio do
        // processo em UTC, `now()` sem fuso declararia o dia seguinte.
        Carbon::setTestNow(Carbon::parse('2026-03-10 02:30:00', 'UTC'));

        [$conta, $certificado] = $this->escritorio('Escritório de Teste');

        $documento = $this->parse($this->assinar($conta, $certificado));

        $this->assertSame('America/Sao_Paulo', SerproTermSigner::FUSO);
        $this->assertSame('20260309', $this->elemento($documento, 'dataAssinatura')->getAttribute('data'));
    }

    public function test_o_template_do_formato_nao_carrega_nome_nem_documento_de_escritorio(): void
    {
        [$primeiro, $certificadoPrimeiro] = $this->escritorio('Escritório Alfa');
        [$segundo, $certificadoSegundo] = $this->escritorio('Contabilidade Beta Ltda');

        $template = SerproTermSigner::template();

        // O digest identifica o **formato**, não a instância: a prova de
        // contrato de um escritório tem de cobrir o documento do outro, e isso
        // só vale se o que entra no digest não varia por escritório.
        $this->assertStringNotContainsString('Escritório Alfa', $template);
        $this->assertStringNotContainsString('Contabilidade Beta', $template);
        $this->assertStringNotContainsString(self::ESCRITORIO, $template);
        $this->assertStringNotContainsString(self::PLATAFORMA, $template);
        $this->assertStringContainsString(SerproTermSigner::PLACEHOLDER, $template);

        // E o placeholder é ASCII puro, que é a razão de ele ser ASCII: um
        // template com caractere invisível mudaria de byte sob a normalização, e
        // o digest ficaria sensível a ela pelo motivo errado — pareceria que o
        // template cobre a regra quando quem a cobre é a constante.
        $invisiveis = array_map(
            static fn (string $ponto): string => mb_chr((int) hexdec(substr(trim($ponto), 2)), 'UTF-8'),
            explode(',', substr(SerproTermSigner::REGRA_NORMALIZACAO, strlen('remove:'))),
        );

        $this->assertSame($template, str_replace($invisiveis, '', $template));
        $this->assertSame(1, preg_match('/^[\x20-\x7E]+$/', SerproTermSigner::PLACEHOLDER), 'O placeholder tem de ser ASCII simples.');

        // E a razão de o nome sumir do template: o digest tem de ser o mesmo
        // para o mesmo formato, e o nome de um escritório tem U+200B, acento e
        // o comprimento que a razão social quiser.
        $this->assertNotSame(
            $this->assinar($primeiro, $certificadoPrimeiro),
            $this->assinar($segundo, $certificadoSegundo),
        );
    }

    // ---------------------------------------------------- estabilidade e sigilo

    public function test_os_bytes_assinados_sao_os_mesmos_para_o_mesmo_instante(): void
    {
        Carbon::setTestNow(Carbon::parse(self::REFERENCIA, 'America/Sao_Paulo'));

        [$conta, $certificado] = $this->escritorio('Escritório de Teste');

        $primeira = $this->assinar($conta, $certificado);
        $segunda = $this->assinar($conta, $certificado);

        // A renovação reenvia exatamente estes bytes, e é o `304` do provedor que
        // evita re-assinar. Uma segunda chamada que devolvesse outra sequência
        // de bytes tornaria a renovação uma emissão disfarçada — e a rotina de
        // origem não reserializa nada depois da assinatura, que é o que
        // garante isto.
        $this->assertSame($primeira, $segunda);
    }

    public function test_a_assinatura_nao_registra_nada_nem_escreve_na_saida(): void
    {
        Log::spy();

        $nivel = ob_get_level();
        ob_start();

        try {
            [$conta, $certificado] = $this->escritorio('Escritório de Teste');
            $assinada = $this->assinar($conta, $certificado);
        } finally {
            $escrito = (string) ob_get_clean();
        }

        $this->assertSame($nivel, ob_get_level());
        $this->assertSame('', $escrito);
        $this->assertNotSame('', $assinada);

        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log'] as $nivelDeLog) {
            Log::shouldNotHaveReceived($nivelDeLog);
        }
    }

    /**
     * O documento do contratante é conferido contra a credencial que o nomeia.
     *
     * O nome da plataforma vem da linha de `SerproConnection`, e o número vinha
     * do parâmetro — duas fontes que ninguém ligava uma na outra. Um chamador
     * que passasse o CNPJ do escritório produzia um termo cujo `destinatario`
     * carregava **o número de uma empresa com a razão social de outra**, sem
     * exceção, sem log e sem que nada no documento parecesse errado. A linha da
     * credencial já guarda `contratante_numero` para isso, e é contra ele que o
     * termo se confirma.
     */
    public function test_o_documento_do_contratante_tem_de_bater_com_a_credencial_da_plataforma(): void
    {
        [$conta, $certificado] = $this->escritorio('Escritório de Teste');

        try {
            // O CNPJ do escritório, que é o número que **não** pode ser o do
            // contratante — e é o erro que só o cruzamento com a credencial
            // pega, porque os dois números são CNPJs válidos.
            $this->assinar($conta, $certificado, self::ESCRITORIO);
            $this->fail('Um termo cujo contratante é o escritório não deveria ser assinado.');
        } catch (SerproException $exception) {
            $this->assertSame(SerproFailure::NotSent, $exception->failure);
            $this->assertStringNotContainsString('Escritório de Teste', $exception->getMessage());
            $this->assertStringNotContainsString(self::ESCRITORIO, $exception->getMessage());
            $this->assertStringNotContainsString(self::PLATAFORMA, $exception->getMessage());
        }
    }

    public function test_sem_credencial_de_plataforma_o_termo_nao_e_assinado(): void
    {
        SerproConnection::query()->delete();

        [$conta, $certificado] = $this->escritorio('Escritório de Teste');

        try {
            $this->assinar($conta, $certificado);
            $this->fail('Um termo sem contratante nomeado não deveria ser assinado.');
        } catch (SerproException $exception) {
            $this->assertSame(SerproFailure::NotSent, $exception->failure);
            $this->assertStringNotContainsString('Escritório de Teste', $exception->getMessage());
            $this->assertStringNotContainsString((string) self::$pfx, $exception->getMessage());
        }
    }

    // ------------------------------------------------------------------ ajudas

    /**
     * @return array{0: Account, 1: AccountCertificate}
     */
    private function escritorio(string $nome): array
    {
        $conta = Account::factory()->create(['name' => $nome]);

        $certificado = AccountCertificate::factory()->for($conta)->create([
            'document' => self::ESCRITORIO,
            'certificate_encrypted' => Crypt::encryptString(base64_encode((string) self::$pfx)),
            'password_encrypted' => Crypt::encryptString(self::PASSWORD),
        ]);

        return [$conta, $certificado];
    }

    private function assinar(Account $conta, AccountCertificate $certificado, string $contratante = self::PLATAFORMA): string
    {
        return app(SerproTermSigner::class)->sign($conta, $certificado, $contratante);
    }

    /**
     * Os bytes que a rotina hasheou: o documento assinado, com a `Signature`
     * retirada — que é o que o transform `enveloped-signature` manda fazer — e
     * canonicalizado em c14n da REC 2001, como a `Reference` declara.
     */
    private function hasheado(string $assinada): string
    {
        $documento = $this->parse($assinada);
        $documento->documentElement?->removeChild($this->signature($documento));

        return (string) $documento->documentElement?->C14N(false, false);
    }

    private function canonicalTemplate(): string
    {
        $template = $this->parse(SerproTermSigner::template());

        return (string) $template->documentElement?->C14N(false, false);
    }

    /**
     * O corpo do PEM do certificado que está no `X509Certificate` do termo, de
     * volta a PEM — que é o que um validador faz para chegar na chave pública
     * que o próprio documento traz.
     */
    private function pemFrom(string $base64): string
    {
        return "-----BEGIN CERTIFICATE-----\n"
            .chunk_split(trim($base64), 64, "\n")
            .'-----END CERTIFICATE-----'."\n";
    }

    /**
     * A concatenação com prefixo de comprimento, escrita aqui de novo e não
     * chamando a da rotina: isto é a especificação da entrada do digest, e um
     * teste que usasse a implementação provaria apenas que a função é
     * idempotente.
     *
     * @param  list<string>  $partes
     */
    private function juncao(array $partes): string
    {
        $entrada = '';

        foreach ($partes as $parte) {
            $entrada .= strlen($parte).':'.$parte.';';
        }

        return $entrada;
    }

    private function parse(string $xml): DOMDocument
    {
        $documento = new DOMDocument;
        $documento->preserveWhiteSpace = true;
        $documento->formatOutput = false;

        $this->assertTrue($documento->loadXML($xml, LIBXML_NONET), 'O termo assinado deveria ser XML legível.');

        return $documento;
    }

    private function xpath(DOMDocument $documento): DOMXPath
    {
        $xpath = new DOMXPath($documento);
        $xpath->registerNamespace('ds', self::XMLDSIG);

        return $xpath;
    }

    private function signature(DOMDocument $documento): DOMElement
    {
        $nos = $this->xpath($documento)->query('//ds:Signature');

        $this->assertSame(1, $nos->length, 'O termo assinado deveria ter exatamente uma Signature.');

        $assinatura = $nos->item(0);
        $this->assertInstanceOf(DOMElement::class, $assinatura);

        return $assinatura;
    }

    private function child(DOMElement $pai, string $nome): DOMElement
    {
        $filho = $pai->getElementsByTagNameNS(self::XMLDSIG, $nome)->item(0);

        $this->assertInstanceOf(DOMElement::class, $filho, sprintf('Faltou `%s` na assinatura.', $nome));

        return $filho;
    }

    private function elemento(DOMDocument $documento, string $nome): DOMElement
    {
        $no = $this->xpath($documento)->query(sprintf('/*/dados/%s', $nome))->item(0);

        $this->assertInstanceOf(DOMElement::class, $no, sprintf('Faltou `%s` no termo.', $nome));

        return $no;
    }

    private static function pkcs12(): string
    {
        $config = file_exists('/etc/ssl/openssl.cnf') ? ['config' => '/etc/ssl/openssl.cnf'] : [];

        $chave = openssl_pkey_new(array_merge(
            ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA],
            $config,
        ));

        $csr = $chave === false
            ? false
            : openssl_csr_new(['CN' => 'Escritorio de Teste:'.self::ESCRITORIO], $chave, array_merge(['digest_alg' => 'sha256'], $config));

        $certificado = $csr === false
            ? false
            : openssl_csr_sign($csr, null, $chave, 1, array_merge(['digest_alg' => 'sha256'], $config));

        $conteudo = '';
        $exportado = $certificado !== false && openssl_pkcs12_export($certificado, $conteudo, $chave, self::PASSWORD);

        if (! $exportado || $conteudo === '') {
            throw new RuntimeException('Não foi possível gerar o e-CNPJ de teste.');
        }

        return $conteudo;
    }
}
