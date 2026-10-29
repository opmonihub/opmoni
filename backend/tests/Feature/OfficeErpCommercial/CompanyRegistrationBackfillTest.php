<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Models\Erp\Commercial\OfficeErpCompanyRegistration;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpLicenceAllocation;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\ErpSubscriptionTrial;
use Illuminate\Support\Facades\DB;
use Tests\OfficeErpCommercialTestCase;

class CompanyRegistrationBackfillTest extends OfficeErpCommercialTestCase
{
    public function test_backfill_preserves_paid_trial_and_historical_records_and_is_repeatable(): void
    {
        $ctx = $this->officeContext();
        foreach (['paid', 'trial', 'historical'] as $kind) {
            $contract = $this->acceptedContract($ctx);
            $company = ErpCompany::factory()->create();
            $contract->forceFill(['erp_company_id' => $company->id, 'created_at' => '2025-01-02 03:04:05'])->save();
            ErpCompanyMembership::factory()->create(['erp_company_id' => $company->id]);
            $subscription = ErpSubscription::factory()->create([
                'erp_company_id' => $company->id, 'office_erp_contract_id' => $contract->id,
                'status' => match ($kind) {
                    'paid' => 'active', 'trial' => 'trialing', default => 'canceled'
                },
            ]);
            OfficeErpLicenceAllocation::factory()->create([
                'account_id' => $ctx->accountId, 'contract_id' => $contract->id, 'erp_company_id' => $company->id,
                'state' => $kind === 'historical' ? 'released' : 'occupied', 'expires_at' => null,
            ]);
            if ($kind === 'trial') {
                ErpSubscriptionTrial::query()->create([
                    'erp_subscription_id' => $subscription->id, 'erp_company_id' => $company->id,
                    'account_id' => $ctx->accountId, 'cnpj' => $company->cnpj,
                    'started_at' => now(), 'ends_at' => now()->addDays(7), 'link_revision' => 1,
                ]);
            } else {
                $this->issuedInvoice($ctx, $contract);
                $contract->forceFill(['agreement_status' => $kind === 'paid' ? 'effective' : 'canceled'])->save();
            }
        }
        $before = $this->snapshot();
        $this->artisan('office-erp:backfill-registrations')->expectsOutputToContain('created=3 unchanged=0 conflicts=0')->assertSuccessful();
        self::assertSame($before, $this->snapshot());
        $registrations = DB::table('office_erp_company_registrations')->orderBy('id')->get();
        self::assertCount(3, $registrations);
        self::assertSame('2025-01-02 03:04:05', $registrations[0]->registered_at);
        $this->artisan('office-erp:backfill-registrations')->expectsOutputToContain('created=0 unchanged=3 conflicts=0')->assertSuccessful();
        self::assertSame($before, $this->snapshot());
        self::assertEquals($registrations, DB::table('office_erp_company_registrations')->orderBy('id')->get());
    }

    public function test_multiple_contracts_for_the_same_relationship_create_one_registration(): void
    {
        $ctx = $this->officeContext();
        $contract = $this->acceptedContract($ctx);
        $company = ErpCompany::factory()->create();
        $contract->forceFill(['erp_company_id' => $company->id, 'created_at' => '2024-01-01 00:00:00'])->save();
        OfficeErpContract::factory()->create(['account_id' => $ctx->accountId, 'client_id' => $contract->client_id, 'erp_company_id' => $company->id]);
        $this->artisan('office-erp:backfill-registrations')->assertSuccessful();
        $this->assertDatabaseCount('office_erp_company_registrations', 1);
        $this->assertDatabaseHas('office_erp_company_registrations', ['registered_at' => '2024-01-01 00:00:00']);
    }

    public function test_ambiguous_relationships_are_reported_without_selecting_a_company(): void
    {
        $ctx = $this->officeContext();
        $client = $this->clientFor($ctx);
        foreach (range(1, 2) as $index) {
            OfficeErpContract::factory()->create(['account_id' => $ctx->accountId, 'client_id' => $client->id, 'erp_company_id' => ErpCompany::factory()->create()->id]);
        }
        $this->artisan('office-erp:backfill-registrations')->expectsOutputToContain('conflicts=2')->assertFailed();
        $this->assertDatabaseCount('office_erp_company_registrations', 0);
    }

    public function test_dry_run_and_conflicting_existing_registration_do_not_overwrite_data(): void
    {
        $ctx = $this->officeContext();
        $contract = $this->acceptedContract($ctx);
        $company = ErpCompany::factory()->create();
        $contract->forceFill(['erp_company_id' => $company->id])->save();
        $this->artisan('office-erp:backfill-registrations', ['--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseCount('office_erp_company_registrations', 0);
        $existing = OfficeErpCompanyRegistration::factory()->create(['account_id' => $ctx->accountId, 'client_id' => $this->clientFor($ctx)->id, 'erp_company_id' => $company->id]);
        $before = $existing->refresh()->getAttributes();
        $this->artisan('office-erp:backfill-registrations')->expectsOutputToContain('conflicts=1')->assertFailed();
        self::assertSame($before, $existing->fresh()->getAttributes());
        $this->assertDatabaseCount('office_erp_company_registrations', 1);
    }

    private function snapshot(): array
    {
        $result = [];
        foreach (['accounts', 'account_memberships', 'users', 'subscriptions', 'clients', 'erp_companies', 'erp_company_memberships', 'erp_subscriptions', 'erp_subscription_trials', 'office_erp_contracts', 'office_erp_contract_revisions', 'office_erp_licence_allocations', 'office_erp_invoices', 'office_erp_commercial_operations', 'office_erp_audit_events'] as $table) {
            $result[$table] = hash('sha256', DB::table($table)->orderBy('id')->get()->toJson());
        }

        return $result;
    }
}
