<?php

namespace Tests\Unit;

use App\Services\BrazilianTaxId;
use App\Services\SerproCertificateIdentity;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

/**
 * A identidade do contratante sabe ler uma coisa que o leitor de PKCS#12 não sabe:
 * o CNPJ de dentro do certificado, e se ele fecha. Este arquivo fixa o outro
 * caminho de entrada dessa mesma capacidade — o certificado **já aberto** — que
 * existe para o cofre do escritório não ter de reabrir um e-CNPJ de até 2 MiB
 * só para chegar ao mesmo `openssl_x509_parse()`.
 *
 * **Por que os dois caminhos existem.** O `SerproConnectionManager` recebe os
 * bytes crus de um upload e não tem leitor nenhum ao redor, então ele precisa
 * da leitura completa, com senha. O `AccountCertificateVault` já tem
 * `CertificatePkcs12::inspect()`, que abriu os mesmos bytes, devolveu o
 * certificado e que se recusa a abrir de novo. Os dois precisam da mesma
 * validação de CNPJ, e ela tem de ser a mesma implementação — um segundo
 * validador de CNPJ é exatamente o que a existência desta classe impede.
 *
 * Nenhum fixture de PKCS#12 é versionado: `*.pfx` e `*.p12` estão no
 * `.gitignore` da raiz, e a regra é do arquivo inteiro, não do caso.
 */
class SerproCertificateIdentityTest extends TestCase
{
    private const PASSWORD = 'senha-de-teste';

    /** O exemplo alfanumérico da RFB IN 2.119/2022, que é o que fecha. */
    private const CNPJ_ALFANUMERICO = '12ABC34501DE35';

    /** O CNPJ numérico clássico, usado no certificado de descarte. */
    private const CNPJ = '12345678000195';

    public function test_o_documento_de_um_certificado_ja_aberto_e_o_mesmo_que_o_dos_bytes(): void
    {
        $bytes = $this->pfx(self::CNPJ);

        // A afirmação que importa não é que o método existe, é que ele devolve
        // **o mesmo** documento que o caminho dos bytes devolve. Uma extração
        // que divergisse aqui gravaria na linha do escritório um `document` que
        // nenhuma das duas leituras concorda.
        $this->assertSame(
            $this->identity()->document($bytes, self::PASSWORD),
            $this->identity()->documentFromCertificate($this->certificateFrom($bytes)),
        );
    }

    public function test_certificado_ja_aberto_aceita_cnpj_alfanumerico(): void
    {
        // O CNPJ alfanumérico é a razão de a extração morar aqui: é o
        // `BrazilianTaxId` que confere o dígito com `ord($char) - 48`, e o
        // leitor de PKCS#12 não valida documento nenhum.
        $documento = $this->identity()->documentFromCertificate(
            $this->certificateFrom($this->pfx(self::CNPJ_ALFANUMERICO)),
        );

        $this->assertSame(self::CNPJ_ALFANUMERICO, $documento);
    }

    public function test_certificado_ja_aberto_com_cnpj_que_nao_fecha_e_recusado(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/CNPJ inválido/');

        // O dígito verificador é o último: '00' no lugar de '95' fecha, '96'
        // também — e um documento que não fecha tem de ser recusado pelo mesmo
        // caminho do que não é documento.
        $this->identity()->documentFromCertificate(
            $this->certificateFrom($this->pfx(substr(self::CNPJ, 0, -2).'00')),
        );
    }

    public function test_certificado_ja_aberto_com_mais_de_um_cnpj_e_recusado(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/mais de um CNPJ/');

        $this->identity()->documentFromCertificate(
            $this->certificateFrom($this->pfx(self::CNPJ, '27865757000102')),
        );
    }

    public function test_certificado_ja_aberto_vencido_e_recusado(): void
    {
        // A vigência é conferida nos dois caminhos, e não só no dos bytes: um
        // método novo que aceitasse certificado vencido seria um afrouxamento
        // silencioso de um contrato que o caminho antigo cumpre.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/está vencido/');

        $bytes = $this->pfx(self::CNPJ, null, 0);

        $this->identity()->documentFromCertificate($this->certificateFrom($bytes));
    }

    public function test_texto_que_nao_e_certificado_e_recusado(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/metadados válidos/');

        $this->identity()->documentFromCertificate('isto não é um certificado');
    }

    private function identity(): SerproCertificateIdentity
    {
        return new SerproCertificateIdentity(new BrazilianTaxId);
    }

    private function certificateFrom(string $bytes): string
    {
        $parsed = [];

        if (! openssl_pkcs12_read($bytes, $parsed, self::PASSWORD) || ! isset($parsed['cert'])) {
            throw new RuntimeException('O PKCS#12 de descarte não abriu.');
        }

        return $parsed['cert'];
    }

    /**
     * O certificado de descarte, com o `CN` no formato que o e-CNPJ da
     * ICP-Brasil traz e o `serialNumber` com o mesmo documento — os dois lugares
     * em que o documento aparece, e que precisam concordar.
     */
    private function pfx(string $document, ?string $outro = null, int $dias = 365): string
    {
        $assunto = [
            'CN' => 'Escritorio Contabil de Descarte:'.$document,
            'serialNumber' => $outro ?? $document,
        ];

        $config = file_exists('/etc/ssl/openssl.cnf') ? ['config' => '/etc/ssl/openssl.cnf'] : [];

        $chave = openssl_pkey_new(array_merge(
            ['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA],
            $config,
        ));

        if ($chave === false) {
            throw new RuntimeException('O par de chaves de descarte não pôde ser gerado.');
        }

        $csr = openssl_csr_new($assunto, $chave, array_merge(['digest_alg' => 'sha256'], $config));

        if ($csr === false) {
            throw new RuntimeException('O CSR de descarte não pôde ser gerado.');
        }

        // Validade de zero dia deixa `notAfter` no segundo corrente, que já torna
        // o certificado vencido na leitura.
        $certificado = openssl_csr_sign($csr, null, $chave, $dias, array_merge(['digest_alg' => 'sha256'], $config));

        $bytes = '';

        if ($certificado === false || ! openssl_pkcs12_export($certificado, $bytes, $chave, self::PASSWORD, $config)) {
            throw new RuntimeException('O PKCS#12 de descarte não pôde ser exportado.');
        }

        return $bytes;
    }
}
