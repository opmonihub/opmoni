<?php

namespace Tests\Feature\Fiscal;

use App\Enums\FiscalProcessingState;
use App\Jobs\RecoverFiscalXmlJob;
use App\Models\Account;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Tenant\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\BusinessTestCase;

class DispatchFiscalProcessingDueWorkTest extends BusinessTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['fiscal.manifestacao_enabled' => true, 'fiscal.manifestacao_rollout_mode' => 'fleet']);
    }

    public function test_command_dispatches_recovery_for_awaiting_xml(): void
    {
        Bus::fake([RecoverFiscalXmlJob::class]);

        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $cliente = Client::factory()->company()->create(['account_id' => $account->getKey()]);

        FiscalDocument::factory()->summary()->create([
            'account_id' => $account->getKey(),
            'client_id' => $cliente->getKey(),
            'processing_state' => FiscalProcessingState::AwaitingXml,
            'next_attempt_at' => now()->subMinute(),
        ]);

        $this->artisan('fiscal:dispatch-processing-due')->assertSuccessful();

        Bus::assertDispatched(RecoverFiscalXmlJob::class);
    }
}
