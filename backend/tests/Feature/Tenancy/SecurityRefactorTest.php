<?php

namespace Tests\Feature\Tenancy;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\ClientCertificate;
use App\Models\ClientSavedFilter;
use App\Models\Department;
use App\Models\Plan;
use App\Models\Process;
use App\Models\ProcessTemplate;
use App\Models\ProcessTemplateTask;
use App\Models\SerproMonitoring;
use App\Models\Subscription;
use App\Models\SupportAccessLog;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TemplateClientException;
use App\Models\User;
use App\Services\PlanLimits;
use App\Tenant\CurrentTenant;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\BusinessTestCase;

class SecurityRefactorTest extends BusinessTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    public function test_calendar_requires_from_and_to(): void
    {
        $member = $this->memberOf(Account::factory()->create(), 'operador');

        $this->actingAs($member, 'web')->getJson('/api/v1/work/calendar')->assertStatus(422);
    }

    public function test_tenant_models_do_not_mass_assign_account_id_but_inherit_it(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();

        $attempt = new Client(['account_id' => 999, 'name' => 'Tentativa']);

        $this->assertArrayNotHasKey('account_id', $attempt->getAttributes());

        $created = Client::query()->create(['name' => 'Herdado']);

        $this->assertSame($account->getKey(), $created->account_id);
        $this->assertSame($account->getKey(), $created->refresh()->account_id);
    }

    public function test_all_tenant_models_exclude_account_id_from_fillable(): void
    {
        $guarded = [
            Client::class,
            ClientCertificate::class,
            ProcessTemplate::class,
            ProcessTemplateTask::class,
            Tag::class,
            Department::class,
            Process::class,
            Task::class,
            SerproMonitoring::class,
            ClientSavedFilter::class,
            TemplateClientException::class,
            SupportAccessLog::class,
            Subscription::class,
        ];

        foreach ($guarded as $class) {
            $this->assertNotContains(
                'account_id',
                (new $class)->getFillable(),
                "{$class} must not list account_id as fillable"
            );
        }

        $this->assertContains('account_id', (new AccountUser)->getFillable());
    }

    public function test_sensitive_business_lookup_is_throttled_after_ten_attempts(): void
    {
        $member = $this->memberOf(Account::factory()->create(), 'operador');
        $this->actingAs($member);
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/v1/clients/cnpj-lookup', ['tax_id' => 'invalid'])->assertUnprocessable();
        }
        $this->postJson('/api/v1/clients/cnpj-lookup', ['tax_id' => 'invalid'])->assertStatus(429);
    }

    public function test_register_is_throttled_after_five_attempts(): void
    {
        $csrf = $this->getJson('/api/v1/csrf')->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $csrf);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/register', [])->assertUnprocessable();
        }
        $this->postJson('/api/v1/register', [])->assertStatus(429);
    }

    public function test_plan_limits_are_null_safe_and_cast_to_int(): void
    {
        $orphan = Account::withoutEvents(fn (): Account => Account::factory()->create());

        $this->assertNull($orphan->subscription);

        PlanLimits::assertCanCreate($orphan, 'clients');

        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $plan = Plan::bySlug('basico');
        $plan->forceFill(['limits' => ['users' => 5, 'clients' => '1']])->save();

        Client::factory()->create(['account_id' => $account->getKey()]);
        resolve(CurrentTenant::class)->accountId = $account->id;

        try {
            PlanLimits::assertCanCreate($account->refresh(), 'clients');

            $this->fail('Expected ValidationException when the string limit is reached.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('limit', $exception->errors());
        }
    }

    public function test_account_role_reflects_membership_changes_without_stale_permissions(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $member = $this->memberOf($account, 'operador');
        $this->assertSame('operador', $member->accountRole($account));

        AccountMembership::where('account_id', $account->id)->where('user_id', $member->id)->update(['role' => 'user']);
        $this->assertSame('user', $member->accountRole($account->id));
        AccountMembership::where('account_id', $account->id)->where('user_id', $member->id)->delete();
        $this->assertNull($member->accountRole($account));
        $this->assertNull($member->accountRole(Account::factory()->create()));
    }

    private function memberOf(Account $account, string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);

        AccountUser::create([
            'account_id' => $account->getKey(),
            'user_id' => $user->getKey(),
            'role' => $role,
        ]);

        $this->selectBusinessAccount($user, $account->getKey());

        return $user->refresh();
    }
}
