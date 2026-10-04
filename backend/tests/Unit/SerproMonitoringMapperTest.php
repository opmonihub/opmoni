<?php

namespace Tests\Unit;

use App\Services\SerproMonitoringMapper;
use App\Services\SerproResult;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Projeção por serviço: o mapper traduz o `dados` do provedor nas colunas
 * que a linha de monitoramento guarda, sem vazar documento em base64.
 */
class SerproMonitoringMapperTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-28 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_o_payload_da_dctfweb_usa_geral_mensal_e_o_mes_corrente(): void
    {
        $payload = (new SerproMonitoringMapper)->payload('CONSXMLDECLARACAO38');

        $this->assertSame([
            'categoria' => 'GERAL_MENSAL',
            'anoPA' => '2026',
            'mesPA' => '09',
        ], $payload);
    }

    public function test_o_payload_da_defis_e_vazio(): void
    {
        $this->assertSame([], (new SerproMonitoringMapper)->payload('CONSDECLARACAO142'));
    }

    public function test_a_dctfweb_extrai_periodo_receitas_e_fgts_1718_sem_base64(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<ProcDctf xmlns="http://www.serpro.gov.br/dctf/v1">
  <ConteudoDeclaracao>
    <DctfXml>
      <A000-DadosIdentificadoresContribuinte>
        <perApuracao>062022</perApuracao>
        <numRecibo>24688</numRecibo>
        <indRetificacao>2</indRetificacao>
      </A000-DadosIdentificadoresContribuinte>
      <A050-CreditosTributariosApurados>
        <CreditoTributarioApurado>
          <codReceita>108201</codReceita>
          <vlTotalCred>10.50</vlTotalCred>
        </CreditoTributarioApurado>
        <CreditoTributarioApurado>
          <codReceita>1718</codReceita>
          <vlTotalCred>99.00</vlTotalCred>
        </CreditoTributarioApurado>
      </A050-CreditosTributariosApurados>
      <A050-DebitosTributariosApurados>
        <DebitoTributarioApurado>
          <codReceita>117601</codReceita>
          <vlTotalDeb>88.25</vlTotalDeb>
        </DebitoTributarioApurado>
      </A050-DebitosTributariosApurados>
    </DctfXml>
  </ConteudoDeclaracao>
</ProcDctf>
XML;

        $result = new SerproResult(
            200,
            ['XMLStringBase64' => base64_encode($xml)],
            [],
            'resp-dctf',
            'tag-de-32-caracteres-para-o-teste',
        );

        $projecao = (new SerproMonitoringMapper)->project('CONSXMLDECLARACAO38', $result);

        $this->assertSame('24688', $projecao['fields']['gi_declaracao']);
        $this->assertSame(109.5, $projecao['fields']['receitas']);
        $this->assertSame(99.0, $projecao['fields']['valor_apurado_1718']);
        $this->assertNull($projecao['due_on']);
        $this->assertNull($projecao['cause']);
        $this->assertSame([
            [
                'period' => '2022-06',
                'declared_at' => null,
                'rectified' => true,
                'slip_number' => null,
                'slip_issued_at' => null,
                'due_on' => null,
                'slip_paid' => null,
            ],
        ], $projecao['periods']);

        $serializado = json_encode($projecao);
        $this->assertStringNotContainsString('XMLStringBase64', $serializado);
        $this->assertStringNotContainsString(base64_encode($xml), $serializado);
    }

    public function test_a_dctfweb_sem_xml_marca_sem_declaracao(): void
    {
        $result = new SerproResult(200, ['XMLStringBase64' => ''], [], 'resp-dctf', 'tag-de-32-caracteres-para-o-teste');

        $projecao = (new SerproMonitoringMapper)->project('CONSXMLDECLARACAO38', $result);

        $this->assertSame('sem_declaracao', $projecao['cause']);
        $this->assertSame([], $projecao['periods']);
    }

    public function test_a_defis_consolida_por_ano_com_a_ultima_transmissao(): void
    {
        $dados = json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/serpro/defis-consultar-declaracoes.json')),
            true,
        );
        $lista = json_decode($dados['dados'], true);

        $result = new SerproResult(200, $lista, [], 'resp-defis', 'tag-de-32-caracteres-para-o-teste');

        $projecao = (new SerproMonitoringMapper)->project('CONSDECLARACAO142', $result);

        $this->assertSame('000000002021002', $projecao['fields']['gi_declaracao']);
        $this->assertNull($projecao['cause']);
        $this->assertSame([
            [
                'period' => '2021',
                'declared_at' => '2023-08-01 14:54:04',
                'rectified' => true,
                'slip_number' => null,
                'slip_issued_at' => null,
                'due_on' => null,
                'slip_paid' => null,
            ],
            [
                'period' => '2020',
                'declared_at' => '2021-04-15 10:30:00',
                'rectified' => false,
                'slip_number' => null,
                'slip_issued_at' => null,
                'due_on' => null,
                'slip_paid' => null,
            ],
        ], $projecao['periods']);
    }

    public function test_a_defis_sem_itens_marca_sem_declaracao(): void
    {
        $result = new SerproResult(200, [], [], 'resp-defis', 'tag-de-32-caracteres-para-o-teste');

        $projecao = (new SerproMonitoringMapper)->project('CONSDECLARACAO142', $result);

        $this->assertSame('sem_declaracao', $projecao['cause']);
        $this->assertSame([], $projecao['periods']);
    }

    public function test_o_payload_do_pagtoweb_pede_a_primeira_pagina_do_ultimo_ano(): void
    {
        $payload = (new SerproMonitoringMapper)->payload('PAGAMENTOS71');

        $this->assertSame(100, $payload['tamanhoDaPagina']);
        $this->assertSame(0, $payload['primeiroDaPagina']);
        $this->assertSame('2025-09-28', $payload['intervaloDataArrecadacao']['dataInicial']);
        $this->assertSame('2026-09-28', $payload['intervaloDataArrecadacao']['dataFinal']);
    }

    public function test_o_pagtoweb_projeta_documentos_pagos_sem_pdf(): void
    {
        $documentos = [
            [
                'numeroDocumento' => '07202215764027873',
                'tipo' => ['descricaoAbreviada' => 'DAS'],
                'periodoApuracao' => '2022-06-01T00:00:00-03:00',
                'dataArrecadacao' => '2022-06-10T15:34:56-03:00',
                'dataVencimento' => '2022-06-20T00:00:00-03:00',
                'valorTotal' => 150.75,
            ],
        ];

        $result = new SerproResult(200, $documentos, [], 'resp-pag', 'tag-de-32-caracteres-para-o-teste');

        $projecao = (new SerproMonitoringMapper)->project('PAGAMENTOS71', $result);

        $this->assertSame(1, $projecao['fields']['quantidade']);
        $this->assertSame('2022-06-10', $projecao['fields']['ultima']);
        $this->assertFalse($projecao['fields']['mais_paginas']);
        $this->assertNull($projecao['cause']);
        $this->assertSame('07202215764027873', $projecao['periods'][0]['slip_number']);
        $this->assertTrue($projecao['periods'][0]['slip_paid']);
        $this->assertSame('DAS', $projecao['periods'][0]['tipo_documento']);
        $this->assertSame(150.75, $projecao['periods'][0]['valor_total']);

        $this->assertStringNotContainsString('base64', json_encode($projecao));
    }

    public function test_o_pagtoweb_sem_documentos_marca_sem_pagamentos(): void
    {
        $result = new SerproResult(200, [], [], 'resp-pag', 'tag-de-32-caracteres-para-o-teste');

        $projecao = (new SerproMonitoringMapper)->project('PAGAMENTOS71', $result);

        $this->assertSame('sem_pagamentos', $projecao['cause']);
        $this->assertSame([], $projecao['periods']);
    }

    public function test_a_caixa_postal_derivada_filtra_por_assunto(): void
    {
        $mapper = new SerproMonitoringMapper;
        $ecac = $mapper->project('MSGCONTRIBUINTE61', new SerproResult(200, [
            'listaMensagens' => [
                [
                    'isn' => '1',
                    'assuntoModelo' => 'FGTS Digital — aviso',
                    'dataEnvio' => '20260901',
                    'indicadorLeitura' => '0',
                ],
                [
                    'isn' => '2',
                    'assuntoModelo' => 'Comunicação DET — prazo',
                    'dataEnvio' => '20260902',
                    'indicadorLeitura' => '1',
                    'dataLeitura' => '20260903',
                    'horaLeitura' => '100000',
                ],
                [
                    'isn' => '3',
                    'assuntoModelo' => 'Outro assunto',
                    'dataEnvio' => '20260903',
                    'indicadorLeitura' => '0',
                ],
            ],
            'indicadorUltimaPagina' => 'S',
        ], [], 'resp-caixa', 'tag-de-32-caracteres-para-o-teste'));

        $derivadas = $mapper->derived('MSGCONTRIBUINTE61', $ecac);

        $this->assertCount(1, $derivadas['caixas-postais/fgts-digital']['messages']);
        $this->assertSame('FGTS Digital — aviso', $derivadas['caixas-postais/fgts-digital']['messages'][0]['assunto']);
        $this->assertCount(1, $derivadas['caixas-postais/det']['messages']);
        $this->assertSame('Comunicação DET — prazo', $derivadas['caixas-postais/det']['messages'][0]['assunto']);
    }

    public function test_o_payload_do_pgmei_usa_ano_calendario_como_string(): void
    {
        $this->assertSame(
            ['anoCalendario' => '2026'],
            (new SerproMonitoringMapper)->payload('DIVIDAATIVA24'),
        );
    }

    public function test_a_divida_ativa_da_fixture_conta_debitos_e_marca_contam_debitos(): void
    {
        $payload = json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/serpro/pgmei-consultar-divida-ativa.json')),
            true,
        );
        $lista = json_decode($payload['dados'], true);

        $result = new SerproResult(200, $lista, [], 'resp-mei', 'tag-de-32-caracteres-para-o-teste');

        $projecao = (new SerproMonitoringMapper)->project('DIVIDAATIVA24', $result);

        $this->assertSame(2, $projecao['fields']['divida_ativa']);
        $this->assertSame('contam_debitos', $projecao['cause']);
        $this->assertSame([
            [
                'period' => '2026-01',
                'declared_at' => null,
                'rectified' => false,
                'slip_number' => null,
                'slip_issued_at' => null,
                'due_on' => null,
                'slip_paid' => null,
            ],
            [
                'period' => '2026-02',
                'declared_at' => null,
                'rectified' => false,
                'slip_number' => null,
                'slip_issued_at' => null,
                'due_on' => null,
                'slip_paid' => null,
            ],
        ], $projecao['periods']);

        $serializado = json_encode($projecao);
        $this->assertStringNotContainsString('INSS', $serializado);
        $this->assertStringNotContainsString('PFN', $serializado);
    }

    public function test_a_divida_ativa_sem_itens_zera_o_campo_e_nao_marca_causa(): void
    {
        $result = new SerproResult(200, [], [], 'resp-mei', 'tag-de-32-caracteres-para-o-teste');

        $projecao = (new SerproMonitoringMapper)->project('DIVIDAATIVA24', $result);

        $this->assertSame(0, $projecao['fields']['divida_ativa']);
        $this->assertNull($projecao['cause']);
        $this->assertSame([], $projecao['periods']);
    }

    public function test_o_payload_dos_pedidos_de_parcelamento_e_vazio(): void
    {
        $mapper = new SerproMonitoringMapper;

        foreach (['PEDIDOSPARC163', 'PEDIDOSPARC173', 'PEDIDOSPARC183', 'PEDIDOSPARC193'] as $idServico) {
            $this->assertSame([], $mapper->payload($idServico));
        }
    }

    public function test_os_pedidos_parcsn_listam_parcelamentos_na_projecao(): void
    {
        $envelope = json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/serpro/parcsn-pedidos-parcelamento.json')),
            true,
        );
        $lista = json_decode($envelope['dados'], true);

        $result = new SerproResult(200, $lista, [], 'resp-parc', 'tag-de-32-caracteres-para-o-teste');

        $projecao = (new SerproMonitoringMapper)->project('PEDIDOSPARC163', $result);

        $this->assertSame(2, $projecao['fields']['quantidade_parcelamentos']);
        $this->assertSame('PARCSN ordinário', $projecao['fields']['modalidade']);
        $this->assertNull($projecao['fields']['consolidacao']);
        $this->assertNull($projecao['cause']);
        $this->assertSame('PARCSN ordinário/1', $projecao['periods'][0]['period']);
        $this->assertSame('2016-02-11', $projecao['periods'][0]['declared_at']);
        $this->assertSame('Em parcelamento', $projecao['periods'][1]['situacao']);
    }

    public function test_o_sitfis_descarta_o_pdf_e_extrai_campos_do_texto(): void
    {
        $pdf = <<<'PDF'
%PDF-1.4
1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj
2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj
3 0 obj<</Type/Page/Contents 4 0 R>>endobj
4 0 obj<</Length 160>>stream
(Emissão 15/09/2026) (Validade 15/09/2027) (Situação Fiscal REGULAR)
endstream
endobj
trailer<</Root 1 0 R>>
%%EOF
PDF;

        $result = new SerproResult(
            200,
            ['pdf' => base64_encode($pdf)],
            [],
            'resp-sitfis',
            'tag-de-32-caracteres-para-o-teste',
        );

        $mapper = new SerproMonitoringMapper;
        $projecao = $mapper->project('RELATORIOSITFIS92', $result);

        $this->assertSame('2026-09-15', $projecao['fields']['emissao']);
        $this->assertSame('2027-09-15', $projecao['fields']['validade']);
        $this->assertSame('REGULAR', $projecao['fields']['situacao_fiscal']);
        $this->assertSame('2027-09-15', $projecao['due_on']);
        $this->assertNull($projecao['cause']);

        $serializado = json_encode($projecao);
        $this->assertStringNotContainsString(base64_encode($pdf), $serializado);
        $this->assertStringNotContainsString('pdf', $serializado);
    }

    public function test_o_sitfis_sem_pdf_marca_sem_relatorio(): void
    {
        $projecao = (new SerproMonitoringMapper)->project(
            'RELATORIOSITFIS92',
            new SerproResult(200, ['pdf' => ''], [], 'resp-sitfis', 'tag-de-32-caracteres-para-o-teste'),
        );

        $this->assertSame('sem_relatorio', $projecao['cause']);
    }

    public function test_as_certidoes_derivadas_repetem_certidao_emissao_e_validade(): void
    {
        $mapper = new SerproMonitoringMapper;
        $direta = [
            'fields' => [
                'certidao' => '12345678901234',
                'emissao' => '2026-09-15',
                'validade' => '2027-09-15',
                'situacao_fiscal' => 'REGULAR',
            ],
            'due_on' => '2027-09-15',
            'cause' => null,
        ];

        $derivadas = $mapper->derived('RELATORIOSITFIS92', $direta);

        $this->assertSame([
            'certidao' => '12345678901234',
            'emissao' => '2026-09-15',
            'validade' => '2027-09-15',
        ], $derivadas['situacao-fiscal/certidoes']['fields']);
        $this->assertSame('2027-09-15', $derivadas['situacao-fiscal/certidoes']['due_on']);
        $this->assertNull($derivadas['situacao-fiscal/certidoes']['cause']);
        $this->assertArrayNotHasKey('situacao_fiscal', $derivadas['situacao-fiscal/certidoes']['fields']);
    }

    public function test_a_mescla_receita_federal_soma_modalidades_e_substitui_o_mesmo_programa(): void
    {
        $mapper = new SerproMonitoringMapper;

        $pert = $mapper->project('PEDIDOSPARC183', new SerproResult(
            200,
            ['parcelamentos' => [['numero' => 1, 'dataDoPedido' => 20180101, 'situacao' => 'Em parcelamento']]],
            [],
            'resp-pert',
            'tag-de-32-caracteres-para-o-teste',
        ));

        $relp = $mapper->project('PEDIDOSPARC193', new SerproResult(
            200,
            ['parcelamentos' => [['numero' => 2, 'dataDoPedido' => 20200101, 'situacao' => 'Encerrado']]],
            [],
            'resp-relp',
            'tag-de-32-caracteres-para-o-teste',
        ));

        $mesclado = $mapper->mesclarPedidosParcelamento($pert, $relp);

        $this->assertSame(2, $mesclado['fields']['quantidade_parcelamentos']);
        $this->assertSame('PERT-SN; RELP-SN', $mesclado['fields']['modalidade']);
        $this->assertSame(['PERT-SN/1', 'RELP-SN/2'], array_column($mesclado['periods'], 'period'));

        $repert = $mapper->mesclarPedidosParcelamento($mesclado, $mapper->project('PEDIDOSPARC183', new SerproResult(
            200,
            ['parcelamentos' => [['numero' => 9, 'dataDoPedido' => 20240101, 'situacao' => 'Em parcelamento']]],
            [],
            'resp-pert-2',
            'tag-de-32-caracteres-para-o-teste',
        )));

        $this->assertSame(2, $repert['fields']['quantidade_parcelamentos']);
        $this->assertSame('PERT-SN/9', $repert['periods'][0]['period']);
        $this->assertSame('RELP-SN/2', $repert['periods'][1]['period']);
    }
}
