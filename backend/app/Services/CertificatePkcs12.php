<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Abrir um PKCS#12 e dizer o que há dentro dele. Só isso.
 *
 * A unidade existe porque essa é a **mesma** operação para o certificado do
 * cliente e para o e-CNPJ do escritório, e porque o mesmo silêncio do OpenSSL
 * acontece nas duas: `openssl_pkcs12_read` devolve `false` para senha errada,
 * para arquivo que não é PKCS#12 e para container legado com RC2, sem dizer
 * qual dos três foi. A diferença só está na fila de erro, que é por thread,
 * precisa ser limpa antes da leitura e precisa ser lida imediatamente depois da
 * falha. Ler isso num lugar só é o que impede os dois cofres de contarem
 * histórias diferentes sobre o mesmo arquivo.
 *
 * **A classificação da falha é decidida aqui e em mais lugar nenhum.** Senha
 * errada e arquivo ilegível saem pela chave `password`; o container legado sai
 * pela chave `certificate`, numa `LegacyPkcs12Ciphertext` — que é a única forma de
 * o chamador dizer de quem é o certificado sem reescrever a frase, que pertence a
 * esta unidade. O que a unidade **não** decide é o nome de ninguém: o cofre de
 * cliente nomeia o cliente, e o nome do escritório não entra em mensagem de erro
 * nenhuma.
 *
 * Vigência é dado de retorno, não veredito: `valid_from` e `valid_until` saem
 * daqui e quem chama decide o que fazer com um certificado vencido. Para o
 * cliente, um certificado vencido é histórico e não erro — recusar por vencimento
 * aqui mudaria um comportamento que o cofre já tinha. Para a plataforma, quem
 * assina é o documento do SERPRO, e quem julga o e-CNPJ vencido é quem vai
 * usá-lo.
 *
 * A unidade também não sabe onde o arquivo mora. Ela recebe bytes e devolve
 * bytes; quem escolhe o destino é o chamador — o cofre de cliente grava cifrado
 * no disco do container, e o cofre do Account do plano 02 vai gravar cifrado no
 * banco —, e nenhum dos dois descobre o caminho pela unidade compartilhada.
 *
 * Sobre a senha: o `finally` esvazia a referência da senha e descarta o que a
 * leitura parseou, e **não** faz mais do que isso. Atribuir não apaga memória, e
 * um comentário aqui dizendo o contrário seria pior do que a ausência dele.
 */
final class CertificatePkcs12
{
    /**
     * @return array{
     *     cert: string,
     *     pkey: string,
     *     subject: string,
     *     serial: string,
     *     valid_from: Carbon,
     *     valid_until: Carbon,
     *     sha256: string,
     * }
     *
     * `pkey` e `subject` não são opcionais e a unidade não devolve string vazia
     * para nenhum dos dois: a chave privada que falta recusa, e o subject que
     * falta vira `desconhecido`, que é uma palavra e não um vazio.
     *
     * @throws LegacyPkcs12Ciphertext quando o container usa RC2 legado. Ainda é
     *                                uma `ValidationException` de chave
     *                                `certificate`, e a distinção existe para o
     *                                chamador, não para o status HTTP.
     * @throws ValidationException na chave `password` quando a senha não abre
     *                             o arquivo ou quando ele não é PKCS#12, e
     *                             na chave `certificate` quando o certificado
     *                             não traz datas de vigência legíveis ou não
     *                             traz chave privada.
     */
    public function inspect(string $bytes, string $password): array
    {
        $this->flushOpenSslErrors();

        $parsed = [];

        try {
            if (! openssl_pkcs12_read($bytes, $parsed, $password) || ! isset($parsed['cert'])) {
                throw $this->rejection($bytes);
            }

            $metadata = openssl_x509_parse($parsed['cert']);

            if (! is_array($metadata) || ! isset($metadata['validFrom_time_t'], $metadata['validTo_time_t'])) {
                throw ValidationException::withMessages([
                    'certificate' => 'O certificado não contém metadados válidos.',
                ]);
            }

            // `pkey` é `string` no contrato, e uma string vazia cumpriria o tipo
            // sem ser chave nenhuma: quem assina receberia material de assinatura
            // vazio e a falha apareceria na assinatura, longe de quem subiu o
            // arquivo. A recusa é inalcançável com o `openssl_pkcs12_read` do
            // PHP — que só extrai `cert` junto com `pkey`, e devolve um container
            // só com certificado sem `cert`, o que já caiu na recusa de senha —, e
            // ela existe para o tipo declarado não poder ser cumprido com o vazio.
            if (! isset($parsed['pkey']) || $parsed['pkey'] === '') {
                throw ValidationException::withMessages([
                    'certificate' => 'O arquivo não traz a chave privada que deveria trazer.',
                ]);
            }

            return [
                'cert' => $parsed['cert'],
                'pkey' => $parsed['pkey'],
                'subject' => $this->subject($metadata),
                'serial' => $this->serial($metadata),
                'valid_from' => Carbon::createFromTimestamp((int) $metadata['validFrom_time_t']),
                'valid_until' => Carbon::createFromTimestamp((int) $metadata['validTo_time_t']),
                // O SHA-256 é dos bytes recebidos, não do que sobrou depois de
                // abrir: é o que o cofre guarda e o que precisa bater com o
                // arquivo que o cliente vai reenviar daqui a dois anos.
                'sha256' => hash('sha256', $bytes),
            ];
        } finally {
            $password = '';
            unset($password, $parsed);
        }
    }

