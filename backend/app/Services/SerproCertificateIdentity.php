<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * A identidade do contratante vem do certificado, nunca do formulário.
 *
 * O provedor exige que o `contratante` do envelope seja igual ao CNPJ gravado
 * no certificado da plataforma, e um `contratante_numero` digitado à mão que
 * não bate com o certificado só se revela como um `403` do gateway — que, para
 * uma credencial compartilhada, é indistinguível de senha errada. Por isso o
 * documento é extraído no momento do upload, recusado quando não é único ou o
 * certificado não está vigente, e só depois gravado na coluna.
 *
 * O CNPJ aparece em dois lugares do certificado de e-CNPJ emitido no padrão
 * ICP-Brasil: o `serialNumber` do subject e o `CN`, que traz a razão social
 * separada do documento por `:`. Os dois são lidos e precisam concordar.
 *
 * A leitura é feita sobre os bytes recebidos, sem gravar nem regerar o PFX. O
 * serviço não guarda estado: nada além dos campos do contrato sai daqui, e a
 * vigência dos bytes é do escopo de quem os tem em mãos — zerar uma cópia
 * local da senha não apaga nada da memória, então nem fingimos que apaga.
 *
 * **São dois caminhos de entrada e uma validação só.** `document()` recebe os
 * bytes crus e abre o container; `documentFromCertificate()` recebe o
 * certificado que quem já abriu o container tem em mãos. A extração do CNPJ é a
 * mesma nos dois — `contractingDocument()`, com o `BrazilianTaxId` conferindo o
 * dígito —, porque um segundo validador de CNPJ aqui seria justamente o defeito
 * que a existência desta classe evita. O segundo caminho não é uma atalho
 * mais permissivo: ele confere a mesma vigência e recusa com as mesmas três
 * causas.
 */
final class SerproCertificateIdentity
{
    public function __construct(private BrazilianTaxId $taxId) {}

    /**
     * O documento a partir de um certificado **já aberto**.
     *
     * É o mesmo caminho de leitura com um degrau a menos, e ele existe por um
     * motivo concreto: quem já tem o PKCS#12 aberto não deveria abri-lo de novo
     * só para chegar ao mesmo `openssl_x509_parse()`. O `AccountCertificateVault`
     * recebia o certificado de `CertificatePkcs12::inspect()` — que já o tinha
     * lido e já o tinha classificado, inclusive o container legado — e o
     * descartava para reabrir os mesmos bytes, de até 2 MiB, por upload.
     *
     * **A validação é a mesma, e é aqui que ela mora.** O que só esta classe
     * sabe fazer é conferir o CNPJ com o `BrazilianTaxId` — inclusive o dígito
     * alfanumérico da RFB IN 2.119/2022 —, e um segundo validador de CNPJ é
     * exatamente o defeito que a existência desta classe evita. Por isso este
     * método não extrai nada: ele entrega os metadados do certificado ao mesmo
     * `contractingDocument()` que o caminho dos bytes usa.
     *
     * A vigência é conferida aqui tanto quanto no caminho dos bytes, para que o
     * método novo não seja um contrato mais frouxo que o antigo. O cofre do
     * escritório recusa antes, com a frase dele — as duas trancas continuam, e a
     * ordem entre elas não muda.
     *
     * @param  string  $certificate  o certificado X.509 em PEM, o `cert` que
     *                               `openssl_pkcs12_read` devolve
     *
     * @throws ValidationException quando o texto não é um certificado legível,
     *                             ele está vencido ou não carrega um CNPJ único.
     */
    public function documentFromCertificate(string $certificate): string
    {
        $this->flushOpenSslErrors();

        $metadata = openssl_x509_parse($certificate);

        if (! is_array($metadata) || ! isset($metadata['validFrom_time_t'], $metadata['validTo_time_t'])) {
            throw ValidationException::withMessages([
                'certificate' => 'O certificado do contratante não contém metadados válidos.',
            ]);
        }

        if (Carbon::createFromTimestamp((int) $metadata['validTo_time_t'])->isPast()) {
            throw ValidationException::withMessages([
                'certificate' => 'O certificado do contratante está vencido.',
            ]);
        }

        return $this->contractingDocument($metadata);
    }

    /**
     * @throws ValidationException quando o PFX não abre, o certificado está
     *                             vencido ou não carrega um CNPJ único.
     */
    public function document(string $bytes, string $password): string
    {
        return $this->parse($bytes, $password)['document'];
    }

