<?php

namespace App\Support;

use App\Enums\SerproFailure;
use App\Services\SerproException;
use DOMDocument;
use DOMElement;
use libxml;

/**
 * A rotina de assinatura do termo, isolada do componente de referência do SERPRO.
 *
 * O termo de autorização é documento jurídico e a assinatura é superfície de
 * correção, não de formatação: um XMLDSig enveloped sutilmente errado só falha
 * no provedor, com um código opaco. Por isso a sequência veio do componente que
 * o próprio provedor publica como referência do formato que o validador dele
 * espera — e por isso ela está aqui **isolada**, nunca executada como script.
 *
 * **Proveniência, conferida no dia da implementação.** Fonte: modelo de
 * assinador digital em PHP publicado pelo SERPRO
 * ({@see self::SOURCE_URL}), distribuído como ZIP v1.0.0
 * ({@see self::SOURCE_VERSION}) em `Serpro.Componentes.AssinadorDigital.php.zip`,
 * SHA-256 do pacote {@see self::SOURCE_SHA256} e licença
 * {@see self::SOURCE_LICENSE} (`LICENSE` dentro do próprio ZIP, `Copyright (c)
 * 2022 SERPRO`). O próprio README do componente declara que ele é baseado no
 * projeto `XMLDSIG for PHP` (<https://github.com/selective-php/xmldsig>). O SHA
 * é do ZIP inteiro, não do script: o ZIP é o artefato que a documentação linka, e
 * é ele que carrega a licença.
 *
 * **O que foi isolado e o que foi reescrito.** Só a sequência XMLDSig de
 * `assinar()` foi portada, e ela é idêntica: canonicalização `C14N`, digest
 * SHA-256, `SignedInfo`, `Reference URI=""` com transform
 * `enveloped-signature` seguida de `c14n`, `SignatureMethod` RSA-SHA256 e
 * `KeyInfo/X509Data/X509Certificate`. O resto foi deliberadamente deixado de
 * fora, porque é o que impediria esta classe de existir:
 *
 * - as seis funções globais e as treze leituras de `$GLOBALS` — nada aqui é
 *   global, e a entrada vem por parâmetro;
 * - `date_default_timezone_set('America/Sao_Paulo')`, que vinha no topo do
 *   arquivo e mudava o fuso do processo inteiro. A rotina é agnóstica a fuso: as
 *   datas do documento são do `SerproTermSigner`, em `America/Sao_Paulo`;
 * - `carregarCertificados()`, que lia o PFX de um caminho com
 *   `file_get_contents`. Aqui os bytes chegam prontos e são abertos com
 *   `openssl_pkcs12_read`, então a assinatura **nunca toca o disco**;
 * - os dois `echo '<textarea>…'` do corpo do script, que despejavam o
 *   documento assinado e o mesmo documento em base64 na saída padrão.
 *
 * **Correções que não são estilo.** O modelo não confere o retorno de
 * `loadXML()` nem o de `openssl_pkcs12_read()`, e lança `XmlSignerException` —
 * classe que o ZIP **nunca declara**. A rotina confere as duas leituras e usa
 * `SerproException`, que existe e sabe dizer que nada foi enviado.
 *
 * **O que não foi vendorizado, e por quê.** O construtor do documento do termo
 * (`montarTermoAutorizacao()`) não veio. Ele é do `SerproTermSigner`, e traz
 * três coisas que parecem erro. **Uma é defeito e é corrigida; duas são
 * preservadas, por decisão.**
 *
 * O `addChild('finalidade ')` é defeito, da mesma família do parêntese a mais
 * da linha 50, e é corrigido: **o espaço no nome não é preservado porque não
 * existe**, não porque tenha sido julgado pouco importante.
 * `DOMDocument::createElement('finalidade ')` lança `DOMException: Invalid
 * Character Error`; o caminho do próprio modelo,
 * `SimpleXMLElement::addChild('finalidade ')`, não lança, mas emite
 * `<finalidade  texto="…"/>` — e o `nodeName` desse nó é `finalidade`, sem
 * espaço, porque o parser consome o espaço como espaço entre tags. Pior, o
 * `loadXML`/`saveXML` que esta própria rotina faz **apaga** o espaço: um termo
 * assinado carregaria `<finalidade texto="…"/>`. Um nome com espaço também é
 * inalcançável por XPath, onde `local-name()='finalidade '` devolve zero nós.
 * Não há como preservar a fidelidade ao modelo aqui, e quem um dia tentar
 * "restaurar" o espaço vai obter um `DOMException` ou um termo que nunca teve
 * espaço. O **nome** `finalidade` é o que fica, e é ele que permanece não
 * verificado e sob o gate.
 *
 * A vigência e a canonicalização são preservadas, e a razão é a mesma para as
 * duas: a documentação do termo do provedor responde `500` e não publica XSD,
 * então o modelo é a única autoridade e "arrumar" seria adivinhar o schema que
 * o provedor valida. Com uma ressalva que importa para quem for implementar: o
 * que se preserva da vigência é o **período de 30 dias**, que é o único valor
 * que existe. A chamada `date()` em volta dele não se preserva, porque não roda
 * — passa string onde vai timestamp e um terceiro argumento a uma função de
 * dois, e é a mesma linha do erro de parse. O `SerproTermSigner` escreve o
 * período como cálculo de `Carbon` em `America/Sao_Paulo`, reproduzindo a
 * intenção do modelo e não a sintaxe.
 *
 * **A decisão de canonicalização, e o que dela se provou.** O modelo calcula o
 * digest com `C14N` **exclusiva** e declara na `Reference` a `c14n` **inclusiva**
 * da REC 2001. A rotina mantém a exclusiva **por decisão, não por descuido**:
 * corrigir mudaria os bytes de `DigestValue` em relação ao exemplo oficial. A
 * decisão está registrada em `design.md` (D2) e na spec, com
 * o gate: **nenhum termo pode ser emitido antes de um teste de contrato provar
 * que o provedor aceita o documento.**
 *
 * O que o teste de proveniência demonstra é a coincidência das duas
 * canonicalizações para o formato de termo que ele exercita, porque ele refaz o
 * digest do jeito que um validador confere (tira a `Signature`, canonicaliza em
 * inclusiva) e o valor bate com o que a rotina gravou. **O gatilho real da
 * divergência é mais largo do que "declarar prefixo próprio":** exclusiva e
 * inclusiva divergem assim que o documento carrega **qualquer** declaração de
 * namespace, usada ou não, porque a exclusiva renderiza a declaração no elemento
 * que a usa e a inclusiva renderiza onde ela foi declarada — em
 * `<termoDeAutorizacao xmlns:ns1="urn:x"><ns1:dados/></termoDeAutorizacao>` as
 * duas produzem bytes diferentes. A garantia, portanto, é: **o elemento raiz do
 * termo não declara namespace algum, e o termo não é aninhado em um elemento que
 * declare.** O documento real ainda não existe: quando o `SerproTermSigner`
 * montar o seu, é esse teste que precisa passar a exercitá-lo. O que continua
 * **não** provado é a interoperabilidade com o validador do provedor, que é do
 * contrato real.
 *
 * **Sigilo.** A classe não registra nada: nem o documento assinado, nem o
 * certificado, nem a senha. Ela também não tenta apagar a senha da memória,
 * porque atribuir uma string em PHP não apaga a string anterior, e fingir que
 * apaga é pior do que não dizer nada — a mesma posição de
 * `SerproCertificateIdentity`. O que ela garante é o que dá para garantir: sem
 * disco, sem log, sem `echo`, sem global, sem `require` do arquivo de origem.
 *
 * MIT License
 *
 * Copyright (c) 2022 SERPRO
 *
 * A sequência XMLDSig abaixo é derivada de `Serpro.Componentes.AssinadorDigital.php`,
 * distribuída pelo SERPRO sob licença MIT e baseada no projeto `XMLDSIG for PHP`.
 * A licença exige que o aviso de copyright acompanhe cópias ou partes
 * substanciais, e é por isso que ele está aqui.
 */
