<?php

namespace Tests\Feature;

use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpOperationReceipt;
use App\Models\Erp\Finance\ErpFinancialAccount;
use App\Models\Erp\Finance\ErpObligation;
use App\Models\Erp\Finance\ErpPayment;
use App\Models\Erp\Finance\ErpPaymentAllocation;
use App\Models\Erp\Reports\ErpReportExport;
use App\Models\Erp\Sales\ErpSale;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;

class ErpReportExportIdentityTest extends ErpReportsFilterContractTest
{
    #[DataProvider('commercialReportCategories')]
    public function test_commercial_export_reauthorizes_ancestors_for_get_replay_and_lookup_without_mutation(string $category): void
    {
        $fixture = $this->crossEstablishmentSale();
        $this->withHeader('Idempotency-Key', 'ancestor-'.$category);
        $created = $this->postJson('/api/v1/erp/reports/'.$category.'/exports', $fixture['filters'])->assertCreated()
            ->assertJsonMissingPath('data.filters.authorized_commercial_ids');
        $export = ErpReportExport::findOrFail($created->json('data.id'));
        $this->getJson('/api/v1/erp/reports/exports/'.$export->id)->assertOk()
            ->assertJsonMissingPath('data.report.filters.authorized_commercial_ids');
        $attributes = $export->getAttributes();
        $receipt = ErpOperationReceipt::where('operation', 'erp.reports.exports.store')->sole()->getAttributes();
        $bytes = Storage::disk('local')->get($export->storage_path);
        $fixture['membership']->update(['role' => 'operator', 'action_permissions' => ['erp.read', 'erp.write'], 'access_all_establishments' => false]);
        $fixture['membership']->establishments()->attach($fixture['visible']->erp_establishment_id, ['erp_company_id' => $export->erp_company_id]);
        $this->getJson('/api/v1/erp/reports/exports/'.$export->id)->assertConflict()
            ->assertJsonPath('code', 'export_requires_regeneration')->assertJsonMissingPath('data.report')
            ->assertJsonMissingPath('data.filters.authorized_commercial_ids');
        $this->postJson('/api/v1/erp/reports/'.$category.'/exports', $fixture['filters'])->assertConflict()->assertJsonMissingPath('data');
        $this->getJson('/api/v1/erp/operations/lookup?operation=erp.reports.exports.store')->assertConflict()->assertJsonMissingPath('data');
        $this->assertSame($attributes, $export->fresh()->getAttributes());
        $this->assertSame($receipt, ErpOperationReceipt::where('operation', 'erp.reports.exports.store')->sole()->getAttributes());
        $this->assertSame($bytes, Storage::disk('local')->get($export->storage_path));
        $this->assertDatabaseCount('erp_report_exports', 1);
    }

    public function test_commercial_export_dependencies_include_current_obligations_outside_period_and_full_set_beyond_detail(): void
    {
        $fixture = $this->crossEstablishmentSale();
        $this->withHeader('Idempotency-Key', 'commercial-full-set');
        $fixture['sale']->update(['posted_at' => now()->subMonth()]);
        for ($i = 0; $i < 201; $i++) {
            ErpSale::create(['erp_company_id' => $fixture['sale']->erp_company_id, 'erp_establishment_id' => $fixture['visible']->erp_establishment_id,
                'invoice_no' => 'full-set-'.$i, 'status' => 'posted', 'currency' => 'BRL', 'total_amount' => '1.0000', 'posted_at' => now()]);
        }
        $last = ErpSale::create(['erp_company_id' => $fixture['sale']->erp_company_id,
            'erp_establishment_id' => $fixture['visible']->erp_establishment_id, 'erp_order_id' => $fixture['sale']->erp_order_id,
            'invoice_no' => 'hidden-beyond-cutoff', 'status' => 'posted', 'currency' => 'BRL', 'total_amount' => '3.0000', 'posted_at' => now()]);
        $created = $this->postJson('/api/v1/erp/reports/sales/exports', $fixture['filters'])->assertCreated();
        $this->getJson('/api/v1/erp/reports/exports/'.$created->json('data.id'))->assertOk()
            ->assertJsonPath('data.report.ledgers.documents.row_count', 203)->assertJsonCount(200, 'data.report.ledgers.documents.rows')
            ->assertJsonPath('data.report.totals.posted_total', '211.0000');
        $export = ErpReportExport::findOrFail($created->json('data.id'));
        $this->assertContains($fixture['sale']->id, $export->filters['authorized_commercial_ids']['sales'] ?? []);
        $this->assertContains($last->id, $export->filters['authorized_commercial_ids']['sales'] ?? []);
        $this->assertCount(204, $export->filters['authorized_commercial_ids']['sales'] ?? []);
        $receipt = ErpOperationReceipt::where('operation', 'erp.reports.exports.store')->sole()->getAttributes();
        $fixture['membership']->update(['role' => 'operator', 'action_permissions' => ['erp.read', 'erp.write'], 'access_all_establishments' => false]);
        $fixture['membership']->establishments()->attach($fixture['visible']->erp_establishment_id, ['erp_company_id' => $export->erp_company_id]);
        $this->getJson('/api/v1/erp/reports/exports/'.$export->id)->assertConflict()->assertJsonMissingPath('data.report');
        $this->assertSame($receipt, ErpOperationReceipt::where('operation', 'erp.reports.exports.store')->sole()->getAttributes());
    }

