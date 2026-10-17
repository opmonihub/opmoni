<?php

namespace Tests\Feature\Fiscal;

use App\Enums\FiscalProcessingState;
use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Models\User;
use App\Services\Fiscal\Read\FiscalProcessing;
use App\Tenant\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\BusinessTestCase;

class FiscalProcessingApiTest extends BusinessTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('fiscal');
    }

    public function test_processing_list_excludes_ready_documents(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $cliente = Client::factory()->company()->create(['account_id' => $account->getKey()]);

        FiscalDocument::factory()->summary()->create([
            'account_id' => $account->getKey(),
            'client_id' => $cliente->getKey(),
            'processing_state' => FiscalProcessingState::AwaitingScience,
        ]);

        FiscalDocument::factory()->withStoredXml()->create([
            'account_id' => $account->getKey(),
            'client_id' => $cliente->getKey(),
            'processing_state' => FiscalProcessingState::Complete,
        ]);

        $page = resolve(FiscalProcessing::class)->page($account->getKey(), []);
        $this->assertSame(1, $page['rows']->total());
        $this->assertSame(1, $page['summary']['active']);

        $this->actingAs($this->membroDe($account, 'operador'), 'web')
            ->getJson('/api/v1/fiscal/processing')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('summary.active', 1);
    }

    private function membroDe(Account $account, string $role): User
    {
        $user = User::factory()->create();
        AccountUser::create([
            'account_id' => $account->getKey(),
            'user_id' => $user->getKey(),
            'role' => $role,
        ]);
        $this->selectBusinessAccount($user, $account->getKey());

        return $user->refresh();
    }
}
