<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Models\Client;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\OfficeErpCommercialTestCase;

class CompanyRegistrationTest extends OfficeErpCommercialTestCase
{
    public function test_registering_a_portfolio_client_creates_only_an_unclaimed_company_and_technical_subscription(): void
    {
        $ctx = $this->officeContext(['enabled' => false, 'licence_limit' => 0, 'module_keys' => []]);
        $client = $this->clientFor($ctx);
        $users = DB::table('users')->count();
        $this->actingAs($ctx->actor)->withHeader('Idempotency-Key', 'register-one')
            ->postJson('/api/v1/erp-commercial/companies', ['client_ids' => [$client->id]])
            ->assertCreated()->assertJsonCount(1, 'data.created')->assertJsonCount(0, 'data.unchanged');
        $company = ErpCompany::query()->sole();
        self::assertSame('unclaimed', $company->owner_setup_status);
        self::assertSame('pending', $company->subscription->status);
        self::assertNull($company->subscription->office_erp_contract_id);
        self::assertSame($users, DB::table('users')->count());
        foreach (['office_erp_contracts', 'office_erp_licence_allocations', 'office_erp_invoices', 'erp_company_memberships'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->getJson('/api/v1/erp-commercial/operations/register-one')->assertOk()->assertJsonCount(1, 'data.created');
        $this->postJson('/api/v1/erp-commercial/companies', ['client_ids' => [$client->id]])->assertCreated();
        $this->assertDatabaseCount('office_erp_company_registrations', 1);
        $this->assertDatabaseCount('erp_subscriptions', 1);
    }

    public function test_batch_is_atomic_and_existing_same_office_registrations_are_unchanged(): void
    {
        $ctx = $this->officeContext();
        $clients = [$this->clientFor($ctx), $this->clientFor($ctx)];
        $this->actingAs($ctx->actor)->withHeader('Idempotency-Key', 'register-first')
            ->postJson('/api/v1/erp-commercial/companies', ['client_ids' => [$clients[0]->id]])->assertCreated();
        $this->withHeader('Idempotency-Key', 'register-batch')->postJson('/api/v1/erp-commercial/companies', ['client_ids' => [$clients[1]->id, $clients[0]->id]])
            ->assertCreated()->assertJsonCount(1, 'data.created')->assertJsonCount(1, 'data.unchanged');
        $this->assertDatabaseCount('office_erp_company_registrations', 2);
        $this->assertDatabaseCount('erp_subscriptions', 2);
        $this->postJson('/api/v1/erp-commercial/companies', ['client_ids' => [$clients[0]->id, $clients[1]->id]])->assertCreated();
        $this->assertDatabaseCount('erp_companies', 2);
    }

    public function test_invalid_foreign_inactive_or_individual_batch_leaves_no_partial_registration(): void
    {
        $ctx = $this->officeContext();
        $valid = $this->clientFor($ctx);
        $foreign = Client::factory()->company()->create();
        $inactive = $this->clientFor($ctx);
        $inactive->forceFill(['status' => 'inactive'])->save();
        $individual = $this->withOfficeAccount($ctx, fn () => Client::factory()->create(['account_id' => $ctx->accountId, 'person_type' => 'individual', 'tax_regime' => 'not_applicable']));
        $this->actingAs($ctx->actor);
        foreach ([$foreign->id, $inactive->id, $individual->id, 9007199254740991] as $id) {
            $this->withHeader('Idempotency-Key', 'invalid-batch-'.$id)->postJson('/api/v1/erp-commercial/companies', ['client_ids' => [$valid->id, $id]])->assertUnprocessable();
            $this->assertDatabaseCount('office_erp_company_registrations', 0);
            $this->assertDatabaseCount('erp_companies', 0);
        }
    }

    public function test_company_cnpj_collision_is_not_silently_adopted_and_rolls_back_the_whole_batch(): void
    {
        $ctx = $this->officeContext();
        $first = $this->clientFor($ctx);
        $second = $this->clientFor($ctx);
        $foreignCompany = ErpCompany::factory()->create(['cnpj' => $second->tax_id]);
        $this->actingAs($ctx->actor)->withHeader('Idempotency-Key', 'collision-batch')
            ->postJson('/api/v1/erp-commercial/companies', ['client_ids' => [$first->id, $second->id]])->assertConflict();
        $this->assertDatabaseCount('office_erp_company_registrations', 0);
        $this->assertDatabaseCount('erp_companies', 1);
        $this->assertDatabaseCount('office_erp_commercial_operations', 0);
        self::assertSame($foreignCompany->cnpj, $foreignCompany->fresh()->cnpj);
    }

    public function test_inactive_office_subscription_and_unknown_or_unbounded_input_are_rejected(): void
    {
        $ctx = $this->officeContext();
        $client = $this->clientFor($ctx);
        $this->actingAs($ctx->actor)->withHeader('Idempotency-Key', 'invalid-registration');
        foreach ([['client_ids' => []], ['client_ids' => array_fill(0, 101, $client->id)], ['client_ids' => [$client->id], 'password' => 'forbidden'], ['client_ids' => [$client->id], 'new_client' => []]] as $payload) {
            $this->postJson('/api/v1/erp-commercial/companies', $payload)->assertUnprocessable();
        }
        $ctx->account->subscription->forceFill(['status' => 'canceled'])->save();
        $this->postJson('/api/v1/erp-commercial/companies', ['client_ids' => [$client->id]])->assertForbidden();
        $this->assertDatabaseCount('erp_companies', 0);
    }

    public function test_new_client_registration_prepares_outside_transaction_and_replay_skips_provider(): void
    {
        $ctx = $this->officeContext();
        $levels = [];
        $baseline = DB::transactionLevel();
        Http::fake(function () use (&$levels) {
            $levels[] = DB::transactionLevel();

            return Http::response([], 404);
        });
        $payload = ['new_client' => ['tax_id' => '27865757000102', 'tax_regime' => 'presumed_profit', 'name' => 'Empresa cadastrada']];
        $first = $this->actingAs($ctx->actor)->withHeader('Idempotency-Key', 'new-registration-replay')
            ->postJson('/api/v1/erp-commercial/companies', $payload)->assertCreated();
        $again = $this->postJson('/api/v1/erp-commercial/companies', $payload)->assertCreated();
        self::assertSame($first->json('data'), $again->json('data'));
        self::assertSame([$baseline], $levels);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseCount('erp_companies', 1);
        $this->assertDatabaseCount('office_erp_contracts', 0);
        $this->assertDatabaseCount('office_erp_invoices', 0);
    }

    public function test_new_registration_enforces_client_limit_before_provider_lookup(): void
    {
        $ctx = $this->officeContext();
        $plan = $ctx->account->subscription->plan;
        $plan->forceFill(['limits' => ['clients' => 0]])->save();
        Http::fake();
        $this->actingAs($ctx->actor)->withHeader('Idempotency-Key', 'registration-limit')
            ->postJson('/api/v1/erp-commercial/companies', ['new_client' => ['tax_id' => '27865757000102', 'tax_regime' => 'presumed_profit', 'name' => 'Empresa']])
            ->assertUnprocessable()->assertJsonValidationErrors('limit');
        Http::assertNothingSent();
        $this->assertDatabaseCount('clients', 0);
        $this->assertDatabaseCount('erp_companies', 0);
    }

    public function test_account_assertion_and_real_admin_role_are_required_even_for_super_admin(): void
    {
        $ctx = $this->officeContext();
        $client = $this->clientFor($ctx);
        $this->actingAs($ctx->actor)->withHeader('Idempotency-Key', 'context-role-denial');
        $foreign = $this->officeContext();
        $this->withHeader('X-Account-Id', (string) $foreign->accountId)
            ->postJson('/api/v1/erp-commercial/companies', ['client_ids' => [$client->id]])->assertConflict();
        $ctx->actor->forceFill(['is_super_admin' => true])->save();
        DB::table('account_memberships')->where('account_id', $ctx->accountId)->where('user_id', $ctx->actor->id)->update(['role' => 'operador']);
        $this->withHeader('X-Account-Id', (string) $ctx->accountId)
            ->postJson('/api/v1/erp-commercial/companies', ['client_ids' => [$client->id]])->assertForbidden();
        $this->getJson('/api/v1/erp-commercial/companies')->assertForbidden();
        $this->assertDatabaseCount('erp_companies', 0);
    }

    public function test_role_loss_during_provider_preparation_is_rechecked_before_registration(): void
    {
        $ctx = $this->officeContext();
        Http::fake(function () use ($ctx) {
            DB::table('account_memberships')->where('account_id', $ctx->accountId)->where('user_id', $ctx->actor->id)->update(['role' => 'operador']);

            return Http::response([], 404);
        });
        $this->actingAs($ctx->actor)->withHeader('Idempotency-Key', 'registration-authority-loss')
            ->postJson('/api/v1/erp-commercial/companies', ['new_client' => ['tax_id' => '27865757000102', 'tax_regime' => 'presumed_profit', 'name' => 'Empresa de teste']])->assertForbidden();
        $this->assertDatabaseCount('clients', 0);
        $this->assertDatabaseCount('erp_companies', 0);
        $this->assertDatabaseCount('office_erp_commercial_operations', 0);
    }

    public function test_transferred_company_denies_old_registration_replay_lookup_and_new_batch_effects(): void
    {
        $ctx = $this->officeContext();
        $valid = $this->clientFor($ctx);
        $client = $this->clientFor($ctx);
        $first = $this->actingAs($ctx->actor)->withHeader('Idempotency-Key', 'before-company-transfer')
            ->postJson('/api/v1/erp-commercial/companies', ['client_ids' => [$client->id]])->assertCreated();
        $company = ErpCompany::query()->findOrFail($first->json('data.created.0.company_id'));
        $foreign = $this->officeContext();
        $contract = $this->acceptedContract($foreign);
        $this->withOfficeAccount($foreign, fn () => $contract->client()->update(['tax_id' => $company->cnpj]));
        $contract->forceFill(['erp_company_id' => $company->id])->save();
        $company->subscription()->update(['office_erp_contract_id' => $contract->id, 'status' => 'active']);
        $company->forceFill(['owner_setup_status' => 'claimed'])->save();
        ErpCompanyMembership::factory()->create(['erp_company_id' => $company->id]);

        $this->actingAs($ctx->actor)->withHeader('Idempotency-Key', 'before-company-transfer')
            ->postJson('/api/v1/erp-commercial/companies', ['client_ids' => [$client->id]])->assertForbidden();
        $this->getJson('/api/v1/erp-commercial/operations/before-company-transfer')->assertForbidden();
        $this->getJson('/api/v1/erp-commercial/companies/'.$company->id)->assertOk()->assertJsonPath('data.capabilities.relationship', 'historical');
        $this->withHeader('Idempotency-Key', 'after-company-transfer')
            ->postJson('/api/v1/erp-commercial/companies', ['client_ids' => [$valid->id, $client->id]])->assertForbidden();
        $this->assertDatabaseCount('office_erp_company_registrations', 1);
        $this->assertDatabaseCount('erp_companies', 1);
        $this->assertDatabaseCount('erp_subscriptions', 1);
        $this->assertDatabaseCount('office_erp_commercial_operations', 1);
    }

    public function test_provider_failure_during_new_registration_preserves_all_existing_data(): void
    {
        $ctx = $this->officeContext();
        Http::fake(['publica.cnpj.ws/*' => Http::response([], 503)]);
        $this->actingAs($ctx->actor)->withHeader('Idempotency-Key', 'new-registration-provider-failure')
            ->postJson('/api/v1/erp-commercial/companies', ['new_client' => ['tax_id' => '11222333000181', 'tax_regime' => 'simple_national']])->assertStatus(503);
        $this->assertDatabaseCount('clients', 0);
        $this->assertDatabaseCount('erp_companies', 0);
        $this->assertDatabaseCount('office_erp_company_registrations', 0);
    }
}
