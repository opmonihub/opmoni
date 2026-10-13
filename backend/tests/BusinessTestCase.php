<?php

namespace Tests;

use App\Models\AccountMembership;
use App\Models\User;
use App\Tenant\CurrentTenant;
use Illuminate\Contracts\Auth\Authenticatable;

abstract class BusinessTestCase extends TestCase
{
    /** @var array<int, int> */
    private array $selectedBusinessAccounts = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['access.ingress_key' => 'business-test-key', 'access.origin' => 'http://localhost:3000', 'cache.default' => 'array', 'session.driver' => 'array', 'session.store' => null]);
        $this->withHeaders(['X-Opmoni-Ingress-Key' => 'business-test-key', 'X-Opmoni-Client-IP' => '127.0.0.1', 'X-Opmoni-Client-Proto' => 'http', 'Origin' => 'http://localhost:3000']);
    }

    protected function selectBusinessAccount(User $user, int $accountId): User
    {
        $this->selectedBusinessAccounts[(int) $user->id] = $accountId;

        return $user;
    }

    public function actingAs(Authenticatable $user, $guard = null)
    {
        parent::actingAs($user, 'web');
        $accountId = $this->selectedBusinessAccounts[(int) $user->getAuthIdentifier()] ?? AccountMembership::where('user_id', $user->getAuthIdentifier())->orderBy('id')->value('account_id');
        $token = bin2hex(random_bytes(20));
        $this->withSession(['access_authenticated' => true, 'access_deadline' => time() + 43200, 'current_account_id' => $accountId, '_token' => $token]);
        $this->withHeader('X-CSRF-Token', $token);
        if ($accountId !== null) {
            $this->withHeader('X-Account-Id', (string) $accountId);
        }
        resolve(CurrentTenant::class)->accountId = $accountId === null ? null : (int) $accountId;

        return $this;
    }
}
