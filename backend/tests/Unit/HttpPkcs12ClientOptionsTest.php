<?php

namespace Tests\Unit;

use App\Support\HttpPkcs12ClientOptions;
use Tests\TestCase;

/**
 * O contrato das opções Guzzle para o par PKCS#12 do certificado A1.
 *
 * O Guzzle 8 rejeita opções cruas de cURL no sub-array `curl` — a allow-list
 * própria da 8.2 foi o que derrubou o `CaptureFiscalDocumentsJob` em
 * 2026-10-02 com "Passing 0 in the curl request option is not supported" —,
 * então o par caminho+senha vai nas opções de requisição `cert`/`cert_type`.
 * Este teste fixa a forma aceita e, de quebra, prova que nenhuma chave
 * `curl` volta para o transporte.
 */
class HttpPkcs12ClientOptionsTest extends TestCase
{
    public function test_o_par_pkcs12_vai_nas_opcoes_cert_e_nao_no_sub_array_curl(): void
    {
        $opcoes = HttpPkcs12ClientOptions::forPath('/tmp/certificado.p12', 'senha-do-pfx');

        $this->assertSame(
            ['cert' => ['/tmp/certificado.p12', 'senha-do-pfx'], 'cert_type' => 'P12'],
            $opcoes,
        );
        $this->assertArrayNotHasKey('curl', $opcoes, 'O Guzzle 8 recusa opções cruas de cURL no sub-array "curl".');
    }

    public function test_a_senha_ausente_vira_string_vazia_e_nao_nula(): void
    {
        $opcoes = HttpPkcs12ClientOptions::forPath('/tmp/certificado.p12', null);

        $this->assertSame('', $opcoes['cert'][1], 'A opção `cert` do Guzzle espera string, não null.');
    }
}
