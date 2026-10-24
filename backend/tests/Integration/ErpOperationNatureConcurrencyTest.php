<?php

namespace Tests\Integration;

use App\Models\Erp\ErpOperationNature;
use App\Models\Erp\Sales\ErpSale;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

class ErpOperationNatureConcurrencyTest extends ErpCommercialTaxRaceTestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function natureConsumerCases(): iterable
    {
        foreach (['default', 'choice', 'exception', 'legacy'] as $writer) {
            foreach (['refresh', 'post'] as $consumer) {
                yield $writer.' vs '.$consumer => [$writer, $consumer];
            }
        }
    }

    #[DataProvider('natureConsumerCases')]
    public function test_nature_writers_advance_one_aggregate_and_waiting_consumers_conflict_without_partial_effects(string $family, string $action): void
    {
        $id = $this->taxCreateSale();
        $old = $this->taxCommittedVector();
        $before = ErpSale::findOrFail($id)->getAttributes();
        $lines = ErpSale::findOrFail($id)->lines()->orderBy('ordinal')->get()->toJson();
        $business = $this->taxBusinessState();
        $receipts = DB::table('erp_operation_receipts')->where('erp_company_id', $this->company->id)->count();
        [$writer, $hold] = $this->taxNatureWriter($family);
        $consumer = $action === 'refresh'
            ? $this->taxRefreshInput($id, $this->taxPreview($id), 'nature-raced-refresh')
            : $this->taxPostInput($id, 'nature-raced-post');
        [$changed, [$stale]] = $this->taxOrderedRace($writer, $hold, [$consumer], function () use ($old): void {
            $this->assertSame($old, $this->taxCommittedVector());
        });
        $this->taxBodyAtStatus($changed, 200);
        $error = $this->taxBodyAtStatus($stale, 409);
        $this->assertSame($action === 'refresh' ? 'commercial_tax_review_changed' : 'sale_fiscal_review_changed', $error['code']);
        $this->assertSame($before, ErpSale::findOrFail($id)->getAttributes());
        $this->assertSame($lines, ErpSale::findOrFail($id)->lines()->orderBy('ordinal')->get()->toJson());
        $this->assertSame($business, $this->taxBusinessState());
        $this->assertSame($receipts, DB::table('erp_operation_receipts')->where('erp_company_id', $this->company->id)->count());
        $new = $this->taxCommittedVector();
        $this->assertSame($old['nature'] + 1, $new['nature']);
        $this->assertSame($old['profile'], $new['profile']);
        $this->assertSame($old['default'], $new['default']);
        $this->taxAssertCoherent($id, $old);
        $this->taxAssertNatureInvariants();
        if ($action === 'refresh') {
            $fresh = $this->taxPreview($id);
            $apply = $this->taxRefreshInput($id, $fresh, 'nature-raced-refresh');
            $accepted = $this->taxRequest($apply);
            $this->taxBodyAtStatus($accepted, 200);
            $this->assertSame($accepted['body'], $this->taxBodyAtStatus($this->taxRequest($apply), 200));
            $this->taxAssertCoherent($id, $new);
            $this->taxPostCurrent($id, 'nature-raced-post');
        } else {
            $this->taxRecoverAndPost($id, 'nature-raced-post');
        }
    }

    /** @return iterable<string, array{string}> */
    public static function natureConfigurationCases(): iterable
    {
        yield 'default versus exception' => ['default-exception'];
        yield 'same allowed choice' => ['duplicate-choice'];
        yield 'same product exception' => ['duplicate-exception'];
        yield 'legacy writer versus allowed collection' => ['legacy-collection'];
        yield 'default archive versus exception target' => ['archive-exception'];
    }

    #[DataProvider('natureConfigurationCases')]
    public function test_configuration_writers_preserve_aggregate_revision_default_membership_and_active_uniqueness(string $case): void
    {
        $base = self::NATURES.'/'.$this->natureId;
        $old = $this->taxCommittedVector();
        $revision = $old['nature'];
        $business = $this->taxBusinessState();
        $audits = DB::table('erp_catalog_attributions')->where('erp_company_id', $this->company->id)->count();
        $sameException = ['erp_product_id' => $this->products[1]->id,
            'erp_operation_nature_cfop_choice_id' => $this->choices['5103'], 'expected_revision' => $revision];
        [$holder, $hold, $waiting, $holderStatus, $retryField] = match ($case) {
            'default-exception' => [...$this->taxNatureWriter('default'), $this->taxNatureWriter('exception', $this->thirdSession)[0], 200, null],
            'duplicate-choice' => [$this->taxInput($base.'/choices', ['cfop' => '5115', 'expected_revision' => $revision], $this->writerSession),
                ['kind' => 'nature', 'id' => $this->natureId],
                $this->taxInput($base.'/choices', ['cfop' => '5115', 'expected_revision' => $revision], $this->thirdSession), 201, 'cfop'],
            'duplicate-exception' => [$this->taxInput($base.'/product-exceptions', $sameException, $this->writerSession),
                ['kind' => 'nature', 'id' => $this->natureId], $this->taxInput($base.'/product-exceptions', $sameException, $this->thirdSession), 201, 'erp_product_id'],
            'legacy-collection' => [...$this->taxNatureWriter('legacy'), $this->taxInput($base, [
                'cfop' => '5103', 'allowed_cfops' => ['5101', '5102', '5103', '5115'], 'expected_revision' => $revision,
            ], $this->thirdSession, 'PATCH'), 200, null],
            'archive-exception' => [...$this->taxNatureWriter('choice'), $this->taxInput($base.'/product-exceptions', [
                ...$sameException, 'erp_operation_nature_cfop_choice_id' => $this->choices['5102'],
            ], $this->thirdSession), 200, 'erp_operation_nature_cfop_choice_id'],
        };
        [$committed, [$stale]] = $this->taxOrderedRace($holder, $hold, [$waiting], function () use ($old, $business): void {
            $this->assertSame($old, $this->taxCommittedVector());
            $this->assertSame($business, $this->taxBusinessState());
        });
        $this->taxBodyAtStatus($committed, $holderStatus);
        $this->assertSame('operation_nature_changed', $this->taxBodyAtStatus($stale, 409)['code']);
        $this->assertSame($revision + 1, ErpOperationNature::findOrFail($this->natureId)->configuration_revision);
        $this->assertGreaterThan($audits, DB::table('erp_catalog_attributions')->where('erp_company_id', $this->company->id)->count());
        $this->taxAssertNatureInvariants();
        $this->assertSame($business, $this->taxBusinessState());
        $afterWinner = $this->taxNatureConfiguration();
        $winnerAudits = DB::table('erp_catalog_attributions')->where('erp_company_id', $this->company->id)->count();
        $waiting['body']['expected_revision'] = $revision + 1;
        if ($retryField !== null) {
            $retry = $this->taxBodyAtStatus($this->taxRequest($waiting), 422);
            $this->assertArrayHasKey($retryField, $retry['errors']);
            $this->assertSame($afterWinner, $this->taxNatureConfiguration());
            $this->assertSame($winnerAudits, DB::table('erp_catalog_attributions')->where('erp_company_id', $this->company->id)->count());
        } else {
            $this->taxBodyAtStatus($this->taxRequest($waiting), 200);
            $this->assertSame($revision + 2, ErpOperationNature::findOrFail($this->natureId)->configuration_revision);
            $this->taxAssertNatureInvariants();
        }
        $this->assertSame($business, $this->taxBusinessState());
        if ($case === 'archive-exception') {
            // A later active exception must prevent removing its target, even with a current review.
            $nature = ErpOperationNature::findOrFail($this->natureId);
            $invalid = $this->taxInput($base.'/choices/'.$this->choices['5101'], ['expected_revision' => $nature->configuration_revision], $this->writerSession, 'DELETE');
            $this->assertArrayHasKey('cfop', $this->taxBodyAtStatus($this->taxRequest($invalid), 422)['errors']);
            $this->assertSame($afterWinner, $this->taxNatureConfiguration());
        }
    }

    public function test_multiline_save_holds_both_profile_and_nature_writers_until_one_joint_vector_is_published(): void
    {
        $old = $this->taxCommittedVector();
        [$profile] = $this->taxProfileWriter('profile', $this->writerSession);
        [$nature] = $this->taxNatureWriter('exception', $this->thirdSession);
        $save = $this->taxInput('/api/v1/erp/sales', $this->taxSaleBody(), $this->consumerSession, 'POST', 'joint-vector-save');
        [$saved, [$profileChanged, $natureChanged]] = $this->taxOrderedRace($save, ['kind' => 'save'], [$profile, $nature]);
        $id = $this->taxData($saved, 201)['id'];
        $this->taxBodyAtStatus($profileChanged, 200);
        $this->taxBodyAtStatus($natureChanged, 200);
        $this->taxAssertCoherent($id, $old);
        $new = $this->taxCommittedVector();
        $this->assertSame($old['profile'] + 1, $new['profile']);
        $this->assertSame($old['nature'] + 1, $new['nature']);
        $this->assertSame($old['default'], $new['default']);
        $before = ErpSale::findOrFail($id)->getAttributes();
        $effects = $this->taxBusinessState();
        $post = $this->taxPostInput($id, 'joint-vector-post');
        $this->assertSame('sale_fiscal_review_changed', $this->taxBodyAtStatus($this->taxRequest($post), 409)['code']);
        $this->assertSame($before, ErpSale::findOrFail($id)->getAttributes());
        $this->assertSame($effects, $this->taxBusinessState());
        $this->taxRecoverAndPost($id, 'joint-vector-post');
        $this->taxAssertNatureInvariants();
    }

    private function taxNatureConfiguration(): array
    {
        $nature = ErpOperationNature::findOrFail($this->natureId);

        return ['root' => $nature->getAttributes(), 'choices' => $nature->cfopChoices()->orderBy('id')->get()->toJson(),
            'exceptions' => $nature->productExceptions()->orderBy('id')->get()->toJson()];
    }
}
