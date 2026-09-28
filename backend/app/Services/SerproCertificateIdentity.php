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
 * A leitura é feita sobre os bytes recebidos, sem gravar nem regerar o PFX.
 */
final class SerproCertificateIdentity
{
    public function __construct(private BrazilianTaxId $taxId) {}

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
        $parsed = [];

        try {
            $this->flushOpenSslErrors();

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
        } finally {
            $password = '';
            $parsed = [];
            unset($password, $parsed);
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function contractingDocument(array $metadata): string
    {
        $subject = is_array($metadata['subject'] ?? null) ? $metadata['subject'] : [];
        $documents = [];
        $malformed = false;

        foreach ($this->candidates($subject) as $candidate) {
            $document = $this->taxId->normalize($candidate);

            if ($this->taxId->isValidCnpj($document)) {
                $documents[$document] = true;

                continue;
            }

            $malformed = $malformed || strlen($document) === 14;
        }

        if ($documents === []) {
            throw ValidationException::withMessages([
                'certificate' => $malformed
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
        while (openssl_error_string() !== false) {
            // OpenSSL keeps a per-thread error queue; clear stale failures before reading this PFX.
        }
    }
}