final class SerproSigner
{
    /** Página do provedor que publica o modelo de referência. */
    public const SOURCE_URL = 'https://apicenter.estaleiro.serpro.gov.br/documentacao/api-integra-contador/pt/modelos/modelo_de_assinador_digital_php/';

    /** Versão declarada no `README.md` dentro do ZIP. */
    public const SOURCE_VERSION = '1.0.0';

    /** SHA-256 do ZIP distribuído, conferido em 2026-09-28 antes de integrar. */
    public const SOURCE_SHA256 = '6e139b207527047e9e66e9228c7c1ea6ea444b1b1a936f5f02f4d936a9e0c72b';

    /** Licença declarada no `LICENSE` dentro do ZIP. */
    public const SOURCE_LICENSE = 'MIT';

    private const XMLDSIG = 'http://www.w3.org/2000/09/xmldsig#';

    private const CANONICALIZATION = 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315';

    private const ENVELOPED_SIGNATURE = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';

    private const SIGNATURE_METHOD = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';

    private const DIGEST_METHOD = 'http://www.w3.org/2001/04/xmlenc#sha256';

    /**
     * Assina um documento XML sem assinatura e devolve o mesmo documento assinado.
     *
     * A entrada é o termo já montado e já normalizado pelo `SerproTermSigner`;
     * aqui não há normalização, porque normalizar depois de calcular o digest
     * invalida a assinatura, e normalizar antes é trabalho de quem monta o
     * documento, com o porquê escrito no lugar onde ele acontece.
     *
     * @param  string  $xml  documento sem assinatura, em UTF-8
     * @param  string  $certificateBytes  PKCS#12 do e-CNPJ do escritório
     * @param  string  $password  senha do PKCS#12
     *
     * @throws SerproException quando o documento não é XML, o PKCS#12 não abre
     *                         ou a assinatura não pôde ser calculada. Nenhuma das
     *                         mensagens carrega o documento, o certificado ou a
     *                         senha.
     */
    public function sign(string $xml, string $certificateBytes, string $password): string
    {
        $document = $this->load($xml);
        $parsed = $this->readCertificate($certificateBytes, $password);

        // O digest é calculado **antes** de a `Signature` existir. É o transform
        // `enveloped-signature` que diz ao validador que ele precisa tirar a
        // `Signature` do documento para refazer a conta — e um digest tirado
        // depois de a `Signature` estar no documento seria a conta de um
        // documento que o validador nunca vai canonicalizar. O modelo oficial
        // calcula nesta ordem, e por isso está nesta ordem aqui.
        $digestValue = $this->digest($document);

        $signature = $document->documentElement->appendChild($document->createElement('Signature'));
        $signature->setAttribute('xmlns', self::XMLDSIG);

        $signedInfo = $this->signedInfo($document, $digestValue);
        $signature->appendChild($signedInfo);

        $signatureValue = $this->signatureValue($document);
        $signature->appendChild($signatureValue);

        $signature->appendChild($this->keyInfo($document, $parsed['cert']));

        // A assinatura é sobre o `SignedInfo` já completo — é por isso que
        // `SignatureValue` entra vazio na árvore e só recebe o valor aqui, e é
        // por isso que a referência direta ao elemento substitui a busca por
        // `XPath` que o modelo fazia para chegar no mesmo nó.
        $canonicalSignedInfo = $signedInfo->C14N(true, false);

        if ($canonicalSignedInfo === false) {
            throw $this->failure('Não foi possível canonicalizar o SignedInfo do termo.');
        }

        $signed = '';

        if (! openssl_sign($canonicalSignedInfo, $signed, $parsed['pkey'], OPENSSL_ALGO_SHA256)) {
            throw $this->failure('Não foi possível calcular a assinatura do termo.');
        }

        $signatureValue->nodeValue = base64_encode($signed);

        $signedXml = $document->saveXML();

        if ($signedXml === false) {
            throw $this->failure('Não foi possível serializar o termo assinado.');
        }

        return $signedXml;
    }

