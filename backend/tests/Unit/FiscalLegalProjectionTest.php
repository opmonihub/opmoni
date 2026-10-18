<?php

namespace Tests\Unit;

use App\Enums\FiscalModel;
use App\Http\Resources\FiscalDocumentDetailResource;
use App\Http\Resources\FiscalProcessingResource;
use App\Models\FiscalDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FiscalLegalProjectionTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_both_views_project_ninety_days_from_authorization_without_changing_issue_date(): void
    {
        Carbon::setTestNow('2026-10-17 12:00:00');
        $document = (new FiscalDocument)->forceFill([
            'model' => FiscalModel::Nfe,
            'authorized_at' => '2026-10-01 12:00:00',
            'emissao_at' => '2022-04-04 11:00:00',
            'next_attempt_at' => '2026-10-18 12:00:00',
        ])->setRelation('client', null);

        foreach ([new FiscalProcessingResource($document), new FiscalDocumentDetailResource($document)] as $resource) {
            $data = $resource->toArray(new Request);
            $this->assertSame('2026-10-01T12:00:00+00:00', Carbon::parse($data['authorized_at'])->toIso8601String());
            $this->assertSame('2026-12-30T12:00:00+00:00', $data['conclusive_deadline_at']);
            $this->assertStringStartsWith('2022-04-04', $data['emissao_at']);
            $this->assertArrayNotHasKey('storage_path', $data);
        }
        $this->assertSame('2026-10-01', $document->authorized_at->toDateString());
        $this->assertSame('2026-10-18T12:00:00+00:00', (new FiscalProcessingResource($document))->toArray(new Request)['next_attempt_at']);
    }

    public function test_missing_future_and_non_nfe_authorization_cannot_invent_a_conclusive_deadline(): void
    {
        Carbon::setTestNow('2026-10-17 12:00:00');
        foreach ([[null, FiscalModel::Nfe], ['2027-01-01', FiscalModel::Nfe], ['2026-10-01', FiscalModel::Cte]] as [$authorizedAt, $model]) {
            $document = (new FiscalDocument)->forceFill([
                'model' => $model,
                'authorized_at' => $authorizedAt,
                'emissao_at' => '2026-01-01',
            ])->setRelation('client', null);
            foreach ([new FiscalProcessingResource($document), new FiscalDocumentDetailResource($document)] as $resource) {
                $data = $resource->toArray(new Request);
                $this->assertArrayHasKey('conclusive_deadline_at', $data);
                $this->assertNull($data['conclusive_deadline_at']);
            }
        }
    }
}
