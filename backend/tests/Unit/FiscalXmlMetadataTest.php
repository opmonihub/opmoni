<?php

namespace Tests\Unit;

use App\Enums\FiscalKind;
use App\Enums\FiscalModel;
use App\Services\Fiscal\Support\FiscalXmlMetadata;
use Tests\TestCase;

class FiscalXmlMetadataTest extends TestCase
{
    public function test_accepts_a_valid_access_key(): void
    {
        // Chave com o DV 0 que o módulo 11 exige: soma 980, resto 1.
        $this->assertTrue(FiscalXmlMetadata::isValidChave('35220499999999999999550010020000001240556600'));
    }

    public function test_rejects_wrong_check_digit(): void
    {
        $this->assertFalse(FiscalXmlMetadata::isValidChave('35220499999999999999550010020000001240556603'));
    }

    public function test_rejects_wrong_length_and_non_digits(): void
    {
        $this->assertFalse(FiscalXmlMetadata::isValidChave('123'));
        $this->assertFalse(FiscalXmlMetadata::isValidChave(str_repeat('A', 44)));
    }

    public function test_extracts_summary_metadata(): void
    {
        $xml = file_get_contents(base_path('tests/Fixtures/fiscal/resNFe.xml'));

        $result = (new FiscalXmlMetadata)->extract($xml, FiscalModel::Nfe);

        $this->assertSame(FiscalModel::Nfe, $result->model);
        $this->assertSame(FiscalKind::Document, $result->kind);
        $this->assertSame('35220499999999999999550010020000001240556600', $result->chave);
        $this->assertSame('99999999999999', $result->emitenteCnpj);
        $this->assertSame('710.00', $result->valorTotal);
        $this->assertNotNull($result->emissaoAt);
        $this->assertSame('', $result->eventId);
    }

    public function test_extracts_authorized_document_metadata(): void
    {
        $xml = file_get_contents(base_path('tests/Fixtures/fiscal/procNFe.xml'));

        $result = (new FiscalXmlMetadata)->extract($xml, FiscalModel::Nfe);

        $this->assertSame(FiscalKind::Document, $result->kind);
        $this->assertSame('35220499999999999999550010020000001240556600', $result->chave);
        $this->assertSame('99999999999999', $result->emitenteCnpj);
        $this->assertSame('11222333000181', $result->destinatarioCnpj);
    }

    public function test_follows_the_model_path_instead_of_any_matching_element(): void
    {
        // No CT-e o destinatário é `<toma>` e o valor é `vTPrest`: um CNPJ
        // qualquer, ou um `vNF` que não existe, não podem satisfazer a
        // extração.
        $xml = file_get_contents(base_path('tests/Fixtures/fiscal/cteProc.xml'));

        $result = (new FiscalXmlMetadata)->extract($xml, FiscalModel::Cte);

        $this->assertSame(FiscalModel::Cte, $result->model);
        $this->assertSame('35220799999999999999570010000001231123456786', $result->chave);
        $this->assertSame('99999999999999', $result->emitenteCnpj);
        $this->assertSame('11222333000181', $result->destinatarioCnpj);
        $this->assertSame('1500.00', $result->valorTotal);
    }

    public function test_extracts_event_identity(): void
    {
        $xml = <<<'XML'
        <procEventoNFe xmlns="http://www.portalfiscal.inf.br/nfe">
          <evento versao="1.00">
            <infEvento Id="ID1101113522049999999999999955001002000000124055660001">
              <tpEvento>110111</tpEvento>
              <nSeqEvento>1</nSeqEvento>
              <dhEvento>2022-04-04T11:54:49-03:00</dhEvento>
            </infEvento>
          </evento>
        </procEventoNFe>
        XML;

        $result = (new FiscalXmlMetadata)->extract($xml, FiscalModel::Nfe);

        $this->assertSame(FiscalKind::Event, $result->kind);
        $this->assertSame('35220499999999999999550010020000001240556600', $result->chave);
        $this->assertSame('110111-1', $result->eventId);
        $this->assertNotNull($result->eventoOcorridoEmAt);
    }
}
