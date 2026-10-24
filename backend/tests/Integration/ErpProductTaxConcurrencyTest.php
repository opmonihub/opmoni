<?php

namespace Tests\Integration;

use App\Models\Erp\ErpCompanyProductTaxDefault;
use App\Models\Erp\ErpProductTaxProfile;
use App\Models\Erp\Sales\ErpSale;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

class ErpProductTaxConcurrencyTest extends ErpCommercialTaxRaceTestCase
{
    /** @return iterable<string, array{string, string, bool}> */
    public static function profileRaceCases(): iterable
    {
        foreach (['profile', 'default'] as $writer) {
            foreach (['save', 'refresh', 'post'] as $consumer) {
                foreach ([true, false] as $writerFirst) {
                    yield $writer.' vs '.$consumer.' '.($writerFirst ? 'writer first' : 'consumer first') => [$writer, $consumer, $writerFirst];
                }
            }
        }
    }

    #[DataProvider('profileRaceCases')]
    public function test_profile_and_default_races_publish_one_committed_revision_set_or_conflict_before_effects(string $family, string $action, bool $writerFirst): void
    {
        $old = $this->taxCommittedVector();
        $id = $action === 'save' ? null : $this->taxCreateSale();
        $beforeSale = $id === null ? null : ErpSale::findOrFail($id)->getAttributes();
        $beforeLines = $id === null ? null : ErpSale::findOrFail($id)->lines()->orderBy('ordinal')->get()->toJson();
        $business = $this->taxBusinessState();
        $receipts = DB::table('erp_operation_receipts')->where('erp_company_id', $this->company->id)->count();
        [$writer, $writerHold] = $this->taxProfileWriter($family);
        $consumer = match ($action) {
            'save' => $this->taxInput('/api/v1/erp/sales', $this->taxSaleBody(), $this->consumerSession, 'POST', 'raced-save'),
            'refresh' => $this->taxRefreshInput($id, $this->taxPreview($id), 'raced-refresh'),
            'post' => $this->taxPostInput($id, 'raced-post'),
        };
        if ($writerFirst) {
            [$writerResult, [$consumerResult]] = $this->taxOrderedRace($writer, $writerHold, [$consumer], function () use ($old, $business): void {
                $this->assertSame($old, $this->taxCommittedVector(), 'Uncommitted writer revision must be invisible to the independent parent.');
                $this->assertSame($business, $this->taxBusinessState());
            });
        } else {
            $consumerHold = ['kind' => $action, ...($action === 'post' ? ['source_id' => $id] : [])];
            if ($action === 'refresh') {
                $consumerHold['id'] = $id;
            }
            [$consumerResult, [$writerResult]] = $this->taxOrderedRace($consumer, $consumerHold, [$writer], function () use ($business): void {
                $this->assertSame($business, $this->taxBusinessState(), 'Partial consumer stock/finance writes must remain invisible until commit.');
            });
        }
        $this->taxBodyAtStatus($writerResult, 200);
        $new = $this->taxCommittedVector();
        $this->assertSame($old['nature'], $new['nature']);
        $this->assertSame($old['profile'] + (int) ($family === 'profile'), $new['profile']);
        $this->assertSame($old['default'] + (int) ($family === 'default'), $new['default']);
        if ($family === 'profile') {
            $this->assertSame(2, ErpProductTaxProfile::findOrFail($this->profileId)->revisions()->count());
        } else {
            $this->assertSame($this->replacementProfileId, ErpCompanyProductTaxDefault::where('erp_company_id', $this->company->id)->sole()->erp_product_tax_profile_id);
        }

        if ($writerFirst && $action !== 'save') {
            $error = $this->taxBodyAtStatus($consumerResult, 409);
            $this->assertSame($action === 'post' ? 'sale_fiscal_review_changed' : 'commercial_tax_review_changed', $error['code']);
            $this->assertSame($beforeSale, ErpSale::findOrFail($id)->getAttributes());
            $this->assertSame($beforeLines, ErpSale::findOrFail($id)->lines()->orderBy('ordinal')->get()->toJson());
            $this->assertSame($business, $this->taxBusinessState());
            $this->assertSame($receipts, DB::table('erp_operation_receipts')->where('erp_company_id', $this->company->id)->count());
            $this->taxAssertCoherent($id, $old);
            if ($action === 'refresh') {
                $fresh = $this->taxPreview($id);
                $recovery = $this->taxRefreshInput($id, $fresh, 'raced-refresh');
                $accepted = $this->taxRequest($recovery);
                $this->taxBodyAtStatus($accepted, 200);
                $this->assertSame($accepted['body'], $this->taxBodyAtStatus($this->taxRequest($recovery), 200));
                $this->taxAssertCoherent($id, $new);
                $this->assertSame($business, $this->taxBusinessState());
                $this->taxPostCurrent($id, 'raced-post');
            } else {
                $this->taxRecoverAndPost($id, 'raced-post');
            }

            return;
        }

        $accepted = $this->taxData($consumerResult, $action === 'save' ? 201 : 200);
        $id ??= $accepted['id'];
        $this->taxAssertCoherent($id, $writerFirst ? $new : $old);
        if ($action === 'post') {
            $frozen = ErpSale::findOrFail($id)->commercial_snapshot;
            $this->assertSame('posted', ErpSale::findOrFail($id)->status);
            $this->taxAssertPostedOnce($id, $consumer, $consumerResult);
            $this->assertSame($frozen, ErpSale::findOrFail($id)->commercial_snapshot);
        } else {
            $this->assertSame($business, $this->taxBusinessState());
            $this->assertSame($consumerResult['body'], $this->taxBodyAtStatus($this->taxRequest($consumer), $action === 'save' ? 201 : 200));
            $this->taxAssertCoherent($id, $writerFirst ? $new : $old);
            if ($writerFirst) {
                $this->taxPostCurrent($id, 'raced-post');
            } else {
                $this->taxRecoverAndPost($id, 'raced-post');
            }
        }
    }

    public function test_nfce_waiting_intended_post_rejects_changed_default_before_effects_and_recovers_explicitly(): void
    {
        $id = $this->taxCreateSale('65');
        $old = $this->taxCommittedVector();
        $sale = ErpSale::findOrFail($id)->getAttributes();
        $business = $this->taxBusinessState();
        [$writer, $hold] = $this->taxProfileWriter('default');
        [$changed, [$post]] = $this->taxOrderedRace($writer, $hold, [$this->taxPostInput($id, 'nfce-reviewed-post')]);
        $this->taxBodyAtStatus($changed, 200);
        $this->assertSame('sale_fiscal_review_changed', $this->taxBodyAtStatus($post, 409)['code']);
        $this->assertSame($sale, ErpSale::findOrFail($id)->getAttributes());
        $this->assertSame($business, $this->taxBusinessState());
        $this->taxAssertCoherent($id, $old);
        $this->taxRecoverAndPost($id, 'nfce-reviewed-post');
        $this->assertSame('65', ErpSale::findOrFail($id)->commercial_snapshot['fiscal_context']['model']);
    }
}