    public function test_reversal_only_period_export_reauthorizes_commercial_payment_ancestry_without_mutation(): void
    {
        $fixture = $this->crossEstablishmentSale();
        $establishmentId = $fixture['visible']->erp_establishment_id;
        $saleObligation = ErpObligation::where('source_type', 'erp_sale')
            ->where('source_id', $fixture['sale']->id)->sole();
        // The payable filter removes this receivable from the snapshot, isolating the payment's ancestry proof.
        ErpObligation::where('erp_company_id', $fixture['sale']->erp_company_id)
            ->where('id', '!=', $saleObligation->id)->update(['source_type' => null, 'source_id' => null]);
        $base = ['erp_company_id' => $fixture['sale']->erp_company_id, 'erp_establishment_id' => $establishmentId,
            'currency' => 'BRL', 'direction' => 'in', 'method' => 'manual', 'status' => 'posted'];
        $original = ErpPayment::create($base + ['amount' => '40.0000', 'posted_at' => now()->subMonth()]);
        ErpPaymentAllocation::create(['erp_payment_id' => $original->id, 'erp_obligation_id' => $saleObligation->id,
            'amount' => '40.0000', 'posted_at' => now()->subMonth()]);
        $reversal = ErpPayment::create(array_replace($base, ['amount' => '-40.0000', 'source_type' => 'payment_reversal',
            'source_id' => $original->id, 'reversal_of_id' => $original->id, 'posted_at' => now()]));
        $filters = $fixture['filters'] + ['direction' => 'payable'];
        $this->withHeader('Idempotency-Key', 'reversal-ancestor-export');
        $created = $this->postJson('/api/v1/erp/reports/financial/exports', $filters)->assertCreated();
        $export = ErpReportExport::findOrFail($created->json('data.id'));
        $created->assertJsonMissingPath('data.filters.authorized_commercial_ids');
        $this->getJson('/api/v1/erp/reports/exports/'.$export->id)->assertOk()
            ->assertJsonPath('data.report.ledgers.payments.row_count', 1)
            ->assertJsonPath('data.report.ledgers.payments.rows.0.id', $reversal->id);
        $this->assertContains($fixture['sale']->id, $export->filters['authorized_commercial_ids']['sales'] ?? []);
        $attributes = $export->getAttributes();
        $receipt = ErpOperationReceipt::where('operation', 'erp.reports.exports.store')->sole()->getAttributes();
        $bytes = Storage::disk('local')->get($export->storage_path);

        $fixture['membership']->update(['role' => 'operator', 'action_permissions' => ['erp.read', 'erp.write'], 'access_all_establishments' => false]);
        $fixture['membership']->establishments()->attach($establishmentId, ['erp_company_id' => $fixture['sale']->erp_company_id]);
        $this->getJson('/api/v1/erp/reports/exports/'.$export->id)->assertConflict()
            ->assertJsonPath('code', 'export_requires_regeneration')->assertJsonMissingPath('data.report');
        $this->postJson('/api/v1/erp/reports/financial/exports', $filters)->assertConflict()->assertJsonMissingPath('data');
        $this->getJson('/api/v1/erp/operations/lookup?operation=erp.reports.exports.store')->assertConflict()->assertJsonMissingPath('data');
        $this->assertSame($attributes, $export->fresh()->getAttributes());
        $this->assertSame($receipt, ErpOperationReceipt::where('operation', 'erp.reports.exports.store')->sole()->getAttributes());
        $this->assertSame($bytes, Storage::disk('local')->get($export->storage_path));
        $this->assertDatabaseCount('erp_report_exports', 1);
    }

