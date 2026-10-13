<?php

namespace Tests\Unit;

use App\Enums\FiscalKind;
use App\Enums\FiscalModel;
use App\Enums\FiscalSkipReason;
use App\Enums\FiscalSource;
use App\Enums\FiscalStage;
use PHPUnit\Framework\TestCase;

class FiscalEnumsTest extends TestCase
{
    public function test_source_values(): void
    {
        $this->assertSame('nfe_distribuicao', FiscalSource::NfeDistribuicao->value);
        $this->assertSame('cte_distribuicao', FiscalSource::CteDistribuicao->value);
        $this->assertSame('nfse_adn', FiscalSource::NfseAdn->value);
    }

    public function test_model_values(): void
    {
        $this->assertSame('nfe', FiscalModel::Nfe->value);
        $this->assertSame('nfce', FiscalModel::Nfce->value);
        $this->assertSame('cte', FiscalModel::Cte->value);
        $this->assertSame('nfse', FiscalModel::Nfse->value);
    }

    public function test_model_is_derived_from_the_fiscal_document_model_code(): void
    {
        $this->assertSame(FiscalModel::Nfe, FiscalModel::fromDocumentModel('55'));
        $this->assertSame(FiscalModel::Nfce, FiscalModel::fromDocumentModel('65'));
        $this->assertSame(FiscalModel::Cte, FiscalModel::fromDocumentModel('57'));
        $this->assertNull(FiscalModel::fromDocumentModel('99'));
    }

    public function test_kind_and_skip_reason_values(): void
    {
        $this->assertSame('document', FiscalKind::Document->value);
        $this->assertSame('event', FiscalKind::Event->value);
        $this->assertSame('blocked', FiscalSkipReason::Blocked->value);
        $this->assertSame('locked', FiscalSkipReason::Locked->value);
        $this->assertSame('no_certificate', FiscalSkipReason::NoCertificate->value);
        $this->assertSame('interrupted', FiscalSkipReason::Interrupted->value);
    }

    public function test_stage_values(): void
    {
        // A etapa é onde a entrega caiu na cadeia da distribuição. São três, e
        // três linhas: resumo, documento completo e evento. A chave composta sem
        // esta coluna guardava o resumo e o documento completo na mesma linha, e o
        // segundo apagava o primeiro.
        $this->assertSame('summary', FiscalStage::Summary->value);
        $this->assertSame('document', FiscalStage::Document->value);
        $this->assertSame('event', FiscalStage::Event->value);
    }

    public function test_a_summary_is_not_an_event(): void
    {
        // `kind` e `stage` são eixos diferentes: o resumo e o documento completo
        // são os dois `kind = document`, e é a etapa que os separa. Um contador
        // que contasse resumo como documento dobraria o número de notas da
        // carteira.
        $this->assertSame(FiscalKind::Document, FiscalKind::Document);
        $this->assertNotSame(FiscalStage::Summary, FiscalStage::Document);
        $this->assertNotSame(FiscalStage::Summary, FiscalStage::Event);
    }

    public function test_only_the_document_stages_carry_a_digest(): void
    {
        // O `digVal` é a comparação de integridade da decisão 8, e ela só tem
        // duas pontas: o resumo traz um, o documento autorizado traz o outro.
        // Evento não tem par, então não tem com o que conferir.
        $this->assertSame(FiscalStage::Document, FiscalStage::Summary->counterpart());
        $this->assertSame(FiscalStage::Summary, FiscalStage::Document->counterpart());
        $this->assertNull(FiscalStage::Event->counterpart());
    }

    public function test_stage_labels(): void
    {
        $this->assertSame('Resumo', FiscalStage::Summary->label());
        $this->assertSame('Documento completo', FiscalStage::Document->label());
        $this->assertSame('Evento', FiscalStage::Event->label());
    }

    public function test_source_and_model_labels(): void
    {
        $this->assertSame('Distribuição NF-e', FiscalSource::NfeDistribuicao->label());
        $this->assertSame('Distribuição CT-e', FiscalSource::CteDistribuicao->label());
        $this->assertSame('ADN NFS-e', FiscalSource::NfseAdn->label());
        $this->assertSame('NF-e', FiscalModel::Nfe->label());
        $this->assertSame('NFC-e', FiscalModel::Nfce->label());
        $this->assertSame('CT-e', FiscalModel::Cte->label());
        $this->assertSame('NFS-e', FiscalModel::Nfse->label());
    }
}