    /**
     * Carrega o termo e deixa o documento pronto para receber a `Signature`.
     *
     * `preserveWhiteSpace` e `formatOutput` reproduzem as duas linhas que o
     * modelo deixa explícitas: o espaço em branco do documento assinado é o
     * documento assinado, e um `saveXML` reformatado mudaria a assinatura sem
     * mudar o termo.
     */
    private function load(string $xml): DOMDocument
    {
        $document = new DOMDocument;
        $document->preserveWhiteSpace = true;
        $document->formatOutput = false;

        // `LIBXML_NONET` porque o termo é um documento assinado: nada nele
        // autoriza o parser a buscar uma entidade externa.
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($loaded === false || ! $document->documentElement instanceof DOMElement) {
            // A mensagem é fixa e não nomeia o XML: o erro do libxml carrega
            // trecho do documento, e um termo assinado não é o que vai para o
            // log quando a montagem falha.
            throw $this->failure('O termo não é um documento XML legível.');
        }

        return $document;
    }

    private function digest(DOMDocument $document): string
    {
        $canonical = $document->documentElement->C14N(true, false);
        $digest = $canonical === false ? false : openssl_digest($canonical, 'sha256', true);

        if ($digest === false) {
            throw $this->failure('Não foi possível calcular o digest do termo.');
        }

        return base64_encode($digest);
    }

