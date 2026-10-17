<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpOfficeDelegation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ErpCompanySchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_additive_tables_do_not_replace_office_identity_tables(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create();

        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasTable('accounts'));
        $this->assertTrue(Schema::hasTable('clients'));
        $this->assertTrue(Schema::hasTable('erp_companies'));
        $this->assertSame(1, User::query()->whereKey($user->id)->count());
        $this->assertSame(1, Account::query()->whereKey($account->id)->count());
        $this->assertSame(0, DB::table('clients')->count());
    }

    public function test_duplicate_company_membership_is_rejected(): void
    {
        $user = User::factory()->create();
        $company = ErpCompany::factory()->create();
        ErpCompanyMembership::factory()->create(['erp_company_id' => $company->id, 'user_id' => $user->id]);

        $this->expectException(QueryException::class);
        ErpCompanyMembership::factory()->create(['erp_company_id' => $company->id, 'user_id' => $user->id]);
    }

    public function test_foreign_keys_reference_global_users_and_office_accounts(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create();
        $company = ErpCompany::factory()->create();
        ErpCompanyMembership::factory()->create(['erp_company_id' => $company->id, 'user_id' => $user->id]);
        ErpOfficeDelegation::factory()->create([
            'erp_company_id' => $company->id,
            'account_id' => $account->id,
            'authorized_by_user_id' => $user->id,
        ]);

        $user->delete();
        $this->assertSame(0, ErpCompanyMembership::query()->count());
        $this->assertSame(0, ErpOfficeDelegation::query()->count());
        $this->assertSame(1, ErpCompany::query()->count());
    }

    public function test_application_enforces_single_active_delegation_per_company(): void
    {
        $company = ErpCompany::factory()->create();
        $officeA = Account::factory()->create();
        $officeB = Account::factory()->create();
        $owner = User::factory()->create();

        ErpOfficeDelegation::factory()->create([
            'erp_company_id' => $company->id,
            'account_id' => $officeA->id,
            'authorized_by_user_id' => $owner->id,
            'status' => 'active',
        ]);
        ErpOfficeDelegation::query()
            ->where('erp_company_id', $company->id)
            ->where('status', 'active')
            ->update(['status' => 'revoked', 'revoked_at' => now()]);

        ErpOfficeDelegation::factory()->create([
            'erp_company_id' => $company->id,
            'account_id' => $officeB->id,
            'authorized_by_user_id' => $owner->id,
            'status' => 'active',
        ]);

        $this->assertSame(1, ErpOfficeDelegation::query()->where('erp_company_id', $company->id)->where('status', 'active')->count());
    }
}
