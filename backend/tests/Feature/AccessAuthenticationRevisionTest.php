<?php

namespace Tests\Feature;

use App\Actions\AccessAccounts;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccessAuthenticationRevisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['access.ingress_key' => 'test-key', 'access.origin' => 'http://localhost:3000', 'cache.default' => 'array', 'session.driver' => 'array', 'session.store' => null]);
        $this->withHeaders(['X-Opmoni-Ingress-Key' => 'test-key', 'X-Opmoni-Client-IP' => '127.0.0.1', 'X-Opmoni-Client-Proto' => 'http', 'Origin' => 'http://localhost:3000']);
    }

    public function test_password_login_captures_the_current_revision_in_the_session(): void
    {
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['access_auth_revision' => 7]);
        $csrf = $this->getJson('/api/v1/csrf')->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $csrf)->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()->assertSessionHas('access_auth_revision', 7);
        $this->getJson('/api/v1/me')->assertOk();
    }

    public function test_an_old_revision_is_denied_even_when_the_guard_holds_a_cached_user(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web')->withSession(['access_authenticated' => true, 'access_deadline' => time() + 43200, 'access_auth_revision' => 0]);
        $this->getJson('/api/v1/me')->assertOk();
        DB::table('users')->where('id', $user->id)->update(['access_auth_revision' => 1]);
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertGuest('web');
    }

    public function test_missing_legacy_revision_is_accepted_only_for_revision_zero(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web')->withSession(['access_authenticated' => true, 'access_deadline' => time() + 43200]);
        $this->getJson('/api/v1/me')->assertOk();
        DB::table('users')->where('id', $user->id)->update(['access_auth_revision' => 1]);
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_a_future_revision_does_not_authenticate_a_user(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web')->withSession(['access_authenticated' => true, 'access_deadline' => time() + 43200, 'access_auth_revision' => 1]);
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_captured_password_proof_is_not_upgraded_by_later_user_changes(): void
    {
        $user = User::factory()->create();
        [$verified, $revision] = app(AccessAccounts::class)->authenticatePassword($user->email, 'password');
        self::assertSame($user->id, $verified->id);
        self::assertSame(0, $revision);
        DB::table('users')->where('id', $user->id)->update(['access_auth_revision' => 1]);
        self::assertSame(0, $revision);
        $this->actingAs($verified, 'web')->withSession(['access_authenticated' => true, 'access_deadline' => time() + 43200, 'access_auth_revision' => $revision]);
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }
}
