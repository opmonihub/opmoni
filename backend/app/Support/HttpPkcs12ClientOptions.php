<?php

namespace App\Support;

/**
 * Opções Guzzle/Laravel HTTP para certificado cliente PKCS#12 (.p12/.pfx).
 *
 * O Guzzle 8 rejeita `CURLOPT_SSLCERT` e `CURLOPT_SSLCERTTYPE` no sub-array
 * `curl` — ambos conflitam com opções que o próprio Guzzle administra —, então
 * o par PKCS#12 vai nas opções de requisição `cert` (caminho + senha) e
 * `cert_type` (`P12`), que o handler cURL converte em `CURLOPT_SSLCERT*` dele.
 */
final class HttpPkcs12ClientOptions
{
    /**
     * @return array{cert: array{0: string, 1: string}, cert_type: string}
     */
    public static function forPath(string $certificatePath, ?string $password): array
    {
        return [
            'cert' => [$certificatePath, $password ?? ''],
            'cert_type' => 'P12',
        ];
    }
}
