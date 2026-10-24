<?php

namespace Tests\Feature;

use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpContact;
use App\Models\Erp\Sales\ErpSale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsReviewedCommercialTax;
use Tests\Support\ErpCommercialTestCase;

class ErpSaleFiscalReadinessTest extends ErpCommercialTestCase
{
    use BuildsReviewedCommercialTax;

    private array $issuerConfiguration = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials()->withCookie(config('session.cookie'), $this->app['session']->getId());
    }

    private function basicBody(array $extra = []): array
    {
        return $extra + ['erp_establishment_id' => $this->establishment->id,
            'lines' => [['description' => 'Serviço comercial', 'quantity' => '1', 'unit_price' => '10']]];
    }

    private function assertNoPostEffects(): void
    {
        foreach (['erp_stock_movements', 'erp_obligations', 'erp_payments', 'erp_fiscal_documents', 'erp_fiscal_attempts'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    private function readyBody(string $model = '55'): array
    {
        $this->configureReviewedCommercialTax();
        $this->patchJson('/api/v1/erp/tax-settings/product-tax-profiles/'.$this->taxProfileId, [
            'fields' => ['icms_cst' => '00', 'pis_cst' => '07', 'cofins_cst' => '07', 'icms_rate' => '18.0000'],
            'expected_revision' => 1,
        ])->assertOk();
        $this->patchJson('/api/v1/erp/products/'.$this->variant->erp_product_id, [
            'ncm' => '12345678', 'origin_code' => '0', 'sku' => 'READY-SKU', 'track_stock' => false,
            'tax_field_intents' => ['icms_cst' => 'inherit', 'pis_cst' => 'inherit', 'cofins_cst' => 'inherit', 'icms_rate' => 'inherit'],
        ])->assertOk();
        $this->variant->product->unit->update(['short_name' => 'UN']);
        $source = ErpFiscalModelBuilderSchemaTest::builderProvider()['NF-e 55 posted sale'][2];
        $customer = ErpContact::factory()->create([
            'erp_company_id' => $this->company->id, 'name' => $source['recipient']['legal_name'],
            'tax_id' => $source['recipient']['cnpj'], 'address' => $source['recipient']['address'], 'is_customer' => true,
        ]);
        $address = $source['issuer']['address'];
        $this->establishment->update(['cnpj' => $source['issuer']['cnpj']]);
        $this->issuerConfiguration = [
            'environment' => 'homologation',
            'issuer' => ['tax_regime' => 3, 'state_registration' => $source['issuer']['state_registration'], 'uf' => $address['uf'],
                'address' => ['street' => $address['street'], 'number' => $address['number'], 'district' => $address['district'],
                    'city_code' => $address['city_code'], 'city_name' => $address['city'], 'zip_code' => $address['postal_code']]],
            'models' => [$model => ['series' => 1, 'next_number' => 1, 'operation_nature_id' => $this->natureId,
                ...($model === '65' ? ['nfce_qr' => ['version' => 3, 'mode' => 'online', 'consultation_url' => 'https://test.invalid/consulta']] : [])]],
        ];
        $this->patchJson('/api/v1/erp/fiscal/configuration/'.$this->establishment->id, $this->issuerConfiguration)->assertOk();

        return $this->reviewedBody([
            'fiscal_model' => $model, 'erp_contact_id' => $customer->id,
            'tax_context' => $this->classificationContext(['model' => $model, 'final_consumer' => true, 'presence' => '1']),
            'lines' => [$this->reviewedLine(['quantity' => '1', 'unit_price' => '100', 'discount_amount' => '0', 'erp_tax_rate_id' => null])],
        ]);
    }

    public function test_basic_sale_omission_and_explicit_null_intent_keep_existing_non_fiscal_posting_contract(): void
    {
        foreach ([[], ['fiscal_model' => null]] as $intent) {
            $id = $this->send('sales', $this->basicBody($intent))->assertCreated()
                ->assertJsonStructure(['data' => ['fiscal_model']])->assertJsonPath('data.fiscal_model', null)->json('data.id');
            $snapshot = ErpSale::findOrFail($id)->commercial_snapshot;
            $this->assertNull($snapshot['fiscal_context']['model']);
            $this->assertNotSame('source_prerequisites_checked', $snapshot['fiscal_context']['readiness']);
            $this->send('sales/'.$id.'/post', ['draft_revision' => 1])->assertOk()->assertJsonPath('data.status', 'posted');
        }
        $this->assertDatabaseCount('erp_obligations', 2);
        $this->assertDatabaseCount('erp_fiscal_documents', 0);
        $this->assertDatabaseCount('erp_fiscal_attempts', 0);
    }

    public function test_additive_intent_field_leaves_a_legacy_posted_snapshot_unclassified_and_unchanged(): void
    {
        $this->assertTrue(Schema::hasColumn('erp_sales', 'fiscal_model'));
        $snapshot = ['version' => 'commercial-v1', 'party' => null, 'lines' => [],
            'fiscal_context' => ['model' => null, 'schema_version' => null, 'readiness' => 'unverified', 'issuer_resolution' => null]];
        $id = DB::table('erp_sales')->insertGetId([
            'erp_company_id' => $this->company->id, 'erp_establishment_id' => $this->establishment->id,
            'invoice_no' => 'HISTORICAL-1', 'status' => 'posted', 'fiscal_status' => 'none', 'currency' => 'BRL',
            'subtotal_amount' => '0', 'discount_amount' => '0', 'tax_amount' => '0', 'total_amount' => '0',
            'commercial_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
        ]);
        $sale = ErpSale::findOrFail($id);

        $this->assertNull($sale->fiscal_model);
        $this->assertSame($snapshot, $sale->commercial_snapshot);
        $this->assertSame('posted', $sale->status);
        $this->assertDatabaseCount('erp_fiscal_documents', 0);
    }

    public static function intentModels(): iterable
    {
        yield 'NF-e' => ['55'];
        yield 'NFC-e' => ['65'];
    }

    #[DataProvider('intentModels')]
    public function test_explicit_intent_is_saved_with_incomplete_draft_but_blocks_post_before_effects(string $model): void
    {
        $id = $this->send('sales', $this->basicBody(['fiscal_model' => $model]))->assertCreated()
            ->assertJsonPath('data.fiscal_model', $model)->json('data.id');
        $sale = ErpSale::findOrFail($id);
        $before = $sale->getAttributes();
        $sequences = DB::table('erp_commercial_number_sequences')->get()->toArray();
        $receipts = DB::table('erp_operation_receipts')->count();
        $this->assertSame($model, $sale->commercial_snapshot['fiscal_context']['model']);
        $this->assertSame($model, $sale->commercial_snapshot['document']['fiscal_model']);

        $this->send('sales/'.$id.'/post', ['draft_revision' => 1])->assertUnprocessable()
            ->assertJsonValidationErrors(['erp_operation_nature_id', 'erp_contact_id']);
        $this->assertSame($before, $sale->fresh()->getAttributes());
        $this->assertEquals($sequences, DB::table('erp_commercial_number_sequences')->get()->toArray());
        $this->assertSame($receipts, DB::table('erp_operation_receipts')->count());
        $this->assertNoPostEffects();
    }

    public function test_intent_omission_preserves_selection_and_explicit_null_clears_without_reclassifying_the_draft(): void
    {
        $id = $this->send('sales', $this->basicBody(['fiscal_model' => '55']))->assertCreated()->json('data.id');
        $sale = ErpSale::findOrFail($id);
        $lines = $sale->lines()->get()->toArray();
        $this->patchJson('/api/v1/erp/sales/'.$id, ['draft_revision' => 1], ['Idempotency-Key' => 'retain-intent'])
            ->assertOk()->assertJsonPath('data.fiscal_model', '55');
        $this->patchJson('/api/v1/erp/sales/'.$id, ['draft_revision' => 2, 'fiscal_model' => null], ['Idempotency-Key' => 'clear-intent'])
            ->assertOk()->assertJsonPath('data.fiscal_model', null);
        $this->assertSame($lines, $sale->fresh()->lines()->get()->toArray());
        $this->send('sales/'.$id.'/post', ['draft_revision' => 3])->assertOk()->assertJsonPath('data.status', 'posted');
        $this->assertDatabaseCount('erp_fiscal_documents', 0);
    }

    public static function invalidIntents(): iterable
    {
        foreach ([55, 65, false, '', '57', '58', '055', '55 '] as $value) {
            yield json_encode($value) => [$value];
        }
    }

    #[DataProvider('invalidIntents')]
    public function test_intent_is_nullable_exact_model_string_with_no_coercion_or_other_fiscal_models(mixed $value): void
    {
        $this->send('sales', $this->basicBody(['fiscal_model' => $value]))->assertUnprocessable()->assertJsonValidationErrors('fiscal_model');
        $this->assertDatabaseCount('erp_sales', 0);
        $this->assertDatabaseCount('erp_operation_receipts', 0);
        $this->assertDatabaseCount('erp_commercial_number_sequences', 0);
    }

    public function test_quotes_orders_and_returns_reject_the_sale_only_fiscal_intent_input(): void
    {
        foreach (['quotes', 'orders', 'sale-returns', 'purchase-returns'] as $plural) {
            $this->send($plural, ['fiscal_model' => '55'])->assertUnprocessable()->assertJsonValidationErrors('operation');
        }
        $this->assertDatabaseCount('erp_quotes', 0);
        $this->assertDatabaseCount('erp_orders', 0);
        $this->assertNoPostEffects();
    }

    public function test_profile_and_tax_context_presence_never_create_fiscal_intent_or_new_post_requirements(): void
    {
        $this->configureReviewedCommercialTax();
        $this->variant->product->update(['track_stock' => false]);
        $id = $this->send('sales', $this->reviewedBody())->assertCreated()
            ->assertJsonPath('data.fiscal_model', null)->json('data.id');
        $facts = ErpSale::findOrFail($id)->lines()->firstOrFail()->product_snapshot;
        $this->assertSame('commercial-v2', $facts['version']);
        $this->assertNotNull($facts['product_tax_resolution']['profile']);
        $this->assertSame('55', $facts['cfop_resolution']['context']['model']);

        $this->send('sales/'.$id.'/post', ['draft_revision' => 1])->assertOk()->assertJsonPath('data.status', 'posted');
        $this->assertDatabaseCount('erp_obligations', 1);
        $this->assertDatabaseCount('erp_fiscal_documents', 0);
    }

    #[DataProvider('intentModels')]
    public function test_model_specific_context_feedback_does_not_invent_a_recipient_exemption_for_nfce(string $model): void
    {
        $this->configureReviewedCommercialTax();
        $id = $this->send('sales', $this->reviewedBody([
            'fiscal_model' => $model, 'tax_context' => $this->classificationContext(['model' => $model]),
        ]))->assertCreated()->json('data.id');
        $errors = $this->send('sales/'.$id.'/post', ['draft_revision' => 1])->assertUnprocessable()
            ->assertJsonValidationErrors('erp_contact_id')->json('errors');
        foreach (['tax_context.final_consumer', 'tax_context.presence'] as $path) {
            if ($model === '65') {
                $this->assertArrayHasKey($path, $errors);
            } else {
                $this->assertArrayNotHasKey($path, $errors);
            }
        }
        $this->assertNoPostEffects();
    }

    #[DataProvider('intentModels')]
    public function test_valid_intended_post_freezes_reviewed_source_facts_without_certificate_or_fiscal_dispatch(string $model): void
    {
        $id = $this->send('sales', $this->readyBody($model))->assertCreated()->json('data.id');
        $sale = ErpSale::findOrFail($id);
        $frozenLines = $sale->commercial_snapshot['lines'];
        $this->send('sales/'.$id.'/post', ['draft_revision' => 1], 'intended-post')->assertOk()
            ->assertJsonPath('data.status', 'posted')->assertJsonPath('data.fiscal_model', $model);
        $snapshot = $sale->fresh()->commercial_snapshot;
        $this->assertSame($frozenLines, $snapshot['lines']);
        $this->assertSame('source_prerequisites_checked', $snapshot['fiscal_context']['readiness']);
        $this->assertSame($model, $snapshot['fiscal_context']['model']);
        $this->assertArrayHasKey('accepted', $snapshot);
        $this->assertDatabaseCount('erp_obligations', 1);
        $this->assertDatabaseCount('erp_fiscal_certificates', 0);
        $this->assertDatabaseCount('erp_fiscal_documents', 0);
        $this->assertDatabaseCount('erp_fiscal_attempts', 0);
    }

    public static function staleFacts(): iterable
    {
        yield 'profile revision' => ['profile'];
        yield 'nature rule revision' => ['nature'];
        yield 'product classification' => ['product'];
        yield 'issuer configuration' => ['issuer'];
    }

    #[DataProvider('staleFacts')]
    public function test_changed_review_preconditions_return_conflict_without_resolving_new_facts_or_effects(string $change): void
    {
        $id = $this->send('sales', $this->readyBody())->assertCreated()->json('data.id');
        $sale = ErpSale::findOrFail($id);
        $before = $sale->getAttributes();
        $lines = $sale->lines()->get()->toArray();
        if ($change === 'profile') {
            $this->patchJson('/api/v1/erp/tax-settings/product-tax-profiles/'.$this->taxProfileId, [
                'fields' => ['icms_cst' => '00', 'pis_cst' => '09', 'cofins_cst' => '07', 'icms_rate' => '18.0000'], 'expected_revision' => 2,
            ])->assertOk();
        } elseif ($change === 'nature') {
            $this->deleteJson('/api/v1/erp/tax-settings/operation-natures/'.$this->natureId.'/product-exceptions/'.$this->exceptionId, ['expected_revision' => 3])->assertOk();
        } elseif ($change === 'product') {
            $this->patchJson('/api/v1/erp/products/'.$this->variant->erp_product_id, ['ncm' => '87654321'])->assertOk();
        } else {
            $this->issuerConfiguration['issuer']['state_registration'] = '110042490115';
            $this->patchJson('/api/v1/erp/fiscal/configuration/'.$this->establishment->id, $this->issuerConfiguration)->assertOk();
        }
        $receipts = DB::table('erp_operation_receipts')->count();
        $this->send('sales/'.$id.'/post', ['draft_revision' => 1])->assertConflict();
        $this->assertSame($before, $sale->fresh()->getAttributes());
        $this->assertSame($lines, $sale->fresh()->lines()->get()->toArray());
        $this->assertSame($receipts, DB::table('erp_operation_receipts')->count());
        $this->assertNoPostEffects();
    }

    public function test_committed_intended_post_replays_original_result_after_current_configuration_changes(): void
    {
        $id = $this->send('sales', $this->readyBody())->assertCreated()->json('data.id');
        $result = $this->send('sales/'.$id.'/post', ['draft_revision' => 1], 'fiscal-intent-retry')->assertOk();
        $before = ErpSale::findOrFail($id)->getAttributes();
        $this->issuerConfiguration['issuer']['state_registration'] = '110042490115';
        $this->patchJson('/api/v1/erp/fiscal/configuration/'.$this->establishment->id, $this->issuerConfiguration)->assertOk();

        $this->send('sales/'.$id.'/post', ['draft_revision' => 1], 'fiscal-intent-retry')->assertOk()->assertExactJson($result->json());
        $this->assertSame($before, ErpSale::findOrFail($id)->getAttributes());
        $this->assertDatabaseCount('erp_obligations', 1);
        $this->assertDatabaseCount('erp_fiscal_documents', 0);
        $this->assertSame(1, DB::table('erp_operation_receipts')->where('operation', 'erp.sales.post')->count());
    }

    public function test_changed_consumed_company_default_requires_review_without_replacing_frozen_profile_facts(): void
    {
        $body = $this->readyBody();
        $this->patchJson('/api/v1/erp/company/settings', [
            'default_product_tax_profile_id' => $this->taxProfileId, 'expected_tax_default_revision' => 0,
        ])->assertOk();
        $this->patchJson('/api/v1/erp/products/'.$this->variant->erp_product_id, ['erp_product_tax_profile_id' => null])->assertOk();
        $id = $this->send('sales', $body)->assertCreated()->json('data.id');
        $sale = ErpSale::findOrFail($id);
        $before = $sale->getAttributes();
        $line = $sale->lines()->firstOrFail();
        $this->assertSame('company_profile', $line->product_snapshot['product_tax_resolution']['sources']['icms_cst']['source']);
        $this->patchJson('/api/v1/erp/company/settings', [
            'default_product_tax_profile_id' => null, 'expected_tax_default_revision' => 1,
        ])->assertOk();

        $this->send('sales/'.$id.'/post', ['draft_revision' => 1])->assertConflict();
        $this->assertSame($before, $sale->fresh()->getAttributes());
        $this->assertSame($line->product_snapshot, $line->fresh()->product_snapshot);
        $this->assertNoPostEffects();
    }

    public function test_declared_model_cannot_silently_replace_an_explicit_classification_context_model(): void
    {
        $this->configureReviewedCommercialTax();
        $body = $this->reviewedBody(['fiscal_model' => '65']);
        $this->assertSame('55', $body['tax_context']['model']);
        $this->send('sales', $body)->assertUnprocessable()->assertJsonValidationErrors('tax_context.model');
        $this->assertDatabaseCount('erp_sales', 0);
        $this->assertDatabaseCount('erp_operation_receipts', 0);
        $this->assertDatabaseCount('erp_commercial_number_sequences', 0);
        $this->assertNoPostEffects();
    }

    public function test_stale_company_assertion_cannot_post_or_refresh_an_intended_sale(): void
    {
        $id = $this->send('sales', $this->basicBody(['fiscal_model' => '55']))->assertCreated()->json('data.id');
        $sale = ErpSale::findOrFail($id);
        $before = $sale->getAttributes();
        $this->postJson('/api/v1/erp/sales/'.$id.'/post', ['draft_revision' => 1], [
            'Idempotency-Key' => 'stale-fiscal-company', 'X-Company-Id' => (string) ($this->company->id + 1),
        ])->assertConflict()->assertJsonPath('code', 'company_context_changed');
        $this->postJson('/api/v1/erp/sales/'.$id.'/tax-refresh-preview', ['draft_revision' => 1], [
            'X-Company-Id' => (string) ($this->company->id + 1),
        ])->assertConflict()->assertJsonPath('code', 'company_context_changed');
        $this->assertSame($before, $sale->fresh()->getAttributes());
        $this->assertNoPostEffects();
    }

    public function test_revoked_post_permission_keeps_an_intended_sale_and_all_effects_unchanged(): void
    {
        $id = $this->send('sales', $this->basicBody(['fiscal_model' => '55']))->assertCreated()->json('data.id');
        $sale = ErpSale::findOrFail($id);
        $before = $sale->getAttributes();
        ErpCompanyMembership::query()->where('user_id', $this->user->id)->update([
            'role' => 'operator', 'action_permissions' => ['erp.sales.show'],
        ]);
        $this->send('sales/'.$id.'/post', ['draft_revision' => 1])->assertForbidden();
        $this->assertSame($before, $sale->fresh()->getAttributes());
        $this->assertNoPostEffects();
    }

    public function test_issuer_change_between_explicit_refresh_preview_and_apply_rejects_before_writes_then_fresh_review_recovers(): void
    {
        $id = $this->send('sales', $this->readyBody())->assertCreated()->json('data.id');
        $sale = ErpSale::findOrFail($id);
        $before = $sale->getAttributes();
        $lines = $sale->lines()->get()->toArray();
        $preview = $this->postJson('/api/v1/erp/sales/'.$id.'/tax-refresh-preview', ['draft_revision' => 1])->assertOk()->json('data');
        $this->issuerConfiguration['issuer']['state_registration'] = '110042490115';
        $this->patchJson('/api/v1/erp/fiscal/configuration/'.$this->establishment->id, $this->issuerConfiguration)->assertOk();
        $receipts = DB::table('erp_operation_receipts')->count();

        $this->patchJson('/api/v1/erp/sales/'.$id, ['draft_revision' => 1,
            'tax_refresh' => ['reviewed_fingerprint' => $preview['reviewed_fingerprint']]], ['Idempotency-Key' => 'issuer-stale-proof'])
            ->assertConflict()->assertJsonPath('code', 'commercial_tax_review_changed');
        $this->assertSame($before, $sale->fresh()->getAttributes());
        $this->assertSame($lines, $sale->fresh()->lines()->get()->toArray());
        $this->assertSame($receipts, DB::table('erp_operation_receipts')->count());
        $this->assertNoPostEffects();

        $fresh = $this->postJson('/api/v1/erp/sales/'.$id.'/tax-refresh-preview', ['draft_revision' => 1])->assertOk()->json('data');
        $this->assertSame('55', $fresh['fiscal_source_review']['model']);
        $this->assertSame($this->natureId, $fresh['fiscal_source_review']['operation_nature_id']);
        $this->assertNotSame($preview['fiscal_source_review']['configuration_revision_id'], $fresh['fiscal_source_review']['configuration_revision_id']);
        $this->patchJson('/api/v1/erp/sales/'.$id, ['draft_revision' => 1,
            'tax_refresh' => ['reviewed_fingerprint' => $fresh['reviewed_fingerprint']]], ['Idempotency-Key' => 'issuer-fresh-proof'])
            ->assertOk()->assertJsonPath('data.draft_revision', 2);
        $this->send('sales/'.$id.'/post', ['draft_revision' => 2])->assertOk()->assertJsonPath('data.status', 'posted');
        $this->assertDatabaseCount('erp_obligations', 1);
        $this->assertDatabaseCount('erp_fiscal_documents', 0);
    }

    public function test_ordinary_edit_never_blesses_a_changed_issuer_review(): void
    {
        $id = $this->send('sales', $this->readyBody())->assertCreated()->json('data.id');
        $review = ErpSale::findOrFail($id)->commercial_snapshot['fiscal_context']['review'];
        $this->issuerConfiguration['issuer']['state_registration'] = '110042490115';
        $this->patchJson('/api/v1/erp/fiscal/configuration/'.$this->establishment->id, $this->issuerConfiguration)->assertOk();
        $this->patchJson('/api/v1/erp/sales/'.$id, ['draft_revision' => 1], ['Idempotency-Key' => 'ordinary-issuer-edit'])->assertOk();
        $this->assertSame($review, ErpSale::findOrFail($id)->commercial_snapshot['fiscal_context']['review']);
        $this->send('sales/'.$id.'/post', ['draft_revision' => 2])->assertConflict();
        $this->assertNoPostEffects();
    }

    public function test_unrelated_company_settings_do_not_stale_review_but_represented_tax_settings_do(): void
    {
        $id = $this->send('sales', $this->readyBody())->assertCreated()->json('data.id');
        $this->company->refresh()->update(['operating_settings' => [...$this->company->operating_settings, 'timezone' => 'America/Manaus']]);
        $this->send('sales/'.$id.'/post', ['draft_revision' => 1])->assertOk();
        $second = $this->send('sales', $this->readyBody())->assertCreated()->json('data.id');
        $before = ErpSale::findOrFail($second)->getAttributes();
        $this->company->refresh()->update(['operating_settings' => [...$this->company->operating_settings, 'perc_icms_padrao' => '17.0000']]);
        $this->send('sales/'.$second.'/post', ['draft_revision' => 1])->assertConflict();
        $this->assertSame($before, ErpSale::findOrFail($second)->getAttributes());
        $this->assertDatabaseCount('erp_obligations', 1);
        $this->assertDatabaseCount('erp_fiscal_documents', 0);
    }

    public function test_setting_fiscal_intent_on_stale_basic_classification_requires_explicit_review(): void
    {
        $body = $this->readyBody();
        $body['fiscal_model'] = null;
        $id = $this->send('sales', $body)->assertCreated()->json('data.id');
        $this->patchJson('/api/v1/erp/tax-settings/product-tax-profiles/'.$this->taxProfileId, [
            'fields' => ['icms_cst' => '00', 'pis_cst' => '09', 'cofins_cst' => '07', 'icms_rate' => '18.0000'], 'expected_revision' => 2,
        ])->assertOk();
        $this->patchJson('/api/v1/erp/sales/'.$id, ['draft_revision' => 1, 'fiscal_model' => '55'], ['Idempotency-Key' => 'explicit-stale-intent'])->assertOk();
        $before = ErpSale::findOrFail($id)->getAttributes();
        $this->send('sales/'.$id.'/post', ['draft_revision' => 2])->assertConflict();
        $this->assertSame($before, ErpSale::findOrFail($id)->getAttributes());
        $preview = $this->postJson('/api/v1/erp/sales/'.$id.'/tax-refresh-preview', ['draft_revision' => 2])->assertOk()->json('data');
        $this->patchJson('/api/v1/erp/sales/'.$id, ['draft_revision' => 2,
            'tax_refresh' => ['reviewed_fingerprint' => $preview['reviewed_fingerprint']]], ['Idempotency-Key' => 'explicit-classification-review'])->assertOk();
        $this->send('sales/'.$id.'/post', ['draft_revision' => 3])->assertOk();
        $this->assertDatabaseCount('erp_obligations', 1);
        $this->assertDatabaseCount('erp_fiscal_documents', 0);
    }

    public function test_refresh_preview_cannot_review_another_model_without_changing_explicit_intent(): void
    {
        $id = $this->send('sales', $this->readyBody())->assertCreated()->json('data.id');
        $before = ErpSale::findOrFail($id)->getAttributes();
        $receipts = DB::table('erp_operation_receipts')->count();
        $this->postJson('/api/v1/erp/sales/'.$id.'/tax-refresh-preview', [
            'draft_revision' => 1, 'tax_context' => $this->classificationContext(['model' => '65', 'final_consumer' => true, 'presence' => '1']),
        ])->assertUnprocessable()->assertJsonValidationErrors('tax_context.model');
        $this->assertSame($before, ErpSale::findOrFail($id)->getAttributes());
        $this->assertSame($receipts, DB::table('erp_operation_receipts')->count());
        $this->assertNoPostEffects();
    }
}
