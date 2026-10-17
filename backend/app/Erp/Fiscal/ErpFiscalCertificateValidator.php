<?php

namespace App\Erp\Fiscal;

use InvalidArgumentException;

final class ErpFiscalCertificateValidator
{
    /**
     * @return array{valid_from: ?string, valid_until: ?string, cnpj: ?string}
     */
    public function validatePkcs12(string $rawCertificate, string $password, ?string $expectedCnpj = null): array
    {
        if (filter_var(config('erp.fiscal_certificate_relaxed_validation', false), FILTER_VALIDATE_BOOL)) {
            return [
                'valid_from' => null,
                'valid_until' => null,
                'cnpj' => $expectedCnpj,
            ];
        }

        $certs = [];
        if (! openssl_pkcs12_read($rawCertificate, $certs, $password)) {
            throw new InvalidArgumentException('Certificado A1 ou senha inválidos.');
        }

        $parsed = openssl_x509_parse($certs['cert'] ?? '');
        if ($parsed === false) {
            throw new InvalidArgumentException('Certificado A1 ilegível.');
        }

        $subject = is_array($parsed['subject'] ?? null) ? $parsed['subject'] : [];
        $cnpj = null;
        foreach (['CN', 'commonName'] as $key) {
            if (! isset($subject[$key])) {
                continue;
            }
            $digits = preg_replace('/\D+/', '', (string) $subject[$key]) ?? '';
            if (strlen($digits) === 14) {
                $cnpj = $digits;
                break;
            }
        }

        if ($expectedCnpj !== null && $cnpj !== null) {
            $expected = preg_replace('/\D+/', '', $expectedCnpj) ?? '';
            if ($expected !== '' && $cnpj !== $expected) {
                throw new InvalidArgumentException('Certificado incompatível com o emissor selecionado.');
            }
        }

        return [
            'valid_from' => isset($parsed['validFrom_time_t']) ? date('c', (int) $parsed['validFrom_time_t']) : null,
            'valid_until' => isset($parsed['validTo_time_t']) ? date('c', (int) $parsed['validTo_time_t']) : null,
            'cnpj' => $cnpj,
        ];
    }
}
