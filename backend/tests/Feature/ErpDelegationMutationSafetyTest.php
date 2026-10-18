<?php

namespace Tests\Feature;

use App\Erp\ErpCompanyAuthorization;
use App\Models\Account;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpOfficeDelegation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ErpDelegationMutationSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_acceptance_rolls_back_when_session_fence_is_lost_after_mutation(): void
    {
        $company = ErpCompany::factory()->create();
        $owner = User::factory()->create();
        ErpCompanyMembership::factory()->create(['erp_company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner']);
        $office = Account::factory()->create();
        $owned = true;
        ErpOfficeDelegation::created(function () use (&$owned): void {
            $owned = false;
        });

        try {
            app(ErpCompanyAuthorization::class)->acceptDelegation($company, $owner, $office->id, ['erp.read'], function () use (&$owned): void {
                abort_unless($owned, 503);
            });
            $this->fail('A lost session fence must abort delegation acceptance.');
        } catch (HttpException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('erp_office_delegations', 0);
    }

    public function test_revocation_rolls_back_when_session_fence_is_lost_after_mutation(): void
    {
        $delegation = ErpOfficeDelegation::factory()->create();
        $owner = User::factory()->create();
        ErpCompanyMembership::factory()->create(['erp_company_id' => $delegation->erp_company_id, 'user_id' => $owner->id, 'role' => 'owner']);
        $owned = true;
        ErpOfficeDelegation::updated(function () use (&$owned): void {
            $owned = false;
        });

        try {
            app(ErpCompanyAuthorization::class)->revokeDelegation($delegation, $owner, function () use (&$owned): void {
                abort_unless($owned, 503);
            });
            $this->fail('A lost session fence must abort delegation revocation.');
        } catch (HttpException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
        }
        $this->assertSame('active', $delegation->fresh()->status);
    }

    public function test_owner_authorization_is_rechecked_after_company_lock(): void
    {
        $company = ErpCompany::factory()->create();
        $owner = User::factory()->create();
        $membership = ErpCompanyMembership::factory()->create(['erp_company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner']);
        $office = Account::factory()->create();
        $changed = false;
        DB::listen(function ($query) use ($membership, &$changed): void {
            if (! $changed && str_starts_with($query->sql, 'select') && str_contains($query->sql, '"erp_companies"')) {
                $changed = true;
                $membership->update(['role' => 'viewer']);
            }
        });

        try {
            app(ErpCompanyAuthorization::class)->acceptDelegation($company, $owner, $office->id, ['erp.read']);
            $this->fail('A former owner must not authorize a delegation.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertTrue($changed);
        $this->assertDatabaseCount('erp_office_delegations', 0);
    }

    #[DataProvider('delegationMutations')]
    public function test_http_mutation_supplies_the_session_fence(string $action): void
    {
        config(['access.ingress_key' => 'test-key', 'cache.default' => 'array', 'session.driver' => 'array', 'session.store' => null]);
        $this->withHeaders(['X-Opmoni-Ingress-Key' => 'test-key', 'X-Opmoni-Client-IP' => '127.0.0.1', 'X-Opmoni-Client-Proto' => 'http', 'Origin' => 'http://localhost:3000']);
        $company = ErpCompany::factory()->create();
        $owner = User::factory()->create();
        ErpCompanyMembership::factory()->create(['erp_company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner']);
        $office = Account::factory()->create();
        $delegation = ErpOfficeDelegation::factory()->create(['erp_company_id' => $company->id, 'account_id' => $office->id]);
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $csrf)->postJson('/api/v1/login', ['email' => $owner->email, 'password' => 'password'])->assertOk();
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $csrf);
        $loseOwnership = function (): void {
            request()->attributes->get('access.session_lock')->forceRelease();
        };
        if ($action === 'accept') {
            ErpOfficeDelegation::created($loseOwnership);
            $this->postJson('/api/v1/erp/delegations', ['company_id' => $company->id, 'account_id' => $office->id])->assertStatus(503);
        } else {
            ErpOfficeDelegation::updated($loseOwnership);
            $this->deleteJson('/api/v1/erp/delegations/'.$delegation->id)->assertStatus(503);
        }
        $this->assertDatabaseCount('erp_office_delegations', 1);
        $this->assertSame('active', $delegation->fresh()->status);
    }

    public static function delegationMutations(): array
    {
        return [['accept'], ['revoke']];
    }
}
