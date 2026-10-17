<?php

namespace Tests\Feature;

use App\Erp\ErpCommercialCatalog;
use App\Erp\LegacyPort\LegacyErpUtil;
use App\Erp\LegacyPort\LegacyFiscalEngine;
use App\Erp\LegacyPort\LegacyNfeXmlParser;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErpLegacyPortTest extends TestCase
{
    use RefreshDatabase;

    private const DEST_CNPJ = '11222333000181';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'access.ingress_key' => 'test-key',
            'erp.operating_writes_enabled' => true,
            'cache.default' => 'array',
            'session.driver' => 'array',
            'session.store' => null,
        ]);
        $this->withHeaders([
            'X-Opmoni-Ingress-Key' => 'test-key',
            'X-Opmoni-Client-IP' => '127.0.0.1',
            'X-Opmoni-Client-Proto' => 'http',
            'Origin' => 'http://localhost:3000',
        ]);
    }

    /** @return array{company: ErpCompany, establishment: ErpEstablishment, headers: array<string, string>} */
    private function ownerContext(string $companyCnpj = self::DEST_CNPJ): array
    {
        $plan = app(ErpCommercialCatalog::class)->ensureBasicPlan();
        $user = User::factory()->create();
        $company = ErpCompany::factory()->create(['cnpj' => $companyCnpj]);
        ErpCompanyMembership::factory()->create([
            'erp_company_id' => $company->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        ErpSubscription::factory()->create([
            'erp_company_id' => $company->id,
            'erp_plan_id' => $plan->id,
            'status' => 'active',
        ]);
        $establishment = ErpEstablishment::factory()->create(['erp_company_id' => $company->id]);
        $this->withHeader('X-CSRF-Token', $this->csrf())
            ->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();

        return [
            'company' => $company,
            'establishment' => $establishment,
            'headers' => ['X-Company-Id' => (string) $company->id],
        ];
    }

    private function csrf(): string
    {
        return $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
    }

    private function sampleNfeXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">
  <NFe>
    <infNFe Id="NFe35251030288513000100550010000000011000000012" versao="4.00">
      <ide>
        <cUF>35</cUF>
        <natOp>Venda</natOp>
        <mod>55</mod>
        <serie>1</serie>
        <nNF>1</nNF>
        <dhEmi>2025-10-01T10:00:00-03:00</dhEmi>
        <tpNF>1</tpNF>
        <idDest>1</idDest>
        <indFinal>1</indFinal>
        <indPag>0</indPag>
      </ide>
      <emit>
        <CNPJ>30288513000100</CNPJ>
        <xNome>FORNECEDOR TESTE LTDA</xNome>
        <IE>123456789</IE>
        <enderEmit>
          <xLgr>Rua A</xLgr>
          <nro>100</nro>
          <xBairro>Centro</xBairro>
          <cMun>3550308</cMun>
          <UF>SP</UF>
          <CEP>01001000</CEP>
        </enderEmit>
      </emit>
      <dest>
        <CNPJ>11222333000181</CNPJ>
        <xNome>COMPRADOR TESTE</xNome>
        <IE>987654321</IE>
        <enderDest>
          <xLgr>Av B</xLgr>
          <nro>200</nro>
          <xBairro>Jardim</xBairro>
          <cMun>3550308</cMun>
          <UF>SP</UF>
          <CEP>02002000</CEP>
        </enderDest>
      </dest>
      <det nItem="1">
        <prod>
          <cProd>001</cProd>
          <cEAN>7891000100103</cEAN>
          <xProd>Produto Teste</xProd>
          <NCM>22021000</NCM>
          <CFOP>5102</CFOP>
          <uCom>UN</uCom>
          <qCom>2.0000</qCom>
          <vUnCom>10.0000</vUnCom>
          <vProd>20.00</vProd>
        </prod>
        <imposto>
          <ICMS>
            <ICMS00>
              <orig>0</orig>
              <CST>00</CST>
              <modBC>3</modBC>
              <vBC>20.00</vBC>
              <pICMS>18.00</pICMS>
              <vICMS>3.60</vICMS>
            </ICMS00>
          </ICMS>
          <PIS>
            <PISAliq>
              <CST>01</CST>
              <vBC>20.00</vBC>
              <pPIS>1.65</pPIS>
              <vPIS>0.33</vPIS>
            </PISAliq>
          </PIS>
          <COFINS>
            <COFINSAliq>
              <CST>01</CST>
              <vBC>20.00</vBC>
              <pCOFINS>7.60</pCOFINS>
              <vCOFINS>1.52</vCOFINS>
            </COFINSAliq>
          </COFINS>
        </imposto>
      </det>
      <total>
        <ICMSTot>
          <vBC>20.00</vBC>
          <vICMS>3.60</vICMS>
          <vProd>20.00</vProd>
          <vFrete>0.00</vFrete>
          <vDesc>2.00</vDesc>
          <vPIS>0.33</vPIS>
          <vCOFINS>1.52</vCOFINS>
          <vNF>18.00</vNF>
        </ICMSTot>
      </total>
      <cobr>
        <dup>
          <nDup>001</nDup>
          <dVenc>2025-11-01</dVenc>
          <vDup>18.00</vDup>
        </dup>
      </cobr>
    </infNFe>
  </NFe>
</nfeProc>
XML;
    }

    public function test_legacy_erp_util_num_uf_and_percentages(): void
    {
        $util = new LegacyErpUtil;

        $this->assertSame(1234.56, $util->num_uf('1.234,56'));
        $this->assertSame(10.0, $util->calc_percentage(100, 10));
        $this->assertSame(100.0, $util->calc_percentage_base(118, 18));
        $this->assertSame(20.0, $util->get_percent(100, 120));
    }

    public function test_legacy_erp_util_generate_reference_number_from_company_settings(): void
    {
        $company = ErpCompany::factory()->create([
            'operating_settings' => [
                'ref_no_prefixes' => ['purchase' => 'PC'],
            ],
        ]);

        $util = new LegacyErpUtil;
        $ref = $util->generateReferenceNumber('purchase', 7, $company);

        $this->assertStringStartsWith('PC', $ref);
        $this->assertStringContainsString('/0007', $ref);
    }

    public function test_legacy_fiscal_engine_monta_chave_and_dv(): void
    {
        $engine = new LegacyFiscalEngine;
        $chave43 = '3525103028851300010055001000000001100000001';
        $dv = $engine->calculaDV($chave43);
        $this->assertMatchesRegularExpression('/^\d$/', $dv);

        $full = $engine->montaChave(35, 25, 10, '30288513000100', 55, 1, 1, 1, 1);
        $this->assertSame(44, strlen($full));
        $this->assertSame($dv, substr($full, -1));
    }

    public function test_legacy_fiscal_engine_item_taxes_match_legacy_formulas(): void
    {
        $engine = new LegacyFiscalEngine;
        $line = (object) ['unit_price' => 100, 'quantity' => 2];
        $product = (object) [
            'origem' => 0,
            'cst_csosn' => '00',
            'perc_icms' => 18,
            'cst_pis' => '01',
            'perc_pis' => 1.65,
            'cst_cofins' => '01',
            'perc_cofins' => 7.6,
            'cst_ipi' => '50',
            'perc_ipi' => 0,
            'pCredSN' => 0,
            'adRemICMSRet' => 0,
            'modBCST' => 0,
            'pICMSST' => 0,
        ];
        $stdProd = (object) ['vProd' => '200.0000', 'qTrib' => 2];
        $config = (object) ['regime' => 3, 'exclusao_icms_pis_cofins' => false];

        $somaICMS = 0;
        $somaVICMSST = 0;
        $vbc = 0;
        $icms = $engine->buildStdIcmsRegimeNormal(1, $line, $product, $stdProd, $config, null, $somaICMS, $somaVICMSST, $vbc);

        $this->assertSame('200.0000', $icms->vBC);
        $this->assertSame('18.0000', $icms->pICMS);
        $this->assertEqualsWithDelta(36.0, $somaICMS, 0.0001);

        $pis = $engine->buildStdPis(1, $product, $stdProd, $icms, $config);
        $this->assertSame('3.3000', $pis->vPIS);
    }

    public function test_legacy_nfe_xml_parser_extracts_inf_nfe_blocks(): void
    {
        $parser = new LegacyNfeXmlParser;
        $parsed = $parser->parse($this->sampleNfeXml());

        $this->assertSame('35251030288513000100550010000000011000000012', $parsed['chave']);
        $this->assertSame('1', $parsed['ide']['nNF']);
        $this->assertSame('30.288.513/0001-00', $parsed['emit']['cpf_cnpj']);
        $this->assertSame('11.222.333/0001-81', $parsed['dest']['cpf_cnpj']);
        $this->assertCount(1, $parsed['itens']);
        $this->assertSame('5102', $parsed['itens'][0]['CFOP']);
        $this->assertSame('00', $parsed['itens'][0]['imposto']['icms']['CST']);
        $this->assertSame('20.00', $parsed['totais']['vProd']);
        $this->assertSame(18.0, $parsed['dados_nf']['vFinal']);
        $this->assertSame('001', $parsed['fatura'][0]['numero']);
    }

    public function test_nfe_entrada_tipos_pagamento_list(): void
    {
        $tipos = LegacyFiscalEngine::tiposPagamentoNfeEntrada();
        $this->assertArrayHasKey('15', $tipos);
        $this->assertSame('Boleto Bancário', $tipos['15']);
    }

    public function test_purchase_xml_preview_api_returns_legacy_parser_shape(): void
    {
        $ctx = $this->ownerContext(self::DEST_CNPJ);

        $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/purchases/xml-preview', ['xml' => $this->sampleNfeXml()])
            ->assertOk()
            ->assertJsonPath('data.chave', '35251030288513000100550010000000011000000012')
            ->assertJsonPath('data.emit.cpf_cnpj', '30.288.513/0001-00')
            ->assertJsonPath('data.dest.cpf_cnpj', '11.222.333/0001-81')
            ->assertJsonPath('data.det.0.CFOP', '5102')
            ->assertJsonPath('data.total.vNF', '18.00')
            ->assertJsonPath('data.duplicatas.0.numero', '001');
    }

    public function test_purchase_xml_preview_api_rejects_dest_cnpj_mismatch(): void
    {
        $ctx = $this->ownerContext('30288513000100');

        $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/purchases/xml-preview', ['xml' => $this->sampleNfeXml()])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_error');
    }

    public function test_fiscal_document_store_generates_access_key_via_legacy_engine(): void
    {
        $ctx = $this->ownerContext('30288513000100');

        $response = $this->withHeaders([...$ctx['headers'], 'X-CSRF-Token' => $this->csrf()])
            ->postJson('/api/v1/erp/fiscal/documents', [
                'erp_establishment_id' => $ctx['establishment']->id,
                'model' => '55',
                'number' => 42,
                'uf' => 'SP',
                'cnf' => '12345678',
                'idempotency_key' => 'legacy-key-preview-1',
            ])
            ->assertCreated();

        $accessKey = $response->json('data.access_key');
        $this->assertIsString($accessKey);
        $this->assertSame(44, strlen($accessKey));
        $this->assertSame(
            (new LegacyFiscalEngine)->calculaDV(substr($accessKey, 0, 43)),
            substr($accessKey, -1),
        );
    }
}