    public function test_legacy_commercial_export_without_dependency_proof_fails_closed(): void
    {
        $this->withHeader('Idempotency-Key', 'legacy-commercial');
        $created = $this->postJson('/api/v1/erp/reports/sales/exports', [])->assertCreated();
        $export = ErpReportExport::findOrFail($created->json('data.id'));
        $export->update(['filters' => array_diff_key($export->filters, ['authorized_commercial_ids' => true])]);
        $this->getJson('/api/v1/erp/reports/exports/'.$export->id)->assertConflict()->assertJsonPath('code', 'export_requires_regeneration');
        $this->postJson('/api/v1/erp/reports/sales/exports', [])->assertConflict();
        $this->getJson('/api/v1/erp/operations/lookup?operation=erp.reports.exports.store')->assertConflict();
    }

    public function test_same_key_replays_original_acknowledgement_without_republishing(): void
    {
        $this->withHeader('Idempotency-Key', 'report-lost-response');
        $first = $this->postJson('/api/v1/erp/reports/purchases/exports', [])->assertCreated();
        $disk = Mockery::mock(Storage::disk('local'))->makePartial();
        $disk->shouldNotReceive('put');
        Storage::set('local', $disk);
        $this->travel(2)->days();
        $this->postJson('/api/v1/erp/reports/purchases/exports', [])->assertCreated()->assertExactJson($first->json());
        $this->getJson('/api/v1/erp/operations/lookup?operation=erp.reports.exports.store')
            ->assertOk()->assertJsonPath('data.state', 'completed')->assertJsonPath('data.result', $first->json('data'));
        $this->assertDatabaseCount('erp_report_exports', 1);
        $this->assertDatabaseCount('erp_operation_receipts', 1);
        $this->getJson('/api/v1/erp/reports/exports/'.$first->json('data.id'))->assertStatus(410);
    }

    public function test_known_failure_replays_503_and_lookup_completed_is_not_artifact_completion(): void
    {
        $disk = Mockery::mock(Storage::disk('local'))->makePartial();
        $disk->shouldReceive('put')->once()->andReturn(false);
        Storage::set('local', $disk);
        $this->withHeader('Idempotency-Key', 'report-failed-response');
        $first = $this->postJson('/api/v1/erp/reports/purchases/exports', [])->assertStatus(503)->assertJsonPath('data.status', 'failed');
        $this->postJson('/api/v1/erp/reports/purchases/exports', [])->assertStatus(503)->assertExactJson($first->json());
        $this->getJson('/api/v1/erp/operations/lookup?operation=erp.reports.exports.store')
            ->assertOk()->assertJsonPath('data.state', 'completed')->assertJsonPath('data.result.status', 'failed');
        $this->assertDatabaseCount('erp_report_exports', 1);
        Storage::fake('local');
        $this->withHeader('Idempotency-Key', 'deliberate-new-generation')
            ->postJson('/api/v1/erp/reports/purchases/exports', [])->assertCreated();
        $this->assertSame('failed', ErpReportExport::query()->findOrFail($first->json('data.id'))->status);
        $this->assertDatabaseCount('erp_report_exports', 2);
    }

    public function test_changed_filters_or_category_conflict_and_unknown_lookup_does_not_publish(): void
    {
        $this->withHeader('Idempotency-Key', 'report-frozen-intent')
            ->postJson('/api/v1/erp/reports/purchases/exports', ['currency' => 'USD'])->assertCreated();
        $this->postJson('/api/v1/erp/reports/purchases/exports', ['currency' => 'BRL'])->assertConflict();
        $this->postJson('/api/v1/erp/reports/sales/exports', ['currency' => 'USD'])->assertConflict();
        $this->withHeader('Idempotency-Key', 'unknown-report-intent')
            ->getJson('/api/v1/erp/operations/lookup?operation=erp.reports.exports.store')->assertNotFound();
        $this->assertDatabaseCount('erp_report_exports', 1);
    }

