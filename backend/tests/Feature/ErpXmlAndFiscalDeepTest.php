<?php

namespace Tests\Feature;

use App\Erp\Fiscal\ErpFiscalPayloadBuilder;
use App\Erp\Stock\ErpPurchaseXmlPreviewService;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\Stock\ErpPurchase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErpXmlAndFiscalDeepTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY_CNPJ = '30288513000100';

    private const EMITTER_CNPJ = '11222333000181';

    public function test_purchase_xml_preview_parses_supplier_items_and_totals(): void
    {
        $company = ErpCompany::factory()->create(['cnpj' => self::COMPANY_CNPJ]);
        $chave = $this->sampleAccessKey(self::EMITTER_CNPJ);
        $xml = $this->sampleNfeXml($chave, self::EMITTER_CNPJ, self::COMPANY_CNPJ);

        $result = app(ErpPurchaseXmlPreviewService::class)->preview($xml, $company->id, self::COMPANY_CNPJ);

        $this->assertTrue($result['valid']);
        $this->assertSame($chave, $result['preview']['chave']);
        $this->assertSame(self::EMITTER_CNPJ, $result['preview']['emitente_cnpj']);
        $this->assertSame(self::COMPANY_CNPJ, $result['preview']['destinatario_cnpj']);
        $this->assertSame('100.0000', $result['preview']['totals']['v_prod']);
        $this->assertCount(1, $result['preview']['items']);
        $this->assertSame('PROD-1', $result['preview']['items'][0]['codigo']);
    }

    public function test_purchase_xml_preview_flags_duplicate_access_key(): void
    {
        $company = ErpCompany::factory()->create(['cnpj' => self::COMPANY_CNPJ]);
        $establishment = ErpEstablishment::factory()->create(['erp_company_id' => $company->id]);
        $chave = $this->sampleAccessKey(self::EMITTER_CNPJ);
        ErpPurchase::query()->create([
            'erp_company_id' => $company->id,
            'erp_establishment_id' => $establishment->id,
            'document_no' => $chave,
            'status' => 'posted',
            'total_amount' => '100',
        ]);

        $result = app(ErpPurchaseXmlPreviewService::class)->preview(
            $this->sampleNfeXml($chave, self::EMITTER_CNPJ, self::COMPANY_CNPJ),
            $company->id,
            self::COMPANY_CNPJ,
        );

        $this->assertFalse($result['valid']);
        $this->assertContains('XML já importado para a empresa (chave duplicada).', $result['errors']);
    }

    public function test_purchase_xml_preview_rejects_wrong_destinatario(): void
    {
        $company = ErpCompany::factory()->create(['cnpj' => self::COMPANY_CNPJ]);
        $chave = $this->sampleAccessKey(self::EMITTER_CNPJ);
        $result = app(ErpPurchaseXmlPreviewService::class)->preview(
            $this->sampleNfeXml($chave, self::EMITTER_CNPJ, '99999999000191'),
            $company->id,
            self::COMPANY_CNPJ,
        );

        $this->assertFalse($result['valid']);
        $this->assertContains('Destinatário do XML não corresponde ao CNPJ da empresa.', $result['errors']);
    }

    public function test_fiscal_payload_builder_generates_valid_access_keys_for_all_models(): void
    {
        $builder = app(ErpFiscalPayloadBuilder::class);
        $context = [
            'uf' => 'SP',
            'cnpj' => self::COMPANY_CNPJ,
            'series' => 1,
            'number' => 9001,
            'cnf' => '12345678',
            'issued_at' => '2026-10-17T12:00:00-03:00',
        ];

        foreach (['55', '65', '57', '58'] as $model) {
            $chave = $builder->buildAccessKey([...$context, 'model' => $model]);
            $this->assertSame(44, strlen($chave));
            $this->assertTrue($builder->validatesAccessKey($chave));
        }
    }

    public function test_fiscal_payload_builder_marks_contingency_without_provider_authorization(): void
    {
        $builder = app(ErpFiscalPayloadBuilder::class);
        $contingency = $builder->resolveContingency('65', true, json_encode(['tpEmis' => 9], JSON_THROW_ON_ERROR));
        $payload = $builder->buildDocumentPayload('nfce', [
            'uf' => 'SP',
            'cnpj' => self::COMPANY_CNPJ,
            'series' => 1,
            'number' => 15,
            'contingency_active' => true,
            'contingency_status' => json_encode(['tpEmis' => 9], JSON_THROW_ON_ERROR),
            'total_produtos' => '10,00',
        ]);

        $this->assertSame(9, $contingency['tp_emis']);
        $this->assertFalse($contingency['claims_provider_authorization']);
        $this->assertSame(9, $payload['ide']['tpEmis']);
        $this->assertFalse($payload['contingency']['claims_provider_authorization']);
    }

    public function test_fiscal_invalidation_rules_match_source_bounds(): void
    {
        $builder = app(ErpFiscalPayloadBuilder::class);
        $errors = $builder->validateInvalidation([
            'model' => '55',
            'series' => 0,
            'number_from' => 10,
            'number_to' => 5,
            'justification' => 'curta',
        ]);

        $this->assertContains('Série zerada ou inválida.', $errors);
        $this->assertContains('Faixa de numeração inválida.', $errors);
        $this->assertContains('Justificativa deve ter ao menos 15 caracteres.', $errors);
    }

    private function sampleAccessKey(string $emitterCnpj): string
    {
        $builder = app(ErpFiscalPayloadBuilder::class);

        return $builder->buildAccessKey([
            'uf' => 'SP',
            'cnpj' => $emitterCnpj,
            'model' => '55',
            'series' => 1,
            'number' => 123,
            'cnf' => '87654321',
            'issued_at' => '2026-10-17T10:00:00-03:00',
        ]);
    }

    private function sampleNfeXml(string $chave, string $emitterCnpj, string $destCnpj): string
    {
        return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">
          <NFe>
            <infNFe versao="4.00" Id="NFe{$chave}">
              <ide>
                <nNF>123</nNF>
                <serie>1</serie>
                <mod>55</mod>
              </ide>
              <emit>
                <CNPJ>{$emitterCnpj}</CNPJ>
                <xNome>Fornecedor Teste</xNome>
              </emit>
              <dest>
                <CNPJ>{$destCnpj}</CNPJ>
              </dest>
              <det nItem="1">
                <prod>
                  <cProd>PROD-1</cProd>
                  <xProd>Produto importado</xProd>
                  <NCM>12345678</NCM>
                  <CFOP>1102</CFOP>
                  <uCom>UN</uCom>
                  <qCom>1.0000</qCom>
                  <vUnCom>100.0000000000</vUnCom>
                  <vProd>100.00</vProd>
                  <cEAN>SEM GTIN</cEAN>
                </prod>
              </det>
              <total>
                <ICMSTot>
                  <vProd>100.00</vProd>
                  <vDesc>0.00</vDesc>
                  <vFrete>0.00</vFrete>
                  <vNF>100.00</vNF>
                </ICMSTot>
              </total>
            </infNFe>
          </NFe>
        </nfeProc>
        XML;
    }
}
