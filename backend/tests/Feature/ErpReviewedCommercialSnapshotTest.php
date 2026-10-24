<?php

namespace Tests\Feature;

use App\Erp\Sales\ErpCommercialDraftService;
use App\Erp\Sales\ErpSaleRepresentation;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpCompanySetupState;
use App\Models\Erp\Sales\ErpInvoiceLayout;
use App\Models\Erp\Sales\ErpQuote;
use App\Models\Erp\Sales\ErpSale;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsReviewedCommercialTax;
use Tests\Support\ErpCommercialTestCase;

class ErpReviewedCommercialSnapshotTest extends ErpCommercialTestCase
{
    use BuildsReviewedCommercialTax;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureReviewedCommercialTax();
    }

    /** @return iterable<string, array{string}> */
    public static function documentKinds(): iterable
    {
        yield 'quote' => ['quotes'];
        yield 'order' => ['orders'];
        yield 'sale' => ['sales'];
    }

    #[DataProvider('documentKinds')]
    public function test_new_snapshot_freezes_tax_and_effective_cfop_provenance_without_stacking_commercial_tax(string $plural): void
    {
        $id = $this->send($plural, $this->reviewedBody(['expected_total' => '20.9000']))->assertCreated()
            ->assertJsonPath('data.total_amount', '20.9000')->json('data.id');
        $detail = $this->commercialDetail($plural, $id);
        $snapshot = $detail['lines'][0]['product_snapshot'];
        $this->assertSame('commercial-v2', $detail['commercial_snapshot']['version']);
        $this->assertSame('commercial-v2', $snapshot['version']);
        $this->assertSame('01', $snapshot['product']['pis_cst']);
        $this->assertSame('0.0000', $snapshot['product']['pis_rate']);
        $this->assertSame('18.0000', $snapshot['product']['icms_rate']);
        $this->assertSame('profile', $snapshot['product_tax_resolution']['sources']['pis_cst']['source']);
        $this->assertSame($this->taxProfileId, $snapshot['product_tax_resolution']['profile']['profile_id']);
        $this->assertSame(1, $snapshot['product_tax_resolution']['profile']['revision']);
        $this->assertSame('5101', $snapshot['operation_nature']['cfop']);
        $this->assertSame('5102', $snapshot['operation_nature']['default_cfop']);
        $this->assertSame('product_exception', $snapshot['cfop_resolution']['source']);
        $this->assertSame($this->exceptionId, $snapshot['cfop_resolution']['exception_id']);
        $this->assertSame(3, $snapshot['cfop_resolution']['configuration_revision']);
        $this->assertSame('1.9000', $detail['lines'][0]['tax_amount']);
        $this->assertSame($this->commercialTaxRate->id, $snapshot['tax_rate']['id']);
        $this->assertSame('selected_commercial_rate', $snapshot['tax_calculation']['source']);
        $this->assertSame('unverified', $detail['commercial_snapshot']['fiscal_context']['readiness']);
        $this->assertNoCommercialEffects();
    }

    #[DataProvider('documentKinds')]
    public function test_explicit_existing_line_collection_retains_classification_when_catalog_revisions_change(string $plural): void
    {
        $id = $this->send($plural, $this->reviewedBody())->assertCreated()->json('data.id');
        $before = $this->commercialDetail($plural, $id);
        $old = $before['lines'][0];
        $this->updateTaxProfile();
        $this->deleteJson('/api/v1/erp/tax-settings/operation-natures/'.$this->natureId.'/product-exceptions/'.$this->exceptionId, ['expected_revision' => 3])->assertOk();
        $this->patchJson('/api/v1/erp/'.$plural.'/'.$id, [
            'draft_revision' => 1, 'lines' => [$this->reviewedLine(['id' => $old['id'], 'description' => 'Descrição revisada'])],
        ], ['Idempotency-Key' => 'retained-'.$plural])->assertOk()->assertJsonPath('data.total_amount', '20.9000');
        $after = $this->commercialDetail($plural, $id)['lines'][0]['product_snapshot'];
        foreach (['product_tax_resolution', 'cfop_resolution', 'operation_nature', 'product'] as $field) {
            $this->assertSame($old['product_snapshot'][$field], $after[$field]);
        }
        $this->assertSame('Descrição revisada', $after['description']);
        $this->assertNoCommercialEffects();
    }

    public function test_reordered_and_added_lines_keep_old_id_facts_and_resolve_only_new_lines(): void
    {
        $other = $this->secondVariant();
        $id = $this->send('quotes', $this->reviewedBody(['lines' => [$this->reviewedLine(), $this->reviewedLine(['erp_product_variant_id' => $other->id])]]))->assertCreated()->json('data.id');
        $before = $this->commercialDetail('quotes', $id)['lines'];
        $this->updateTaxProfile();
        $this->patchJson('/api/v1/erp/quotes/'.$id, ['draft_revision' => 1, 'lines' => [
            $this->reviewedLine(['id' => $before[1]['id'], 'erp_product_variant_id' => $other->id]),
            $this->reviewedLine(['id' => $before[0]['id']]),
            $this->reviewedLine(),
        ]], ['Idempotency-Key' => 'reorder-and-add'])->assertOk();
        $after = $this->commercialDetail('quotes', $id)['lines'];
        $this->assertSame($before[1]['id'], $after[0]['id']);
        $this->assertSame($before[0]['id'], $after[1]['id']);
        $this->assertSame($before[1]['product_snapshot']['product_tax_resolution'], $after[0]['product_snapshot']['product_tax_resolution']);
        $this->assertSame($before[0]['product_snapshot']['product_tax_resolution'], $after[1]['product_snapshot']['product_tax_resolution']);
        $this->assertSame(2, $after[2]['product_snapshot']['product_tax_resolution']['profile']['revision']);
        $this->assertSame('02', $after[2]['product_snapshot']['product']['pis_cst']);
    }

    public function test_duplicate_or_foreign_line_id_is_rejected_atomically_before_classification_changes(): void
    {
        $id = $this->send('quotes', $this->reviewedBody())->assertCreated()->json('data.id');
        $before = $this->commercialDetail('quotes', $id);
        $line = $this->reviewedLine(['id' => $before['lines'][0]['id']]);
        foreach ([[$line, $line], [$this->reviewedLine(['id' => 999999])]] as $index => $lines) {
            $this->patchJson('/api/v1/erp/quotes/'.$id, ['lines' => $lines], ['Idempotency-Key' => 'bad-line-'.$index])->assertUnprocessable()->assertJsonValidationErrors('lines.'.($index === 0 ? '1' : '0').'.id');
            $this->assertSame($before, $this->commercialDetail('quotes', $id));
        }
    }

    public function test_cfop_omission_retains_explicit_choice_and_null_clears_it_for_fresh_review(): void
    {
        $id = $this->send('quotes', $this->reviewedBody(['lines' => [$this->reviewedLine(['cfop' => '5102'])]]))->assertCreated()->json('data.id');
        $line = $this->commercialDetail('quotes', $id)['lines'][0];
        $this->assertSame('explicit', $line['product_snapshot']['cfop_resolution']['source']);
        $this->patchJson('/api/v1/erp/quotes/'.$id, ['draft_revision' => 1, 'lines' => [$this->reviewedLine(['id' => $line['id']])]], ['Idempotency-Key' => 'choice-omitted'])->assertOk();
        $this->assertSame($line['product_snapshot']['cfop_resolution'], $this->commercialDetail('quotes', $id)['lines'][0]['product_snapshot']['cfop_resolution']);
        $this->patchJson('/api/v1/erp/quotes/'.$id, ['draft_revision' => 2, 'lines' => [$this->reviewedLine(['id' => $line['id'], 'cfop' => null])]], ['Idempotency-Key' => 'choice-cleared'])->assertOk();
        $after = $this->commercialDetail('quotes', $id)['lines'][0]['product_snapshot'];
        $this->assertSame('5101', $after['operation_nature']['cfop']);
        $this->assertSame('product_exception', $after['cfop_resolution']['source']);
        $this->assertSame('20.9000', $this->commercialDetail('quotes', $id)['total_amount']);
    }

    public function test_product_replacement_resolves_current_revision_without_changing_retained_neighbor(): void
    {
        $id = $this->send('orders', $this->reviewedBody(['lines' => [$this->reviewedLine(), $this->reviewedLine()]]))->assertCreated()->json('data.id');
        $before = $this->commercialDetail('orders', $id)['lines'];
        $this->updateTaxProfile();
        $replacement = $this->secondVariant();
        $this->patchJson('/api/v1/erp/orders/'.$id, ['draft_revision' => 1, 'lines' => [
            $this->reviewedLine(['id' => $before[0]['id'], 'erp_product_variant_id' => $replacement->id]),
            $this->reviewedLine(['id' => $before[1]['id']]),
        ]], ['Idempotency-Key' => 'replace-variant'])->assertOk();
        $after = $this->commercialDetail('orders', $id)['lines'];
        $this->assertSame(2, $after[0]['product_snapshot']['product_tax_resolution']['profile']['revision']);
        $this->assertSame($before[1]['product_snapshot']['product_tax_resolution'], $after[1]['product_snapshot']['product_tax_resolution']);
    }

    public function test_quote_order_sale_conversion_copies_frozen_classifications_and_totals_after_configuration_changes(): void
    {
        $quote = $this->send('quotes', $this->reviewedBody())->assertCreated()->json('data.id');
        $original = $this->commercialDetail('quotes', $quote)['lines'][0];
        $this->updateTaxProfile();
        $this->deleteJson('/api/v1/erp/tax-settings/operation-natures/'.$this->natureId.'/product-exceptions/'.$this->exceptionId, ['expected_revision' => 3])->assertOk();
        $this->variant->update(['sale_price' => '999.0000']);
        $order = $this->send('quotes/'.$quote.'/convert', ['draft_revision' => 1])->assertOk()->assertJsonPath('data.order.total_amount', '20.9000')->json('data.order.id');
        $orderLine = $this->commercialDetail('orders', $order)['lines'][0];
        $this->assertSame($original['product_snapshot'], $orderLine['product_snapshot']);
        $sale = $this->send('orders/'.$order.'/convert', ['draft_revision' => 1])->assertOk()->assertJsonPath('data.sale.total_amount', '20.9000')->json('data.sale.id');
        $saleLine = $this->commercialDetail('sales', $sale)['lines'][0];
        $this->assertSame($original['product_snapshot'], $saleLine['product_snapshot']);
        $this->assertSame($original['id'], $saleLine['source_quote_line_id']);
        $this->assertSame($orderLine['id'], $saleLine['source_order_line_id']);
        $this->assertNoCommercialEffects();
    }

    public function test_profile_classification_does_not_grant_manual_price_or_discount_authority(): void
    {
        ErpCompanyMembership::query()->where('user_id', $this->user->id)->update(['action_permissions' => ['erp.quotes.store']]);
        $this->send('quotes', $this->reviewedBody(['lines' => [$this->reviewedLine(['unit_price' => '9.0000'])]]))
            ->assertUnprocessable()->assertJsonValidationErrors('lines.0.unit_price');
        $this->send('quotes', $this->reviewedBody())
            ->assertUnprocessable()->assertJsonValidationErrors('lines.0.discount_amount');
        $this->assertDatabaseCount('erp_quotes', 0);
        $this->assertNoCommercialEffects();
    }

    public function test_missing_context_and_basic_product_remain_unresolved_without_fiscal_intent_or_fabricated_cfop(): void
    {
        $this->patchJson('/api/v1/erp/products/'.$this->variant->erp_product_id, [
            'erp_product_tax_profile_id' => null, 'tax_field_intents' => ['icms_cst' => 'unset', 'pis_cst' => 'unset', 'pis_rate' => 'unset', 'icms_rate' => 'unset'],
        ])->assertOk();
        $id = $this->send('sales', $this->reviewedBody(['tax_context' => []]))->assertCreated()->json('data.id');
        $detail = $this->commercialDetail('sales', $id);
        $snapshot = $detail['lines'][0]['product_snapshot'];
        $this->assertNull($snapshot['product']['pis_cst']);
        $this->assertNull($snapshot['operation_nature']['cfop']);
        $this->assertSame('5102', $snapshot['operation_nature']['default_cfop']);
        $this->assertNull($snapshot['cfop_resolution']['effective_cfop']);
        $this->assertContains('context.model', $snapshot['cfop_resolution']['unresolved_fields']);
        $this->assertSame('unverified', $detail['commercial_snapshot']['fiscal_context']['readiness']);
        $this->assertSame('20.9000', $detail['total_amount']);
        $this->assertNoCommercialEffects();
    }

    public function test_invalid_supplied_line_choice_is_rejected_without_a_default_substitution_or_saved_operation(): void
    {
        foreach (['6102', 'invalid-code', 5102] as $cfop) {
            $this->send('quotes', $this->reviewedBody(['lines' => [$this->reviewedLine(['cfop' => $cfop])]]))
                ->assertUnprocessable()->assertJsonValidationErrors('lines.0.cfop');
        }
        $this->assertDatabaseCount('erp_quotes', 0);
        $this->assertNoCommercialEffects();
    }

    public function test_legacy_line_edit_does_not_adopt_profile_or_rule_provenance_and_new_line_keeps_its_own_version(): void
    {
        $id = $this->send('quotes', $this->reviewedBody())->assertCreated()->json('data.id');
        $this->restoreLegacySnapshotShape('quote', $id);
        $old = $this->commercialDetail('quotes', $id)['lines'][0];
        $this->updateTaxProfile();
        $this->patchJson('/api/v1/erp/quotes/'.$id, ['draft_revision' => 1, 'lines' => [$this->reviewedLine(['id' => $old['id']])]], ['Idempotency-Key' => 'legacy-unchanged-lines'])->assertOk();
        $this->assertSame($old['product_snapshot'], $this->commercialDetail('quotes', $id)['lines'][0]['product_snapshot']);
        $this->patchJson('/api/v1/erp/quotes/'.$id, ['draft_revision' => 2, 'lines' => [$this->reviewedLine(['id' => $old['id']]), $this->reviewedLine()]], ['Idempotency-Key' => 'legacy-and-new-line'])->assertOk();
        $mixed = $this->commercialDetail('quotes', $id);
        $this->assertSame('commercial-v2', $mixed['commercial_snapshot']['version']);
        $this->assertSame($old['product_snapshot'], $mixed['lines'][0]['product_snapshot']);
        $this->assertSame('commercial-v2', $mixed['lines'][1]['product_snapshot']['version']);
        $this->assertSame('02', $mixed['lines'][1]['product_snapshot']['product']['pis_cst']);
    }

    public function test_saved_context_change_resolves_new_reviewable_facts_without_reclassifying_a_neighbor_by_catalog_revision_alone(): void
    {
        $id = $this->send('quotes', $this->reviewedBody())->assertCreated()->json('data.id');
        $old = $this->commercialDetail('quotes', $id)['lines'][0];
        $this->updateTaxProfile();
        $this->patchJson('/api/v1/erp/quotes/'.$id, [
            'draft_revision' => 1, 'tax_context' => $this->classificationContext(['model' => '65', 'final_consumer' => true, 'presence' => '1']),
            'lines' => [$this->reviewedLine(['id' => $old['id']])],
        ], ['Idempotency-Key' => 'new-reviewed-context'])->assertOk()->assertJsonPath('data.total_amount', '20.9000');
        $after = $this->commercialDetail('quotes', $id)['lines'][0]['product_snapshot'];
        $this->assertSame('02', $after['product']['pis_cst']);
        $this->assertSame('65', $after['cfop_resolution']['context']['model']);
        $this->assertSame('5101', $after['operation_nature']['cfop']);
        $this->assertNoCommercialEffects();
    }

    public function test_native_commercial_acceptance_and_representation_read_both_versions_without_rewriting_old_facts(): void
    {
        $layout = ErpInvoiceLayout::query()->create(['erp_company_id' => $this->company->id, 'name' => 'Modelo comercial', 'status' => 'active', 'layout_settings' => ErpSaleRepresentation::DEFAULT_SETTINGS]);
        ErpCompanySetupState::query()->create(['erp_company_id' => $this->company->id, 'preset_version' => 'br-basic-v1', 'revision' => 1, 'default_invoice_layout_id' => $layout->id]);
        $id = $this->send('sales', $this->reviewedBody(['currency' => 'BRL']))->assertCreated()->json('data.id');
        $this->assertSame('commercial-v2', $this->commercialDetail('sales', $id)['commercial_snapshot']['version']);
        $reader = app(ErpCommercialDraftService::class);
        $accepted = $reader->acceptedSnapshot(ErpSale::findOrFail($id), $this->user->id);
        $this->assertSame('commercial-v2', $accepted['version']);
        $this->assertSame(1, $accepted['accepted']['draft_revision']);
        $this->getJson('/api/v1/erp/sales/'.$id.'/representation')->assertOk()->assertSee('20,90');
        $this->restoreLegacySnapshotShape('sale', $id);
        $before = ErpSale::findOrFail($id)->getAttributes();
        $acceptedOld = $reader->acceptedSnapshot(ErpSale::findOrFail($id), $this->user->id);
        $this->assertSame('commercial-v1', $acceptedOld['version']);
        $this->assertArrayNotHasKey('product_tax_resolution', $acceptedOld['lines'][0]['product_snapshot']);
        $this->getJson('/api/v1/erp/sales/'.$id.'/representation')->assertOk()->assertSee('20,90');
        $this->assertSame($before, ErpSale::findOrFail($id)->getAttributes());
        $sale = ErpSale::findOrFail($id);
        $sale->update(['commercial_snapshot' => [...$sale->commercial_snapshot, 'version' => 'unknown-commercial-version']]);
        $unknown = $sale->fresh()->getAttributes();
        $this->getJson('/api/v1/erp/sales/'.$id.'/representation')->assertConflict();
        $this->assertSame($unknown, $sale->fresh()->getAttributes());
    }

    public function test_context_only_save_reclassifies_retained_lines_without_repricing_or_changing_money(): void
    {
        $id = $this->send('quotes', $this->reviewedBody())->assertCreated()->json('data.id');
        $before = $this->commercialDetail('quotes', $id)['lines'][0];
        $this->updateTaxProfile();
        $this->variant->update(['sale_price' => '999.0000']);
        $context = $this->classificationContext(['model' => '65', 'final_consumer' => true, 'presence' => '1']);
        $this->patchJson('/api/v1/erp/quotes/'.$id, ['draft_revision' => 1, 'tax_context' => $context], ['Idempotency-Key' => 'context-only-save'])
            ->assertOk()->assertJsonPath('data.total_amount', '20.9000');
        $after = $this->commercialDetail('quotes', $id)['lines'][0];
        $this->assertSame('02', $after['product_snapshot']['product']['pis_cst']);
        $this->assertSame('65', $after['product_snapshot']['cfop_resolution']['context']['model']);
        foreach (['unit_price', 'gross_amount', 'discount_amount', 'tax_amount', 'line_total', 'quantity', 'base_quantity'] as $field) {
            $this->assertSame($before[$field], $after[$field]);
        }
        $this->assertSame($before['product_snapshot']['pricing'], $after['product_snapshot']['pricing']);
        $this->assertNoCommercialEffects();
    }

    public function test_unchanged_or_invalid_context_only_save_never_silently_adopts_current_configuration(): void
    {
        $id = $this->send('quotes', $this->reviewedBody())->assertCreated()->json('data.id');
        $this->updateTaxProfile();
        $before = $this->commercialDetail('quotes', $id)['lines'][0]['product_snapshot'];
        $this->patchJson('/api/v1/erp/quotes/'.$id, ['draft_revision' => 1, 'tax_context' => $this->classificationContext()], ['Idempotency-Key' => 'unchanged-context'])
            ->assertOk();
        $this->assertSame($before, $this->commercialDetail('quotes', $id)['lines'][0]['product_snapshot']);
        $this->patchJson('/api/v1/erp/quotes/'.$id, ['draft_revision' => 2, 'tax_context' => ['model' => '57']], ['Idempotency-Key' => 'invalid-context'])
            ->assertUnprocessable()->assertJsonValidationErrors('tax_context.model');
        $this->assertSame(2, ErpQuote::findOrFail($id)->draft_revision);
        $this->assertNoCommercialEffects();
    }

    public function test_conversion_rejects_incoherent_versioned_facts_without_repair_or_child_creation(): void
    {
        $id = $this->send('quotes', $this->reviewedBody())->assertCreated()->json('data.id');
        $quote = ErpQuote::findOrFail($id);
        $snapshot = $quote->commercial_snapshot;
        $snapshot['lines'][0]['product_snapshot']['operation_nature']['cfop'] = '6102';
        $quote->update(['commercial_snapshot' => $snapshot]);
        $before = $quote->fresh()->getAttributes();
        $this->send('quotes/'.$id.'/convert', ['draft_revision' => 1], 'incoherent-source')->assertConflict();
        $this->assertSame($before, $quote->fresh()->getAttributes());
        $this->assertDatabaseCount('erp_orders', 0);
        $this->assertNoCommercialEffects();
    }
}