    private function signedInfo(DOMDocument $document, string $digestValue): DOMElement
    {
        $signedInfo = $document->createElement('SignedInfo');

        $canonicalization = $document->createElement('CanonicalizationMethod');
        $canonicalization->setAttribute('Algorithm', self::CANONICALIZATION);
        $signedInfo->appendChild($canonicalization);

        $method = $document->createElement('SignatureMethod');
        $method->setAttribute('Algorithm', self::SIGNATURE_METHOD);
        $signedInfo->appendChild($method);

        $reference = $document->createElement('Reference');
        // `URI=""` é o documento inteiro, não um fragmento: o termo é um
        // documento só, e é o transform `enveloped-signature` que diz como
        // deixar a `Signature` de fora da conta.
        $reference->setAttribute('URI', '');
        $signedInfo->appendChild($reference);

        $transforms = $document->createElement('Transforms');
        $enveloped = $document->createElement('Transform');
        $enveloped->setAttribute('Algorithm', self::ENVELOPED_SIGNATURE);
        $transforms->appendChild($enveloped);
        $canonical = $document->createElement('Transform');
        $canonical->setAttribute('Algorithm', self::CANONICALIZATION);
        $transforms->appendChild($canonical);
        $reference->appendChild($transforms);

        $digestMethod = $document->createElement('DigestMethod');
        $digestMethod->setAttribute('Algorithm', self::DIGEST_METHOD);
        $reference->appendChild($digestMethod);

        $digest = $document->createElement('DigestValue', $digestValue);
        $reference->appendChild($digest);

        return $signedInfo;
    }

    private function signatureValue(DOMDocument $document): DOMElement
    {
        // O valor entra vazio e é preenchido depois que o `SignedInfo` está
        // completo, porque é o `SignedInfo` que se assina.
        return $document->createElement('SignatureValue', '');
    }

    private function keyInfo(DOMDocument $document, string $certificate): DOMElement
    {
        $keyInfo = $document->createElement('KeyInfo');
        $data = $document->createElement('X509Data');
        $data->appendChild($document->createElement('X509Certificate', $this->body($certificate)));
        $keyInfo->appendChild($data);

        return $keyInfo;
    }

    /**
     * O corpo do PEM sem as linhas `-----BEGIN/END CERTIFICATE-----` e sem
     * quebras, que é o que o `X509Certificate` do XMLDSig transporta.
     */
    private function body(string $certificate): string
    {
        $stripped = str_replace(
            ['-----END CERTIFICATE-----', '-----BEGIN CERTIFICATE-----'],
            ' ',
            $certificate,
        );

        return preg_replace('/\r|\n/', '', trim($stripped)) ?? '';
    }

    /**
     * Abre o PKCS#12 dos bytes recebidos. O modelo lia o certificado de um
     * caminho e ignorava o retorno de `openssl_pkcs12_read()`, o que levava a
     * senha errada até um `openssl_sign()` com chave nula; aqui a leitura é
     * conferida e a falha é nomeada.
     *
     * @return array{cert: string, pkey: string}
     */
    private function readCertificate(string $certificateBytes, string $password): array
    {
        $parsed = [];

        if (! openssl_pkcs12_read($certificateBytes, $parsed, $password) || ! isset($parsed['cert'], $parsed['pkey'])) {
            // A senha é o segredo que o chamador está exercitando: ela não
            // aparece na mensagem, e o erro do OpenSSL também não entra.
            throw $this->failure('Não foi possível abrir o certificado do escritório com a senha informada.');
        }

        return ['cert' => $parsed['cert'], 'pkey' => $parsed['pkey']];
    }

    /**
     * Falha local, e nada foi enviado: é o que `NotSent` significa, e o que
     * assinatura é — a falha acontece antes de existir requisição, então
     * `Indeterminate` mentiria e `DoNotRetry` não diria quem tem o conserto.
     */
    private function failure(string $message): SerproException
    {
        return new SerproException($message, SerproFailure::NotSent, 0);
    }
}
