<?php

namespace Tests\Unit;

use App\Enums\FiscalModel;
use App\Enums\FiscalStage;
use App\Services\Fiscal\Manifestacao\FiscalScienceEligibility;
use App\Services\Fiscal\Support\FiscalXmlMetadata;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class FiscalAuthorizationMetadataTest extends TestCase
{
    public function test_issue_and_authorization_dates_are_separate_for_proc_nfe(): void
    {
        $xml = file_get_contents(base_path('tests/Fixtures/fiscal/procNFe.xml'));

        $result = (new FiscalXmlMetadata)->extract($xml, FiscalModel::Nfe);

        $this->assertNotNull($result->emissaoAt);
        $this->assertNotNull($result->authorizedAt);
        $this->assertNotSame(
            $result->emissaoAt?->toIso8601String(),
            $result->authorizedAt?->toIso8601String(),
        );
    }

    public function test_ten_day_science_deadline_uses_authorization_not_issue(): void
    {
        CarbonImmutable::setTestNow('2026-09-28 12:00:00');

        $issue = CarbonImmutable::parse('2022-04-04T11:54:49-03:00');
        $authorized = CarbonImmutable::parse('2026-09-20T10:00:00-03:00');

        $this->assertLessThanOrEqual(
            FiscalScienceEligibility::SCIENCE_DEADLINE_DAYS,
            $authorized->diffInDays(CarbonImmutable::now())
        );

        $this->assertGreaterThan(
            FiscalScienceEligibility::SCIENCE_DEADLINE_DAYS,
            $issue->diffInDays(CarbonImmutable::now())
        );
    }

    public function test_summary_without_authorization_has_null_authorized_at(): void
    {
        $xml = file_get_contents(base_path('tests/Fixtures/fiscal/resNFe.xml'));

        $result = (new FiscalXmlMetadata)->extract($xml, FiscalModel::Nfe);

        $this->assertSame(FiscalStage::Summary, $result->stage);
        $this->assertNotNull($result->emissaoAt);
        $this->assertNull($result->authorizedAt);
    }
}
