<?php

namespace Tests\Feature\Fiscal;

use App\Enums\FiscalManifestationEventType;
use App\Enums\FiscalManifestationOutcome;
use App\Jobs\SendFiscalManifestationJob;
use App\Models\Account;
use App\Models\Client;
use App\Models\FiscalManifestation;
use App\Services\Fiscal\Manifestacao\FiscalManifestationStore;
use App\Services\Fiscal\Manifestacao\RecepcaoEventoConnector;
use App\Tenant\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManifestacaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_conta_nao_enxerga_manifestacao_de_outra(): void
    {
        $conta = Account::factory()->create();
        $outra = Account::factory()->create();

        resolve(CurrentTenant::class)->accountId = $conta->getKey();

        $cliente = Client::factory()->individual()->create(['account_id' => $conta->getKey()]);
        $manifestacao = FiscalManifestation::factory()->create([
            'account_id' => $conta->getKey(),
            'client_id' => $cliente->getKey(),
        ]);

        $clienteEstrangeiro = Client::factory()->individual()->create(['account_id' => $outra->getKey()]);
        $manifestacaoEstrangeira = FiscalManifestation::factory()->create([
            'account_id' => $outra->getKey(),
            'client_id' => $clienteEstrangeiro->getKey(),
        ]);

        $this->assertSame($outra->getKey(), $manifestacaoEstrangeira->account_id);

        resolve(CurrentTenant::class)->accountId = $conta->getKey();

        $this->assertSame([$manifestacao->getKey()], FiscalManifestation::query()->pluck('id')->all());
        $this->assertNull(FiscalManifestation::find($manifestacaoEstrangeira->getKey()));
        $this->assertTrue(
            FiscalManifestation::withoutGlobalScope('account')->whereKey($manifestacaoEstrangeira->getKey())->exists()
        );
    }

    public function test_job_com_account_id_errado_nao_age_no_cliente(): void
    {
        $conta = Account::factory()->create();
        $outra = Account::factory()->create();
        $cliente = Client::factory()->individual()->create(['account_id' => $conta->getKey()]);

        $chave = str_repeat('1', 44);

        resolve(FiscalManifestationStore::class)->registrarPedido(
            accountId: $conta->getKey(),
            clientId: $cliente->getKey(),
            chaveAcesso: $chave,
            eventType: FiscalManifestationEventType::CienciaEmissao,
            eventSeq: 1,
            requestedBy: 'job:SendFiscalManifestationJob',
        );

        config(['fiscal.manifestacao_enabled' => true]);

        $job = new SendFiscalManifestationJob(
            accountId: $outra->getKey(),
            clientId: $cliente->getKey(),
            chaveAcesso: $chave,
            eventType: FiscalManifestationEventType::CienciaEmissao,
            eventSeq: 1,
        );

        $job->handle(
            resolve(FiscalManifestationStore::class),
            resolve(RecepcaoEventoConnector::class),
        );

        $registro = FiscalManifestation::withoutGlobalScope('account')->firstOrFail();

        $this->assertSame(FiscalManifestationOutcome::Pending, $registro->outcome);
        $this->assertNull($registro->sent_at);
    }

    public function test_job_com_account_id_explicito_encontra_o_cliente(): void
    {
        $conta = Account::factory()->create();
        $cliente = Client::factory()->individual()->create(['account_id' => $conta->getKey()]);
        $chave = str_repeat('2', 44);

        resolve(FiscalManifestationStore::class)->registrarPedido(
            accountId: $conta->getKey(),
            clientId: $cliente->getKey(),
            chaveAcesso: $chave,
            eventType: FiscalManifestationEventType::CienciaEmissao,
            eventSeq: 1,
            requestedBy: 'job:SendFiscalManifestationJob',
        );

        config(['fiscal.manifestacao_enabled' => true]);

        resolve(CurrentTenant::class)->accountId = null;

        $job = new SendFiscalManifestationJob(
            accountId: $conta->getKey(),
            clientId: $cliente->getKey(),
            chaveAcesso: $chave,
            eventType: FiscalManifestationEventType::CienciaEmissao,
            eventSeq: 1,
        );

        $job->handle(
            resolve(FiscalManifestationStore::class),
            resolve(RecepcaoEventoConnector::class),
        );

        $registro = FiscalManifestation::withoutGlobalScope('account')->firstOrFail();

        // O cliente foi achado e o job executou: sem resumo gravado para a
        // chave, `emissao_at` é nulo e o conector lê ausência de data como
        // prazo não verificável — o veredito `deadline_missed` é a prova de
        // que a execução chegou ao conector, coisa que `pending` não provaria.
        $this->assertSame(FiscalManifestationOutcome::DeadlineMissed, $registro->outcome);
    }
}