    /**
     * @return array{
     *     document: string,
     *     subject: string,
     *     serial: string,
     *     valid_from: Carbon,
     *     valid_until: Carbon,
     * }
     *
     * @throws ValidationException
     */
    public function parse(string $bytes, string $password): array
    {
        $this->flushOpenSslErrors();

        $parsed = [];

        if (! openssl_pkcs12_read($bytes, $parsed, $password) || ! isset($parsed['cert'])) {
            throw ValidationException::withMessages([
                'password' => 'Não foi possível abrir o certificado do contratante com a senha informada.',
            ]);
        }

        $metadata = openssl_x509_parse($parsed['cert']);

        if (! is_array($metadata) || ! isset($metadata['validFrom_time_t'], $metadata['validTo_time_t'])) {
            throw ValidationException::withMessages([
                'certificate' => 'O certificado do contratante não contém metadados válidos.',
            ]);
        }

        $validFrom = Carbon::createFromTimestamp((int) $metadata['validFrom_time_t']);
        $validUntil = Carbon::createFromTimestamp((int) $metadata['validTo_time_t']);

        if ($validUntil->isPast()) {
            throw ValidationException::withMessages([
                'certificate' => 'O certificado do contratante está vencido.',
            ]);
        }

        return [
            'document' => $this->contractingDocument($metadata),
            'subject' => is_string($metadata['name'] ?? null) ? $metadata['name'] : 'desconhecido',
            'serial' => $this->serial($metadata),
            'valid_from' => $validFrom,
            'valid_until' => $validUntil,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function contractingDocument(array $metadata): string
    {
        $subject = is_array($metadata['subject'] ?? null) ? $metadata['subject'] : [];
        $documents = [];
        $pareceDocumento = false;

        foreach ($this->candidates($subject) as $candidate) {
            $document = $this->taxId->normalize($candidate);

            if ($this->taxId->isValidCnpj($document)) {
                $documents[$document] = true;

                continue;
            }

            $pareceDocumento = $pareceDocumento || $this->looksLikeDocument($candidate, $document);
        }

        if ($documents === []) {
            throw ValidationException::withMessages([
                'certificate' => $pareceDocumento
                    ? 'O certificado do contratante informa um CNPJ inválido.'
                    : 'O certificado do contratante não informa o CNPJ da empresa.',
            ]);
        }

        if (count($documents) > 1) {
            throw ValidationException::withMessages([
                'certificate' => 'O certificado do contratante informa mais de um CNPJ, e não é possível saber qual é o contratante.',
            ]);
        }

        return (string) array_key_first($documents);
    }

    /**
     * Distingue "o certificado traz um documento que não fecha" de "o
     * certificado não traz documento nenhum" — a diferença é o que o operador
     * precisa para saber se o erro é do certificado ou do preenchimento.
     *
     * O comprimento sozinho não diz nada: um segmento de CN como
     * `AGROINDUSTRIAL` tem catorze caracteres depois de normalizado e não é
     * documento nenhum. Documento tem dígitos — no CNPJ clássico todos, e
     * sempre ao menos os dois do dígito verificador no alfanumérico — e
     * catorze posições depois de perder pontuação e espaço.
     */
    private function looksLikeDocument(string $candidate, string $normalized): bool
    {
        return strlen($normalized) === 14
            && preg_match('/\d/', $candidate) === 1;
    }

    /**
     * As duas grafias do CNPJ no certificado, sem distinguí-las: um certificado
     * que traz o mesmo documento nos dois lugares continua sendo um documento.
     *
     * @param  array<string, mixed>  $subject
     * @return list<string>
     */
    private function candidates(array $subject): array
    {
        $candidates = [];

        if (is_string($subject['serialNumber'] ?? null)) {
            $candidates[] = $subject['serialNumber'];
        }

        $commonName = $subject['CN'] ?? null;

        foreach (is_array($commonName) ? $commonName : [$commonName] as $value) {
            foreach (explode(':', (string) $value) as $part) {
                $candidates[] = $part;
            }
        }

        return $candidates;
    }

    /**
     * O número de série do próprio certificado — o `serialNumber` do *subject* é
     * o CNPJ, e esse já foi lido como identidade.
     *
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

    private function flushOpenSslErrors(): void
    {
        // A fila de erro do OpenSSL é por thread: uma falha antiga contaminaria
        // a leitura deste PFX, e é preciso limpá-la antes de abrir.
        while (openssl_error_string() !== false) {
            // Esvazia a fila.
        }
    }
}