    public function test_replay_and_lookup_reauthorize_actor_scope_permissions_and_context(): void
    {
        $this->withHeader('Idempotency-Key', 'report-current-access')
            ->postJson('/api/v1/erp/reports/purchases/exports', [])->assertCreated();
        $receipt = ErpOperationReceipt::query()->sole();
        $membership = ErpCompanyMembership::query()->where('user_id', $receipt->actor_user_id)->sole();
        $membership->update(['role' => 'manager', 'action_permissions' => ['*'], 'access_all_establishments' => false]);
        $this->getJson('/api/v1/erp/operations/lookup?operation=erp.reports.exports.store')->assertConflict();
        $this->postJson('/api/v1/erp/reports/purchases/exports', [])->assertConflict();
        $membership->update(['action_permissions' => ['erp.stock.write']]);
        $this->getJson('/api/v1/erp/operations/lookup?operation=erp.reports.exports.store')->assertForbidden();
        $membership->delete();
        $this->postJson('/api/v1/erp/reports/purchases/exports', [])->assertForbidden();
        $this->assertDatabaseCount('erp_report_exports', 1);
    }

    public function test_recovery_cannot_expose_another_actor_or_cross_session_company(): void
    {
        $this->withHeader('Idempotency-Key', 'report-actor-bound')
            ->postJson('/api/v1/erp/reports/purchases/exports', [])->assertCreated();
        $export = ErpReportExport::query()->sole();
        $export->update(['user_id' => User::factory()->create()->id]);
        $this->getJson('/api/v1/erp/operations/lookup?operation=erp.reports.exports.store')->assertForbidden();
        $this->postJson('/api/v1/erp/reports/purchases/exports', [])->assertForbidden();
        $this->withHeader('X-Company-Id', '999999')
            ->getJson('/api/v1/erp/operations/lookup?operation=erp.reports.exports.store')->assertConflict();
        $this->assertDatabaseCount('erp_report_exports', 1);
    }

    public function test_initial_publication_keeps_store_permission_while_recovery_requires_current_read_permission(): void
    {
        ErpCompanyMembership::query()->update(['action_permissions' => ['erp.reports.exports.store']]);
        $this->withHeader('Idempotency-Key', 'report-store-only')
            ->postJson('/api/v1/erp/reports/purchases/exports', [])->assertCreated();
        $this->getJson('/api/v1/erp/operations/lookup?operation=erp.reports.exports.store')->assertForbidden();
        $this->postJson('/api/v1/erp/reports/purchases/exports', [])->assertForbidden();
        $this->assertDatabaseCount('erp_report_exports', 1);
    }

    #[DataProvider('financialReportCategories')]
    public function test_linked_scope_rechecks_existing_explicit_establishment_export_for_get_replay_and_lookup(string $category): void
    {
        $fixture = $this->crossEstablishmentPayment();
        $this->withHeader('Idempotency-Key', 'linked-scope-'.$category);
        $created = $this->postJson('/api/v1/erp/reports/'.$category.'/exports', $fixture['filters'])->assertCreated();
        $created->assertJsonMissingPath('data.filters.authorized_payment_ids');
        $export = ErpReportExport::query()->findOrFail($created->json('data.id'));
        $prefix = $category === 'overview' ? 'data.report.ledgers.financial' : 'data.report.ledgers';
        $this->getJson('/api/v1/erp/reports/exports/'.$export->id)->assertOk()
            ->assertJsonPath($prefix.'.payments.rows.0.id', $fixture['payment']->id);
        $this->postJson('/api/v1/erp/reports/'.$category.'/exports', $fixture['filters'])->assertCreated()->assertExactJson($created->json());
        $this->getJson('/api/v1/erp/operations/lookup?operation=erp.reports.exports.store')->assertOk();
        $original = $export->getAttributes();
        $receipt = ErpOperationReceipt::query()->sole()->getAttributes();
        $bytes = Storage::disk('local')->get($export->storage_path);
        $disk = Mockery::mock(Storage::disk('local'))->makePartial();
        $disk->shouldNotReceive('put');
        Storage::set('local', $disk);
        $fixture['membership']->establishments()->detach($fixture['hidden_establishment_id']);
        $this->getJson('/api/v1/erp/reports/exports/'.$export->id)->assertConflict()
            ->assertJsonPath('code', 'export_requires_regeneration')->assertJsonMissingPath('data.report')
            ->assertJsonMissingPath('data.filters.authorized_payment_ids');
        $this->postJson('/api/v1/erp/reports/'.$category.'/exports', $fixture['filters'])->assertConflict()->assertJsonMissingPath('data');
        $this->getJson('/api/v1/erp/operations/lookup?operation=erp.reports.exports.store')->assertConflict()->assertJsonMissingPath('data');
        $this->assertSame($original, $export->fresh()->getAttributes());
        $this->assertSame($receipt, ErpOperationReceipt::query()->sole()->getAttributes());
        $this->assertSame($bytes, Storage::disk('local')->get($export->storage_path));
        $this->assertDatabaseCount('erp_report_exports', 1);
    }

