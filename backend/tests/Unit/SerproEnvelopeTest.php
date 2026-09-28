<?php

namespace Tests\Unit;

use App\Services\SerproEnvelope;
use PHPUnit\Framework\TestCase;

class SerproEnvelopeTest extends TestCase
{
    public function test_it_encodes_dados_as_a_json_string(): void
    {
        $envelope = (new SerproEnvelope)->build(
            '33683111000107',
            2,
            '33683111000875',
            '33683111000875',
            'REGIMEAPURACAO',
            'CONSULTARANOSCALENDARIOS102',
            '1.0',
            ['anoCalendario' => 2023],
        );

        $this->assertIsString($envelope['pedidoDados']['dados']);
        $this->assertSame(['anoCalendario' => 2023], json_decode($envelope['pedidoDados']['dados'], true));
    }

    public function test_an_empty_payload_becomes_an_empty_string(): void
    {
        $envelope = (new SerproEnvelope)->build(
            '33683111000107',
            2,
            '33683111000875',
            '33683111000875',
            'REGIMEAPURACAO',
            'CONSULTARANOSCALENDARIOS102',
            '1.0',
            [],
        );

        $this->assertSame('', $envelope['pedidoDados']['dados']);
    }

    public function test_it_carries_three_distinct_identities(): void
    {
        $envelope = (new SerproEnvelope)->build(
            '33683111000107',
            2,
            '33683111000875',
            '99999999999999',
            'PROCURACOES',
            'OBTERPROCURACAO41',
            '1',
            [],
        );

        $this->assertSame('33683111000107', $envelope['contratante']['numero']);
        $this->assertSame('33683111000875', $envelope['autorPedidoDados']['numero']);
        $this->assertSame('99999999999999', $envelope['contribuinte']['numero']);
        $this->assertSame('1', $envelope['pedidoDados']['versaoSistema']);
    }

    /**
     * A regra 11→1, 14→2 mora aqui e é a fonte única de três lugares: o
     * `autorPedidoDados.tipo`/`contribuinte.tipo` deste envelope, a coluna
     * `contratante_tipo` gravada pela plataforma e o `X-Request-Tag` de
     * `SerproRequestTag`. Nenhuma cópia local dela: documento e tipo discordando
     * é um `403` que não diz qual dos dois está errado.
     */
    public function test_deriva_o_tipo_do_documento_e_nao_o_declara(): void
    {
        $this->assertSame(1, SerproEnvelope::tipo('12345678901'));
        $this->assertSame(2, SerproEnvelope::tipo('33683111000107'));
        $this->assertSame(2, SerproEnvelope::tipo('12ABC345000188'));

        $envelope = (new SerproEnvelope)->build(
            '33683111000107',
            2,
            '12345678901',
            '99999999999999',
            'PROCURACOES',
            'OBTERPROCURACAO41',
            '1',
            [],
        );

        // O `contratante.tipo` vem pronto do manager, que o derivou do
        // certificado; os outros dois saem da regra, e é o que este teste segura.
        $this->assertSame(2, $envelope['contratante']['tipo']);
        $this->assertSame(1, $envelope['autorPedidoDados']['tipo']);
        $this->assertSame(2, $envelope['contribuinte']['tipo']);
    }

    public function test_it_decodes_dados_twice(): void
    {
        $result = (new SerproEnvelope)->parse([
            'status' => 200,
            'responseId' => 'z45e2f31-03e6-417d-8f1a-7153954f2d5b',
            'dados' => '[{"anoCalendario":2023,"regimeApurado":"CAIXA"}]',
            'mensagens' => [
                ['codigo' => '[Sucesso-REGIME]', 'texto' => 'Requisição efetuada com sucesso.'],
            ],
        ]);

        $this->assertSame(200, $result['status']);
        $this->assertSame('z45e2f31-03e6-417d-8f1a-7153954f2d5b', $result['response_id']);
        $this->assertSame([['anoCalendario' => 2023, 'regimeApurado' => 'CAIXA']], $result['dados']);
        $this->assertSame('[Sucesso-REGIME]', $result['mensagens'][0]['codigo']);
    }

    public function test_it_tolerates_a_nulled_envelope(): void
    {
        $result = (new SerproEnvelope)->parse([
            'contratante' => null,
            'pedidoDados' => null,
            'status' => 400,
            'dados' => null,
            'mensagens' => [
                ['codigo' => 'ERRO', 'texto' => 'Dados inválidos.'],
            ],
        ]);

        $this->assertSame(400, $result['status']);
        $this->assertNull($result['dados']);
        $this->assertNull($result['response_id']);
    }
}
