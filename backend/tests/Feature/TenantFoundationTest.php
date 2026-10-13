<?php

namespace Tests\Feature;

use App\Http\Middleware\AccessSecurity;
use App\Http\Middleware\ResolveAccount;
use App\Http\Middleware\SerializedAccessSession;
use App\Http\Middleware\TrustedIngress;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Concerns\BelongsToAccount;
use App\Models\User;
use App\Support\CurrentAccount;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class TenantFixture extends Model
{
    use BelongsToAccount;

    protected $table = 'tenant_fixtures';

    protected $guarded = [];
}
class TenantFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['access.ingress_key' => 'test-key', 'cache.default' => 'array', 'session.driver' => 'array', 'session.store' => null]);
        Schema::create('tenant_fixtures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained();
            $table->string('name');
            $table->timestamps();
        });
        Route::middleware([TrustedIngress::class, EncryptCookies::class, AddQueuedCookiesToResponse::class, SerializedAccessSession::class, AccessSecurity::class, 'auth', ResolveAccount::class])->group(function (): void {
            Route::get('/api/v1/testing/tenant/{id}', fn (int $id) => response()->json(TenantFixture::findOrFail($id)->only(['id', 'name'])));
            Route::post('/api/v1/testing/tenant', function (Request $request) {
                CurrentAccount::fromRequest()->assertWrite();
                $request->validate(['related_id' => ['sometimes', Rule::exists('tenant_fixtures', 'id')->where('account_id', CurrentAccount::fromRequest()->account->id)]]);

                return response()->json(TenantFixture::create(['name' => 'Written'])->only(['id', 'name']), 201);
            });
        });
        $this->withHeaders(['X-Opmoni-Ingress-Key' => 'test-key', 'X-Opmoni-Client-IP' => '127.0.0.1', 'X-Opmoni-Client-Proto' => 'http', 'Origin' => 'http://localhost:3000']);
    }

    private function authenticate(string $role = 'admin'): array
    {
        $user = User::factory()->create(['is_super_admin' => true]);
        $a = Account::factory()->create();
        $b = Account::factory()->create();
        AccountMembership::factory()->create(['account_id' => $a->id, 'user_id' => $user->id, 'role' => $role]);
        $id = DB::table('tenant_fixtures')->insertGetId(['account_id' => $a->id, 'name' => 'Own record']);
        $foreign = DB::table('tenant_fixtures')->insertGetId(['account_id' => $b->id, 'name' => 'Foreign record']);
        $csrf = $this->getJson('/api/v1/csrf')->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $csrf)->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $csrf = $this->getJson('/api/v1/csrf')->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $csrf);

        return [$user, $a, $b, $id, $foreign];
    }

    public function test_foreign_records_assertions_payload_and_related_boundaries(): void
    {
        [$user,$a,$b,$id,$foreign] = $this->authenticate();
        $this->getJson('/api/v1/testing/tenant/'.$id)->assertUnprocessable();
        $this->withHeader('X-Account-Id', $b->id)->getJson('/api/v1/testing/tenant/'.$id)->assertStatus(409)->assertJsonPath('code', 'account_context_changed');
        $this->withHeader('X-Account-Id', $a->id)->getJson('/api/v1/testing/tenant/'.$id)->assertOk()->assertJsonPath('name', 'Own record');
        $this->getJson('/api/v1/testing/tenant/'.$foreign)->assertNotFound();
        $this->postJson('/api/v1/testing/tenant', ['account_id' => $b->id])->assertUnprocessable();
        $this->postJson('/api/v1/testing/tenant', ['related_id' => $foreign])->assertUnprocessable();
        $this->postJson('/api/v1/testing/tenant')->assertCreated();
        $a->update(['status' => 'suspended']);
        $this->getJson('/api/v1/testing/tenant/'.$id)->assertForbidden()->assertJsonPath('code', 'account_suspended');
        $this->getJson('/api/v1/me')->assertOk();
    }

    public function test_read_only_role_and_membership_revocation(): void
    {
        [$user,$a,$b,$id] = $this->authenticate('user');
        $this->withHeader('X-Account-Id', $a->id)->getJson('/api/v1/testing/tenant/'.$id)->assertOk();
        $this->postJson('/api/v1/testing/tenant')->assertForbidden();
        AccountMembership::where('user_id', $user->id)->delete();
        $this->getJson('/api/v1/testing/tenant/'.$id)->assertForbidden()->assertJsonPath('code', 'membership_required');
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('current_account', null);
    }

    public function test_model_scope_fails_closed_without_request_context(): void
    {
        $this->expectException(HttpException::class);
        TenantFixture::count();
    }
}