    public function test_linked_scope_export_checks_dependencies_beyond_bounded_payment_detail_without_a_journal_key(): void
    {
        $membership = ErpCompanyMembership::query()->sole();
        $establishment = ErpEstablishment::query()->where('erp_company_id', $membership->erp_company_id)->sole();
        $second = ErpEstablishment::factory()->create(['erp_company_id' => $membership->erp_company_id]);
        $base = ['erp_company_id' => $membership->erp_company_id, 'erp_establishment_id' => $establishment->id,
            'currency' => 'BRL', 'posted_at' => now(), 'direction' => 'in', 'method' => 'manual', 'status' => 'posted'];
        for ($i = 0; $i < 200; $i++) {
            ErpPayment::create($base + ['amount' => '1.0000']);
        }
        $hiddenAccount = ErpFinancialAccount::create([
            'erp_company_id' => $membership->erp_company_id, 'erp_establishment_id' => $second->id,
            'name' => 'Linked account', 'account_type' => 'bank', 'currency' => 'BRL',
        ]);
        ErpPayment::create($base + ['erp_financial_account_id' => $hiddenAccount->id, 'amount' => '100.0000']);
        $created = $this->postJson('/api/v1/erp/reports/financial/exports', ['erp_establishment_id' => $establishment->id])->assertCreated();
        $this->getJson('/api/v1/erp/reports/exports/'.$created->json('data.id'))->assertOk()
            ->assertJsonPath('data.report.ledgers.payments.row_count', 201)
            ->assertJsonPath('data.report.ledgers.payments.detail_truncated', true)
            ->assertJsonCount(200, 'data.report.ledgers.payments.rows')
            ->assertJsonPath('data.report.totals.payments_posted_total', '300.0000');
        $membership->update(['role' => 'operator', 'action_permissions' => ['erp.read', 'erp.write'], 'access_all_establishments' => false]);
        $membership->establishments()->attach($establishment->id, ['erp_company_id' => $membership->erp_company_id]);
        $this->getJson('/api/v1/erp/reports/exports/'.$created->json('data.id'))->assertConflict()
            ->assertJsonPath('code', 'export_requires_regeneration')->assertJsonMissingPath('data.report');
        $this->assertDatabaseCount('erp_operation_receipts', 0);
    }

    public function test_linked_scope_legacy_financial_export_without_dependency_proof_requires_regeneration(): void
    {
        $fixture = $this->crossEstablishmentPayment();
        $this->withHeader('Idempotency-Key', 'legacy-linked-scope');
        $created = $this->postJson('/api/v1/erp/reports/financial/exports', $fixture['filters'])->assertCreated();
        $export = ErpReportExport::query()->findOrFail($created->json('data.id'));
        $export->update(['filters' => array_diff_key($export->filters, ['authorized_payment_ids' => true])]);
        $this->getJson('/api/v1/erp/reports/exports/'.$export->id)->assertConflict()->assertJsonPath('code', 'export_requires_regeneration');
        $this->postJson('/api/v1/erp/reports/financial/exports', $fixture['filters'])->assertConflict();
        $this->getJson('/api/v1/erp/operations/lookup?operation=erp.reports.exports.store')->assertConflict();
    }
}
