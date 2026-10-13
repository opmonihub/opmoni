<?php

namespace Tests\Concerns;

use RuntimeException;

/**
 * Um PKCS#12 descartável, gerado na hora, para os testes que precisam de um A1
 * que abre e assina. Nenhum PFX é versionado — `*.pfx` e `*.p12` estão no
 * `.gitignore` da raiz —, e o `openssl.cnf` é escrito aqui para o certificado
 * não depender dos defaults do host (ver `CertificatePkcs12Test::opensslConfig()`
 * para o porquê).
 *
 * A senha é fixa e de teste; o par de chaves é RSA de 2048 bits porque é o que
 * o fisco aceita num A1 real e o que torna a assinatura RSA-SHA1 comparável à
 * de produção. A classe que usa o trait precisa chamar `cleanupThrowawayPkcs12()`
 * em `tearDownAfterClass()` para apagar o config temporário.
 */
trait BuildsThrowawayPkcs12
{
    private static ?string $throwawayOpensslConfig = null;

    /**
     * @return array{bytes: string, password: string, cert: string}
     */
    protected static function throwawayPkcs12(string $commonName = 'Cliente de Teste'): array
    {
        $password = 'senha-de-teste';
        $options = ['config' => self::throwawayOpensslConfig()];

        $key = openssl_pkey_new(array_merge(
            ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA],
            $options,
        ));

        if ($key === false) {
            throw new RuntimeException('O par de chaves de descarte não pôde ser gerado.');
        }

        $csr = openssl_csr_new(['CN' => $commonName], $key, array_merge(['digest_alg' => 'sha256'], $options));

        if ($csr === false) {
            throw new RuntimeException('O CSR de descarte não pôde ser gerado.');
        }

        $certificate = openssl_csr_sign($csr, null, $key, 30, array_merge(['digest_alg' => 'sha256'], $options));

        if ($certificate === false) {
            throw new RuntimeException('O certificado de descarte não pôde ser assinado.');
        }

        $bytes = '';

        if (! openssl_pkcs12_export($certificate, $bytes, $key, $password, $options)) {
            throw new RuntimeException('O PFX de descarte não pôde ser exportado.');
        }

        $pem = '';
        openssl_x509_export($certificate, $pem);

        return ['bytes' => $bytes, 'password' => $password, 'cert' => $pem];
    }

    protected static function cleanupThrowawayPkcs12(): void
    {
        if (self::$throwawayOpensslConfig !== null) {
            @unlink(self::$throwawayOpensslConfig);
            @rmdir(dirname(self::$throwawayOpensslConfig));
            self::$throwawayOpensslConfig = null;
        }
    }

    private static function throwawayOpensslConfig(): string
    {
        if (self::$throwawayOpensslConfig !== null) {
            return self::$throwawayOpensslConfig;
        }

        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pkcs12-descarte-'.bin2hex(random_bytes(6));

        if (! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Não foi possível criar o diretório temporário em %s', sys_get_temp_dir()));
        }

        $path = $directory.DIRECTORY_SEPARATOR.'openssl.cnf';
        file_put_contents($path, <<<'OPENSSL'
            [ req ]
            distinguished_name = dn
            prompt = no

            [ dn ]
            CN = substituido pelo argumento
            countryName_default = BR
            0.organizationName_default = ICP-Brasil

            OPENSSL);

        return self::$throwawayOpensslConfig = $path;
    }
}
