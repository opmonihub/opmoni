<?php

namespace App\Erp\Fiscal;

use InvalidArgumentException;

final class ErpFiscalCertificateValidator
{
    /**
     * @return array{valid_from: ?string, valid_until: ?string, cnpj: ?string, subject?: ?string}
     */
    public function validatePkcs12(string $rawCertificate, string $password, ?string $expectedCnpj = null): array
    {
        if (app()->environment('testing') && filter_var(config('erp.fiscal_certificate_relaxed_validation', false), FILTER_VALIDATE_BOOL)) {
            return [
                'valid_from' => null,
                'valid_until' => null,
                'cnpj' => $expectedCnpj,
            ];
        }

        if ($expectedCnpj === null || ! $this->isValidNumericCnpj($expectedCnpj)) {
            throw new InvalidArgumentException('O CNPJ emissor do estabelecimento precisa ser válido antes do envio do certificado.');
        }

        $certs = [];
        if (! openssl_pkcs12_read($rawCertificate, $certs, $password)) {
            throw new InvalidArgumentException('Certificado A1 ou senha inválidos.');
        }

        $certificate = $certs['cert'] ?? null;
        $privateKey = $certs['pkey'] ?? null;
        if (! is_string($certificate)
            || (! is_string($privateKey) && ! ($privateKey instanceof \OpenSSLAsymmetricKey))
            || ! openssl_x509_check_private_key($certificate, $privateKey)) {
            throw new InvalidArgumentException('O certificado não contém uma chave privada correspondente.');
        }

        $parsed = openssl_x509_parse($certificate);
        if ($parsed === false || ! isset($parsed['validFrom_time_t'], $parsed['validTo_time_t'])) {
            throw new InvalidArgumentException('Certificado A1 ilegível.');
        }

        $subject = is_array($parsed['subject'] ?? null) ? $parsed['subject'] : [];
        $cnpj = $this->subjectCnpj($subject);
        if ($cnpj === null) {
            throw new InvalidArgumentException('Não foi possível identificar o CNPJ do certificado.');
        }
        if ($cnpj !== $expectedCnpj) {
            throw new InvalidArgumentException('Certificado incompatível com o emissor selecionado.');
        }

        $validFrom = (int) $parsed['validFrom_time_t'];
        $validUntil = (int) $parsed['validTo_time_t'];
        $now = time();
        if ($validFrom > $now || $validUntil <= $now) {
            throw new InvalidArgumentException('O certificado está fora do período de validade.');
        }

        return [
            'valid_from' => date(DATE_ATOM, $validFrom),
            'valid_until' => date(DATE_ATOM, $validUntil),
            'cnpj' => $cnpj,
            'subject' => $this->formatSubject($subject),
        ];
    }

    /** @param array<string, mixed> $subject */
    private function subjectCnpj(array $subject): ?string
    {
        foreach (['serialNumber', 'CN', 'commonName'] as $key) {
            $values = $subject[$key] ?? [];
            foreach (is_array($values) ? $values : [$values] as $value) {
                if (! is_string($value)) {
                    continue;
                }
                if (preg_match('/(?<!\d)(\d{14})(?!\d)/', $value, $match) === 1) {
                    return $match[1];
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed> $subject */
    private function formatSubject(array $subject): string
    {
        $parts = [];
        foreach ($subject as $key => $value) {
            foreach (is_array($value) ? $value : [$value] as $part) {
                if (is_scalar($part)) {
                    $parts[] = $key.'='.substr((string) $part, 0, 128);
                }
            }
        }

        return implode(',', $parts);
    }

    private function isValidNumericCnpj(string $cnpj): bool
    {
        if (! preg_match('/^\d{14}$/', $cnpj) || preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }

        $digits = array_map('intval', str_split($cnpj));
        foreach ([
            [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2],
            [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2],
        ] as $position => $weights) {
            $sum = 0;
            foreach ($weights as $index => $weight) {
                $sum += $digits[$index] * $weight;
            }
            $remainder = $sum % 11;
            $expectedDigit = $remainder < 2 ? 0 : 11 - $remainder;
            if ($digits[count($weights)] !== $expectedDigit) {
                return false;
            }
        }

        return true;
    }
}
