<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccessFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['access.ingress_key' => 'test-key', 'cache.default' => 'array', 'session.driver' => 'array', 'session.store' => null]);
        $this->withHeaders(['X-Opmoni-Ingress-Key' => 'test-key', 'X-Opmoni-Client-IP' => '127.0.0.1', 'X-Opmoni-Client-Proto' => 'http', 'Origin' => 'http://localhost:3000']);
    }

    public function test_bootstrap_and_safe_identity(): void
    {
        $token = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $token)->postJson('/api/v1/register', ['name' => 'Initial Owner', 'email' => 'OWNER@example.com', 'password' => ' 密碼abcdef  ', 'company' => 'Office One', 'size' => 'solo'])->assertCreated()->assertJsonPath('is_super_admin', true)->assertJsonPath('current_account.name', 'Office One')->assertJsonMissingPath('password');
        $this->getJson('/api/v1/registration-status')->assertJsonPath('registration_available', false);
    }

    public function test_missing_csrf_and_direct_ingress_are_denied(): void
    {
        $this->postJson('/api/v1/login', ['email' => 'a@example.com', 'password' => 'abcdefghi'])->assertForbidden();
        $this->withHeader('X-Opmoni-Ingress-Key', 'bad')->getJson('/api/v1/csrf')->assertForbidden();
    }

    public function test_unauthenticated_api_returns_json_for_browser_accept_headers(): void
    {
        foreach (['text/html', '*/*'] as $accept) {
            $this->withHeader('Accept', $accept)->get('/api/v1/me')
                ->assertUnauthorized()->assertJsonPath('code', 'unauthenticated')
                ->assertHeader('Content-Type', 'application/json')->assertHeaderMissing('Location');
        }
    }

    private function csrf(): string
    {
        return $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
    }

    private function login(User $user, string $password = 'password'): void
    {
        $this->withHeader('X-CSRF-Token', $this->csrf())->postJson('/api/v1/login', ['email' => strtoupper($user->email), 'password' => $password])->assertOk();
    }

    public function test_login_selection_switching_and_lifecycle(): void
    {
        $user = User::factory()->create(['is_super_admin' => true]);
        $a = Account::factory()->create();
        $b = Account::factory()->create();
        foreach ([$b, $a] as $account) {
            AccountMembership::factory()->create(['account_id' => $account->id, 'user_id' => $user->id]);
        }
        $this->login($user);
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('current_account.id', $b->id)->assertJsonCount(2, 'accounts');
        $this->withHeader('X-CSRF-Token', $this->csrf())->postJson('/api/v1/account/switch', ['account_id' => $a->id])->assertOk()->assertJsonPath('current_account.id', $a->id);
        $this->withHeader('X-CSRF-Token', $this->csrf())->postJson('/api/v1/account/switch', ['account_id' => 999])->assertForbidden();
        $this->getJson('/api/v1/me')->assertJsonPath('current_account.id', $a->id);
        $this->login($user);
        $this->getJson('/api/v1/me')->assertJsonPath('current_account.id', $b->id);
        $this->withSession(['current_account_id' => null])->getJson('/api/v1/me')->assertOk()->assertJsonPath('current_account.id', $b->id);
        $this->withHeader('X-CSRF-Token', $this->csrf())->postJson('/api/v1/account/switch', ['account_id' => $a->id])->assertOk();
        $this->withHeader('X-CSRF-Token', $this->csrf())->patchJson('/api/v1/admin/accounts/'.$a->id, ['name' => 'Renamed Office', 'status' => 'suspended'])->assertOk()->assertJsonPath('data.name', 'Renamed Office');
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('current_account.status', 'suspended');
        $this->withHeader('X-CSRF-Token', $this->csrf())->postJson('/api/v1/account/switch', ['account_id' => $a->id])->assertForbidden()->assertJsonPath('code', 'account_suspended');
        $this->login($user);
        $this->getJson('/api/v1/me')->assertJsonPath('current_account.id', $b->id);
        $this->withHeader('X-CSRF-Token', $this->csrf())->postJson('/api/v1/logout')->assertNoContent();
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_platform_issue_reissue_and_activation_preserves_exact_password(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $this->login($admin);
        $data = ['name' => 'Second Office', 'owner_name' => 'New Owner', 'login_email' => ' NEW@example.com '];
        $created = $this->withHeader('X-CSRF-Token', $this->csrf())->postJson('/api/v1/admin/accounts', $data)->assertCreated();
        $id = $created->json('data.id');
        parse_str(parse_url($created->json('first_access.url'), PHP_URL_QUERY), $old);
        $this->getJson('/api/v1/admin/accounts')->assertOk()->assertJsonMissingPath('data.0.token_hash');
        $reissued = $this->withHeader('X-CSRF-Token', $this->csrf())->postJson('/api/v1/admin/accounts/'.$id.'/first-access', ['owner_name' => 'New Owner', 'login_email' => 'new@example.com'])->assertOk();
        parse_str(parse_url($reissued->json('first_access.url'), PHP_URL_QUERY), $link);
        $this->getJson('/api/v1/first-access/status?'.http_build_query($old))->assertOk()->assertJsonPath('valid', false);
        $this->getJson('/api/v1/first-access/status?'.http_build_query($link))->assertOk()->assertJsonPath('valid', true);
        $password = ' 密碼'.str_repeat('é', 90).' ';
        $this->withHeader('X-CSRF-Token', $this->csrf())->postJson('/api/v1/first-access', [...$link, 'password' => $password, 'password_confirmation' => $password])->assertCreated()->assertJsonPath('current_account.id', $id);
        $owner = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertTrue(Hash::check($password, $owner->password));
        $this->assertFalse(Hash::check(trim($password), $owner->password));
        $this->withHeader('X-CSRF-Token', $this->csrf())->postJson('/api/v1/first-access', [...$link, 'password' => $password, 'password_confirmation' => $password])->assertUnprocessable();
        $this->getJson('/api/v1/admin/accounts')->assertForbidden();
    }

    public function test_invalid_credentials_and_unknown_fields(): void
    {
        $user = User::factory()->create();
        foreach ([$user->email, 'unknown@example.com'] as $email) {
            $this->withHeader('X-CSRF-Token', $this->csrf())->postJson('/api/v1/login', ['email' => $email, 'password' => 'wrong'])->assertUnprocessable()->assertJsonPath('message', 'Email ou senha inválidos');
        }
        $this->withHeader('X-CSRF-Token', $this->csrf())->postJson('/api/v1/register', ['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'password', 'company' => 'Office', 'size' => 'solo', 'is_super_admin' => true])->assertUnprocessable()->assertJsonValidationErrors('is_super_admin');
    }

    public function test_expired_session_rotates_csrf_and_rejects_the_old_token(): void
    {
        $oldToken = $this->csrf();
        $freshToken = $this->withSession(['access_deadline' => time() - 1])
            ->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->assertIsString($freshToken);
        $this->assertNotSame($oldToken, $freshToken);
        $this->withHeader('X-CSRF-Token', $oldToken)->postJson('/api/v1/login', ['email' => 'unknown@example.com', 'password' => 'password'])->assertForbidden();
        $this->withHeader('X-CSRF-Token', $freshToken)->postJson('/api/v1/login', ['email' => 'unknown@example.com', 'password' => 'password'])->assertUnprocessable();
    }

    public function test_bcrypt_credentials_are_checked_before_migrating_to_argon(): void
    {
        $hash = Hash::driver('bcrypt')->make(' exact password ');
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['password' => $hash]);
        $user->refresh();
        $this->withHeader('X-CSRF-Token', $this->csrf())->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnprocessable()->assertJsonPath('message', 'Email ou senha inválidos');
        $this->assertSame($hash, $user->fresh()->password);
        $this->login($user, ' exact password ');
        $this->assertSame('argon2id', password_get_info($user->fresh()->password)['algoName']);
        $this->assertTrue(Hash::check(' exact password ', $user->fresh()->password));
        $this->assertFalse(Hash::check('exact password', $user->fresh()->password));
    }

    public function test_bcrypt_does_not_accept_passwords_with_a_truncated_suffix(): void
    {
        $password = str_repeat('a', 72);
        $hash = Hash::driver('bcrypt')->make($password);
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['password' => $hash]);
        $user->refresh();
        $this->withHeader('X-CSRF-Token', $this->csrf())->postJson('/api/v1/login', ['email' => $user->email, 'password' => $password.'suffix'])->assertUnprocessable();
        $this->assertSame($hash, $user->fresh()->password);
    }

    public function test_hostile_origin_and_rate_limits(): void
    {
        $this->withHeaders(['Origin' => 'https://hostile.example', 'X-CSRF-Token' => $this->csrf()])->postJson('/api/v1/login', ['email' => 'a@example.com', 'password' => 'password'])->assertForbidden();
        for ($i = 0; $i < 29; $i++) {
            $this->getJson('/api/v1/csrf')->assertOk();
        }
        $this->getJson('/api/v1/csrf')->assertStatus(429)->assertHeader('Retry-After');
    }
}
