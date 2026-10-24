<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpOfficeDelegation;
use App\Models\Erp\Sales\ErpQuote;
use App\Models\Erp\Sales\ErpSale;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsReviewedCommercialTax;
use Tests\Support\ErpCommercialTestCase;

class ErpCommercialTaxRefreshTest extends ErpCommercialTestCase
{
    use BuildsReviewedCommercialTax;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureReviewedCommercialTax();
        $this->withCredentials()->withCookie(config('session.cookie'), $this->app['session']->getId());
    }

    private function preview(string $plural, int $id, int $revision, array $extra = []): array
    {
        $response = $this->postJson('/api/v1/erp/'.$plural.'/'.$id.'/tax-refresh-preview', ['draft_revision' => $revision, ...$extra]);
        $this->assertSame(200, $response->status(), $response->getContent());

        return $response->json('data');
    }

    private function applyReview(string $plural, int $id, int $revision, array $preview, string $key, array $extra = []): TestResponse
    {
        return $this->patchJson('/api/v1/erp/'.$plural.'/'.$id, [
            'draft_revision' => $revision, 'tax_refresh' => ['reviewed_fingerprint' => $preview['reviewed_fingerprint'], ...$extra],
        ], ['Idempotency-Key' => $key]);
    }

    public function test_preview_exposes_old_and_candidate_facts_without_persisting_or_repricing(): void
    {
        $id = $this->send('sales', $this->reviewedBody())->assertCreated()->json('data.id');
        $before = $this->commercialDetail('sales', $id);
        $this->updateTaxProfile();
        $this->variant->update(['sale_price' => '999.0000']);
        $receipts = DB::table('erp_operation_receipts')->count();
        $preview = $this->preview('sales', $id, 1);
        $this->assertSame($before['lines'][0]['id'], $preview['lines'][0]['id']);
        $this->assertSame('01', $preview['lines'][0]['previous_product_snapshot']['product']['pis_cst']);
        $this->assertSame('02', $preview['lines'][0]['product_snapshot']['product']['pis_cst']);
        $this->assertSame($before['lines'][0]['product_snapshot']['pricing'], $preview['lines'][0]['product_snapshot']['pricing']);
        $this->assertSame($before, $this->commercialDetail('sales', $id));
        $this->assertSame($receipts, DB::table('erp_operation_receipts')->count());
        $this->assertNoCommercialEffects();
    }

    public function test_reviewed_refresh_changes_only_classifications_and_equal_retry_reuses_existing_update_receipt(): void
    {
        $id = $this->send('sales', $this->reviewedBody())->assertCreated()->json('data.id');
        $before = $this->commercialDetail('sales', $id);
        $this->updateTaxProfile();
        $this->deleteJson('/api/v1/erp/tax-settings/operation-natures/'.$this->natureId.'/product-exceptions/'.$this->exceptionId, ['expected_revision' => 3])->assertOk();
        $this->variant->update(['sale_price' => '999.0000']);
        $preview = $this->preview('sales', $id, 1);
        $response = $this->applyReview('sales', $id, 1, $preview, 'review-tax-once');
        $this->assertSame(200, $response->status(), $response->getContent());
        $response->assertJsonPath('data.draft_revision', 2)->assertJsonPath('data.total_amount', '20.9000');
        $after = $this->commercialDetail('sales', $id);
        $this->assertSame($before['invoice_no'], $after['invoice_no']);
        foreach (['unit_price', 'gross_amount', 'discount_amount', 'tax_amount', 'line_total', 'quantity', 'base_quantity'] as $field) {
            $this->assertSame($before['lines'][0][$field], $after['lines'][0][$field]);
        }
        foreach (['pricing', 'amounts', 'tax_calculation', 'tax_rate'] as $field) {
            $this->assertSame($before['lines'][0]['product_snapshot'][$field], $after['lines'][0]['product_snapshot'][$field]);
        }
        $this->assertSame('02', $after['lines'][0]['product_snapshot']['product']['pis_cst']);
        $this->assertSame('5102', $after['lines'][0]['product_snapshot']['operation_nature']['cfop']);
        $this->applyReview('sales', $id, 1, $preview, 'review-tax-once')->assertOk()->assertExactJson($response->json());
        $this->assertSame($after, $this->commercialDetail('sales', $id));
        $this->getJson('/api/v1/erp/operations/lookup?operation=erp.sales.update', ['Idempotency-Key' => 'review-tax-once'])->assertOk()->assertJsonPath('data.state', 'completed')->assertJsonPath('data.result.id', $id);
        $this->assertSame(1, DB::table('erp_operation_receipts')->where('operation', 'erp.sales.update')->count());
        $this->assertNoCommercialEffects();
    }

    public function test_profile_changes_after_preview_return_stale_review_409_before_any_mutation(): void
    {
        $id = $this->send('quotes', $this->reviewedBody())->assertCreated()->json('data.id');
        $before = $this->commercialDetail('quotes', $id);
        $preview = $this->preview('quotes', $id, 1);
        $this->updateTaxProfile();
        $this->applyReview('quotes', $id, 1, $preview, 'stale-profile-review')->assertConflict();
        $this->assertSame($before, $this->commercialDetail('quotes', $id));
        $this->assertSame(0, DB::table('erp_operation_receipts')->where('operation', 'erp.quotes.update')->count());
        $this->assertNoCommercialEffects();
    }

    public function test_nature_exception_changes_after_preview_return_stale_review_without_substituting_the_new_default(): void
    {
        $id = $this->send('orders', $this->reviewedBody())->assertCreated()->json('data.id');
        $before = $this->commercialDetail('orders', $id);
        $preview = $this->preview('orders', $id, 1);
        $this->deleteJson('/api/v1/erp/tax-settings/operation-natures/'.$this->natureId.'/product-exceptions/'.$this->exceptionId, ['expected_revision' => 3])->assertOk();
        $this->applyReview('orders', $id, 1, $preview, 'stale-nature-review')->assertConflict();
        $this->assertSame($before, $this->commercialDetail('orders', $id));
        $this->assertNoCommercialEffects();
    }

    public function test_archived_nature_after_preview_is_a_stale_review_without_mutations(): void
    {
        $id = $this->send('quotes', $this->reviewedBody())->assertCreated()->json('data.id');
        $before = $this->commercialDetail('quotes', $id);
        $preview = $this->preview('quotes', $id, 1);
        $this->deleteJson('/api/v1/erp/tax-settings/operation-natures/'.$this->natureId, ['expected_revision' => 3])->assertOk();
        $this->applyReview('quotes', $id, 1, $preview, 'archived-nature-review')->assertConflict();
        $this->assertSame($before, $this->commercialDetail('quotes', $id));
        $this->assertSame(0, DB::table('erp_operation_receipts')->where('operation', 'erp.quotes.update')->count());
        $this->assertNoCommercialEffects();
    }

    public function test_another_authenticated_session_cannot_apply_the_reviewed_fingerprint(): void
    {
        $id = $this->send('quotes', $this->reviewedBody())->assertCreated()->json('data.id');
        $preview = $this->preview('quotes', $id, 1);
        $this->app['session']->regenerate();
        $this->app['session']->save();
        $this->withCookie(config('session.cookie'), $this->app['session']->getId());
        $this->withHeader('X-CSRF-Token', $this->app['session']->token());
        $this->applyReview('quotes', $id, 1, $preview, 'another-session-review')->assertConflict();
        $this->assertSame(1, ErpQuote::findOrFail($id)->draft_revision);
        $this->assertNoCommercialEffects();
    }

    public function test_preview_rejects_unowned_duplicate_or_unsupported_input_without_side_effects(): void
    {
        $id = $this->send('quotes', $this->reviewedBody())->assertCreated()->json('data.id');
        $before = $this->commercialDetail('quotes', $id);
        $line = $before['lines'][0]['id'];
        foreach ([
            [['line_ids' => [999999]], 'line_ids'],
            [['line_ids' => [$line, $line]], 'line_ids.0'],
            [['lines' => [['id' => 999999, 'cfop' => null]]], 'lines.0.id'],
            [['tax_context' => ['fiscal_intent' => '55']], 'tax_context'],
            [['unit_price' => '0.0000'], 'operation'],
        ] as [$extra, $field]) {
            $this->postJson('/api/v1/erp/quotes/'.$id.'/tax-refresh-preview', ['draft_revision' => 1, ...$extra])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertSame($before, $this->commercialDetail('quotes', $id));
        $this->assertNoCommercialEffects();
    }

    public function test_delegated_preview_uses_exact_existing_update_grant_and_current_office_write_role(): void
    {
        $id = $this->send('quotes', $this->reviewedBody(['lines' => [$this->reviewedLine(['discount_amount' => '0.0000'])]]))->assertCreated()->json('data.id');
        $office = Account::factory()->create();
        $officeMember = AccountMembership::factory()->create(['account_id' => $office->id, 'user_id' => $this->user->id, 'role' => 'admin']);
        ErpCompanyMembership::where('user_id', $this->user->id)->delete();
        $delegation = ErpOfficeDelegation::factory()->create(['erp_company_id' => $this->company->id, 'account_id' => $office->id, 'permission_allowlist' => ['erp.quotes.update']]);
        $this->postJson('/api/v1/login', ['email' => $this->user->email, 'password' => 'password'])->assertOk();
        $this->withCookie(config('session.cookie'), $this->app['session']->getId());
        $this->withHeader('X-CSRF-Token', $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'));
        $this->postJson('/api/v1/erp/company/switch', ['company_id' => $this->company->id])->assertOk();
        $this->withHeader('X-CSRF-Token', $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'));
        $preview = $this->preview('quotes', $id, 1);
        $this->applyReview('quotes', $id, 1, $preview, 'delegated-reviewed-refresh')->assertOk();
        $delegation->update(['permission_allowlist' => []]);
        $this->postJson('/api/v1/erp/quotes/'.$id.'/tax-refresh-preview', ['draft_revision' => 2])->assertForbidden();
        $delegation->update(['permission_allowlist' => ['erp.quotes.update']]);
        $officeMember->update(['role' => 'user']);
        $this->postJson('/api/v1/erp/quotes/'.$id.'/tax-refresh-preview', ['draft_revision' => 2])->assertForbidden();
        $this->applyReview('quotes', $id, 1, $preview, 'delegated-reviewed-refresh')->assertForbidden();
        $this->assertSame(2, ErpQuote::findOrFail($id)->draft_revision);
        $this->assertNoCommercialEffects();
    }

    public function test_review_token_cannot_be_reused_for_another_document_or_changed_context(): void
    {
        $first = $this->send('quotes', $this->reviewedBody())->assertCreated()->json('data.id');
        $second = $this->send('quotes', $this->reviewedBody())->assertCreated()->json('data.id');
        $preview = $this->preview('quotes', $first, 1);
        $this->applyReview('quotes', $second, 1, $preview, 'wrong-document-review')->assertConflict();
        $this->applyReview('quotes', $first, 1, $preview, 'wrong-context-review', ['tax_context' => $this->classificationContext(['destination_uf' => 'RJ'])])->assertConflict();
        $this->assertSame(1, ErpQuote::findOrFail($first)->draft_revision);
        $this->assertSame(1, ErpQuote::findOrFail($second)->draft_revision);
    }

    public function test_targeted_refresh_preserves_unselected_line_and_clears_selected_explicit_cfop_only_after_review(): void
    {
        $id = $this->send('quotes', $this->reviewedBody(['lines' => [$this->reviewedLine(['cfop' => '5102']), $this->reviewedLine()]]))->assertCreated()->json('data.id');
        $before = $this->commercialDetail('quotes', $id)['lines'];
        $this->updateTaxProfile();
        $selection = ['line_ids' => [$before[0]['id']], 'lines' => [['id' => $before[0]['id'], 'cfop' => null]]];
        $preview = $this->preview('quotes', $id, 1, $selection);
        $this->applyReview('quotes', $id, 1, $preview, 'targeted-tax-review', $selection)->assertOk();
        $after = $this->commercialDetail('quotes', $id)['lines'];
        $this->assertSame('02', $after[0]['product_snapshot']['product']['pis_cst']);
        $this->assertSame('5101', $after[0]['product_snapshot']['operation_nature']['cfop']);
        $this->assertSame($before[1]['product_snapshot'], $after[1]['product_snapshot']);
    }

    public function test_preview_and_apply_require_existing_source_update_authority_and_current_role(): void
    {
        $id = $this->send('sales', $this->reviewedBody())->assertCreated()->json('data.id');
        $membership = ErpCompanyMembership::query()->where('erp_company_id', $this->company->id)->where('user_id', $this->user->id)->firstOrFail();
        $membership->update(['role' => 'operator', 'action_permissions' => ['erp.sales.update']]);
        $preview = $this->preview('sales', $id, 1);
        $this->postJson('/api/v1/erp/tax-settings/product-tax-profiles', ['name' => 'Outside grant'])->assertForbidden();
        $membership->update(['role' => 'viewer', 'action_permissions' => ['*']]);
        $this->postJson('/api/v1/erp/sales/'.$id.'/tax-refresh-preview', ['draft_revision' => 1])->assertForbidden();
        $this->applyReview('sales', $id, 1, $preview, 'revoked-review')->assertForbidden();
        $this->assertSame(1, ErpSale::findOrFail($id)->draft_revision);
        $this->assertNoCommercialEffects();
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function manualMoneyOverrides(): iterable
    {
        yield 'manual price requires its explicit capability' => [['unit_price' => '9.0000', 'discount_amount' => '0.0000'], 'lines.0.unit_price'];
        yield 'manual discount requires its explicit capability' => [['discount_amount' => '1.0000'], 'lines.0.discount_amount'];
    }

    #[DataProvider('manualMoneyOverrides')]
    public function test_classification_preview_does_not_grant_manual_money_authority_for_refresh_apply(array $lineChanges, string $field): void
    {
        $id = $this->send('sales', $this->reviewedBody(['lines' => [$this->reviewedLine($lineChanges)]]))->assertCreated()->json('data.id');
        $sale = ErpSale::findOrFail($id);
        $before = $sale->getAttributes();
        $beforeLines = $sale->lines()->get()->map(fn ($line): array => $line->getAttributes())->all();
        $receipts = DB::table('erp_operation_receipts')->count();
        $membership = ErpCompanyMembership::query()->where('erp_company_id', $this->company->id)->where('user_id', $this->user->id)->firstOrFail();
        $membership->update(['role' => 'operator', 'action_permissions' => ['erp.sales.update']]);
        $preview = $this->preview('sales', $id, 1);
        $this->applyReview('sales', $id, 1, $preview, 'money-fence-'.$field)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, $sale->fresh()->getAttributes());
        $this->assertSame($beforeLines, $sale->lines()->get()->map(fn ($line): array => $line->getAttributes())->all());
        $this->assertSame($receipts, DB::table('erp_operation_receipts')->count());
        $this->assertSame(['erp.sales.update'], $membership->fresh()->action_permissions);
        $this->assertNoCommercialEffects();
    }

    public function test_refresh_rejects_consumed_sources_and_does_not_change_the_converted_source(): void
    {
        $id = $this->send('quotes', $this->reviewedBody())->assertCreated()->json('data.id');
        $preview = $this->preview('quotes', $id, 1);
        $this->send('quotes/'.$id.'/convert', ['draft_revision' => 1])->assertOk();
        $before = ErpQuote::findOrFail($id)->getAttributes();
        $this->postJson('/api/v1/erp/quotes/'.$id.'/tax-refresh-preview', ['draft_revision' => 1])->assertConflict();
        $this->applyReview('quotes', $id, 1, $preview, 'converted-review')->assertConflict();
        $this->assertSame($before, ErpQuote::findOrFail($id)->getAttributes());
    }

    public function test_company_default_change_invalidates_review_and_explicit_refresh_adopts_only_the_new_default(): void
    {
        $this->patchJson('/api/v1/erp/company/settings', ['default_product_tax_profile_id' => $this->taxProfileId])->assertOk();
        $this->patchJson('/api/v1/erp/products/'.$this->variant->erp_product_id, ['erp_product_tax_profile_id' => null])->assertOk();
        $id = $this->send('quotes', $this->reviewedBody())->assertCreated()->json('data.id');
        $before = $this->commercialDetail('quotes', $id);
        $this->assertSame('company_profile', $before['lines'][0]['product_snapshot']['product_tax_resolution']['sources']['pis_cst']['source']);
        $preview = $this->preview('quotes', $id, 1);
        $replacement = $this->postJson('/api/v1/erp/tax-settings/product-tax-profiles', ['name' => 'Outro padrão revisado', 'fields' => ['pis_cst' => '03']])->assertCreated()->json('data.id');
        $this->patchJson('/api/v1/erp/company/settings', ['default_product_tax_profile_id' => $replacement])->assertOk();
        $this->applyReview('quotes', $id, 1, $preview, 'stale-default-review')->assertConflict();
        $this->assertSame($before, $this->commercialDetail('quotes', $id));
        $fresh = $this->preview('quotes', $id, 1);
        $this->applyReview('quotes', $id, 1, $fresh, 'fresh-default-review')->assertOk();
        $this->assertSame('03', $this->commercialDetail('quotes', $id)['lines'][0]['product_snapshot']['product']['pis_cst']);
    }

    public function test_archived_profile_preview_errors_preserve_the_draft_and_do_not_substitute_company_values(): void
    {
        $id = $this->send('quotes', $this->reviewedBody())->assertCreated()->json('data.id');
        $before = $this->commercialDetail('quotes', $id);
        $this->deleteJson('/api/v1/erp/tax-settings/product-tax-profiles/'.$this->taxProfileId)->assertOk();
        $this->postJson('/api/v1/erp/quotes/'.$id.'/tax-refresh-preview', ['draft_revision' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('lines.0.erp_product_tax_profile_id');
        $this->assertSame($before, $this->commercialDetail('quotes', $id));
        $this->assertNoCommercialEffects();
    }

    public function test_product_intent_change_after_preview_invalidates_proof_even_if_profile_revision_is_unchanged(): void
    {
        $id = $this->send('quotes', $this->reviewedBody())->assertCreated()->json('data.id');
        $before = $this->commercialDetail('quotes', $id);
        $preview = $this->preview('quotes', $id, 1);
        $this->patchJson('/api/v1/erp/products/'.$this->variant->erp_product_id, ['pis_cst' => '04'])->assertOk();
        $this->applyReview('quotes', $id, 1, $preview, 'stale-product-intent')->assertConflict();
        $this->assertSame($before, $this->commercialDetail('quotes', $id));
    }

    public function test_legacy_classification_is_adopted_only_by_explicit_review_and_never_retroactively_rewritten(): void
    {
        $id = $this->send('quotes', $this->reviewedBody())->assertCreated()->json('data.id');
        $this->restoreLegacySnapshotShape('quote', $id);
        $old = $this->commercialDetail('quotes', $id);
        $this->assertArrayNotHasKey('product_tax_resolution', $old['lines'][0]['product_snapshot']);
        $preview = $this->preview('quotes', $id, 1, ['tax_context' => $this->classificationContext()]);
        $this->assertSame($old, $this->commercialDetail('quotes', $id));
        $this->applyReview('quotes', $id, 1, $preview, 'adopt-legacy-reviewed', ['tax_context' => $this->classificationContext()])->assertOk();
        $new = $this->commercialDetail('quotes', $id);
        $this->assertSame('commercial-v2', $new['lines'][0]['product_snapshot']['version']);
        $this->assertSame('01', $new['lines'][0]['product_snapshot']['product']['pis_cst']);
        $this->assertSame('5101', $new['lines'][0]['product_snapshot']['operation_nature']['cfop']);
        $this->assertSame($old['total_amount'], $new['total_amount']);
        $this->assertNoCommercialEffects();
    }

    public function test_apply_rechecks_authority_after_request_context_resolution_before_refresh_effects(): void
    {
        $id = $this->send('quotes', $this->reviewedBody())->assertCreated()->json('data.id');
        $before = $this->commercialDetail('quotes', $id);
        $preview = $this->preview('quotes', $id, 1);
        $membership = ErpCompanyMembership::query()->where('erp_company_id', $this->company->id)->where('user_id', $this->user->id)->firstOrFail();
        $triggered = false;
        $event = 'eloquent.retrieved: '.ErpCompany::class;
        Event::listen($event, function () use ($membership, &$triggered): void {
            if ($triggered || ! request()->attributes->get(CurrentCompany::class) instanceof CurrentCompany) {
                return;
            }
            $triggered = true;
            $membership->update(['role' => 'viewer']);
        });
        try {
            $this->applyReview('quotes', $id, 1, $preview, 'late-revoked-review')->assertForbidden();
        } finally {
            Event::forget($event);
        }
        $this->assertTrue($triggered);
        $this->assertSame($before, $this->commercialDetail('quotes', $id));
        $this->assertSame(0, DB::table('erp_operation_receipts')->where('operation', 'erp.quotes.update')->count());
    }
}