    /**
     * A recusa, e a escolha entre as duas que o OpenSSL não distingue sozinho.
     *
     * A fila de erro é por thread, então a detecção só pode ler o que a leitura
     * que acabou de falhar deixou nela — e é por isso que a limpeza acontece no
     * começo de `inspect()` e não aqui, para que ninguém precise lembrar de
     * limpar antes de chamar.
     */
    private function rejection(string $bytes): ValidationException
    {
        if ($this->failedBecauseOfLegacyRc2($bytes)) {
            return LegacyPkcs12Ciphertext::becauseRc2();
        }

        // A mensagem não promete a causa: `openssl_pkcs12_read` não disse qual foi,
        // e a diferença entre "senha errada" e "isto não é um PKCS#12" é do
        // formulário, não do arquivo.
        return ValidationException::withMessages([
            'password' => 'Não foi possível abrir o certificado com a senha informada.',
        ]);
    }

    /**
     * O `name` do `openssl_x509_parse` é o subject em uma linha só, e vem
     * preenchido em todo certificado que tem subject — o que inclui o e-CNPJ real.
     * O ramo do meio, que remonta o subject a partir do array, é para a leitura
     * que não trouxer o `name`; o `X509_NAME_oneline` só devolve string vazia
     * quando não há DN, e nesse caso o array também vem vazio, o que leva ao
     * último ramo. Dizer `desconhecido` é melhor do que gravar meia verdade na
     * coluna de histórico: o arquivo é legível, o subject é que não se sabe.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function subject(array $metadata): string
    {
        if (is_string($metadata['name'] ?? null) && $metadata['name'] !== '') {
            return $metadata['name'];
        }

        $subject = $metadata['subject'] ?? null;

        if (is_array($subject)) {
            $parts = [];

            foreach ($subject as $key => $value) {
                $parts[] = is_array($value) ? "{$key}=".implode(',', $value) : "{$key}={$value}";
            }

            if ($parts !== []) {
                return '/'.implode('/', $parts);
            }
        }

        return 'desconhecido';
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function serial(array $metadata): string
    {
        foreach (['serialNumber', 'serialNumberHex'] as $key) {
            if (is_string($metadata[$key] ?? null) && $metadata[$key] !== '') {
                return $metadata[$key];
            }
        }

        return 'desconhecido';
    }

    /**
     * Esvazia a fila de erro do OpenSSL antes de ler o PFX.
     *
     * A fila é por thread, e por isso quem contamina esta leitura não é o que
     * outra thread fez: é o que o **mesmo** thread deixou para trás — a leitura
     * de um arquivo recusado antes nesta requisição, o `setUpBeforeClass` de um
     * teste, a rotina que roda acima nesta chamada. A detecção de RC2 lê o que
     * estiver na fila depois da falha, e um erro antigo lido como se fosse deste
     * arquivo troca uma recusa de senha por uma de RC2.
     */
    private function flushOpenSslErrors(): void
    {
        while (openssl_error_string() !== false) {
            // Esvazia a fila.
        }
    }

    private function failedBecauseOfLegacyRc2(string $bytes): bool
    {
        $unsupportedAlgorithm = false;

        while (($error = openssl_error_string()) !== false) {
            $normalized = strtolower($error);

            if (str_contains($normalized, 'rc2')) {
                return true;
            }

            if (str_contains($normalized, 'unsupported')) {
                $unsupportedAlgorithm = true;
            }
        }

        return $unsupportedAlgorithm && $this->containsLegacyRc2Identifier($bytes);
    }

    private function containsLegacyRc2Identifier(string $bytes): bool
    {
        foreach ([
            hex2bin('060a2a864886f70d010c0105'),
            hex2bin('060a2a864886f70d010c0106'),
            hex2bin('06082a864886f70d0302'),
        ] as $identifier) {
            if ($identifier !== false && str_contains($bytes, $identifier)) {
                return true;
            }
        }

        return false;
    }
}
