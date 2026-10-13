<?php

namespace Tests\Feature\Fiscal;

use App\Models\Account;
use App\Models\Client;
use App\Models\FiscalManifestation;
use App\Services\Fiscal\Manifestacao\ManifestacaoDispatcher;
use App\Tenant\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\BusinessTestCase;

class ManifestacaoDuplicidadeTest extends BusinessTestCase
{
    use RefreshDatabase;

    public function test_duas_ciencias_da_mesma_chave_deixam_um_registro(): void
    {
        config(['fiscal.manifestacao_enabled' => true]);

        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $client = Client::factory()->individual()->create(['account_id' => $account->getKey()]);
        $chave = str_repeat('5', 44);

        Bus::fake();

        $dispatcher = resolve(ManifestacaoDispatcher::class);

        $this->assertTrue($dispatcher->enfileirarCiencia($account->getKey(), $client, $chave));

        $primeiro = FiscalManifestation::withoutGlobalScope('account')->firstOrFail();
        $primeiroUpdatedAt = $primeiro->updated_at;

        $this->assertTrue($dispatcher->enfileirarCiencia($account->getKey(), $client, $chave));

        $this->assertSame(1, FiscalManifestation::withoutGlobalScope('account')->count());

        $segundo = FiscalManifestation::withoutGlobalScope('account')->firstOrFail();

        $this->assertSame($primeiro->getKey(), $segundo->getKey());
        $this->assertTrue($segundo->updated_at->greaterThanOrEqualTo($primeiroUpdatedAt));
    }
}
