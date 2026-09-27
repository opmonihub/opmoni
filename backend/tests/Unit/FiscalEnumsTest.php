<?php

namespace Tests\Unit;

use App\Enums\FiscalKind;
use App\Enums\FiscalModel;
use App\Enums\FiscalSkipReason;
use App\Enums\FiscalSource;
use PHPUnit\Framework\TestCase;

class FiscalEnumsTest extends TestCase
{
    public function test_source_values(): void
    {
        $this->assertSame('nfe_distribuicao', FiscalSource::NfeDistribuicao->value);
        $this->assertSame('cte_distribuicao', FiscalSource::CteDistribuicao->value);
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
        $this->assertSame('no_certificate', FiscalSkipReason::NoCertificate->value);
        $this->assertSame('interrupted', FiscalSkipReason::Interrupted->value);
    }

    public function test_source_and_model_labels(): void
    {
        $this->assertSame('Distribuição NF-e', FiscalSource::NfeDistribuicao->label());
        $this->assertSame('Distribuição CT-e', FiscalSource::CteDistribuicao->label());
        $this->assertSame('NF-e', FiscalModel::Nfe->label());
        $this->assertSame('NFC-e', FiscalModel::Nfce->label());
        $this->assertSame('CT-e', FiscalModel::Cte->label());
        $this->assertSame('NFS-e', FiscalModel::Nfse->label());
    }
}
