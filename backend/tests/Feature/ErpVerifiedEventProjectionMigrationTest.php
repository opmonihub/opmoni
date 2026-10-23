<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Erp\Accounting\ErpAccountingDelivery;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpOfficeDelegation;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\Erp\Fiscal\ErpFiscalEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ErpVerifiedEventProjectionMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_upgrade_preserves_existing_document_history_and_defaults_explicit_projection_identity(): void
    {
        $migration = require database_path('migrations/2026_10_22_203000_add_verified_event_accounting_projections.php');
        $this->assertTrue(Schema::hasColumn('erp_accounting_deliveries', 'projection_identity'));
        $migration->down();
        $company = ErpCompany::factory()->create();
        $issuer = ErpEstablishment::factory()->create(['erp_company_id' => $company->id]);
        $document = ErpFiscalDocument::create(['erp_company_id' => $company->id, 'erp_establishment_id' => $issuer->id, 'model' => '55', 'number' => 1, 'idempotency_key' => 'migration-history', 'status' => 'authorized']);
        $id = DB::table('erp_accounting_deliveries')->insertGetId(['erp_company_id' => $company->id, 'erp_fiscal_document_id' => $document->id, 'account_id' => Account::factory()->create()->id, 'status' => 'delivered', 'attempts' => 2, 'delivered_at' => now()]);
        $before = (array) DB::table('erp_accounting_deliveries')->find($id);
        $migration->up();
        $after = (array) DB::table('erp_accounting_deliveries')->find($id);
        $this->assertSame('document', $after['projection_identity']);
        $this->assertNull($after['erp_fiscal_event_id']);
        $this->assertSame($before, array_diff_key($after, array_flip(['projection_identity', 'erp_fiscal_event_id'])));
        $migration->down();
        $this->assertSame($before, (array) DB::table('erp_accounting_deliveries')->find($id));
        $migration->up();
        $this->assertSame($id, ErpAccountingDelivery::sole()->id);
    }

    public function test_nullable_grant_and_mapping_indexes_distinguish_events_and_reject_equal_replay(): void
    {
        $company = ErpCompany::factory()->create();
        $issuer = ErpEstablishment::factory()->create(['erp_company_id' => $company->id]);
        $account = Account::factory()->create();
        $document = ErpFiscalDocument::create(['erp_company_id' => $company->id, 'erp_establishment_id' => $issuer->id, 'model' => '55', 'number' => 1, 'idempotency_key' => 'index-history']);
        $delegation = ErpOfficeDelegation::factory()->create(['erp_company_id' => $company->id, 'account_id' => $account->id]);
        foreach ([null, $delegation->id] as $grant) {
            $identity = ['erp_company_id' => $company->id, 'erp_fiscal_document_id' => $document->id, 'account_id' => $account->id,
                'delegation_id' => $grant, 'mapping_id' => null, 'status' => 'pending', 'attempts' => 0];
            foreach (['document', 'event:1', 'event:2'] as $projection) {
                DB::table('erp_accounting_deliveries')->insert($identity + ['projection_identity' => $projection]);
            }
            try {
                DB::transaction(fn () => DB::table('erp_accounting_deliveries')->insert($identity + ['projection_identity' => 'event:1']));
                $this->fail('Equal nullable-grant projection identity must remain unique.');
            } catch (UniqueConstraintViolationException) {
                $this->assertSame(3, DB::table('erp_accounting_deliveries')->where('delegation_id', $grant)->count());
            }
        }
        $migration = require database_path('migrations/2026_10_22_203000_add_verified_event_accounting_projections.php');
        try {
            $migration->down();
            $this->fail('Distinct projection histories must prevent narrowing.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Cannot narrow', $exception->getMessage());
        }
        $this->assertDatabaseCount('erp_accounting_deliveries', 6);
    }

    public function test_rollback_refuses_to_discard_accepted_event_evidence(): void
    {
        $company = ErpCompany::factory()->create();
        $issuer = ErpEstablishment::factory()->create(['erp_company_id' => $company->id]);
        $document = ErpFiscalDocument::create(['erp_company_id' => $company->id, 'erp_establishment_id' => $issuer->id, 'model' => '55', 'number' => 1, 'idempotency_key' => 'event-history']);
        $event = ErpFiscalEvent::create(['erp_fiscal_document_id' => $document->id, 'event_type' => 'cancel', 'status' => 'accepted', 'idempotency_key' => 'accepted-event', 'accepted_xml' => '<preserved/>']);
        $migration = require database_path('migrations/2026_10_22_203000_add_verified_event_accounting_projections.php');
        try {
            $migration->down();
            $this->fail('Accepted event evidence must prevent narrowing rollback.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Cannot narrow', $exception->getMessage());
        }
        $this->assertSame('<preserved/>', $event->fresh()->accepted_xml);
        $this->assertTrue(Schema::hasColumn('erp_accounting_deliveries', 'projection_identity'));
    }
}
