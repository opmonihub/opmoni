<?php

namespace Tests\Feature\Fiscal;

use App\Models\Account;
use App\Models\Client;
use App\Models\ClientCertificate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * O comando de canário `fiscal:nfse-probe` no grupo opt-in `nfse-live`, fora
 * da suíte padrão como o `serpro-trial`.
 *
 * ⚠️ O grupo é a forma de **rodar a suíte inteira fora do CI**, não uma
 * promessa de tráfego real: o phpunit força `DB_CONNECTION=sqlite` e
 * `DB_DATABASE=:memory:` para a suíte toda, então este arquivo não pode
 * depender de um banco preparado — o cliente do probe é semeado na hora pela
 * factory, e a chamada HTTP é `Http::fake()` como em todo teste do módulo.
 * A corrida contra a ADN de verdade continua sendo o passo manual do
 * canário: `php artisan fiscal:nfse-probe --client=<id>` com
 * `FISCAL_NFSE_ENABLED=true`.
 *
 * O que este arquivo garante é o caminho do comando dentro da suíte: a
 * resolução do cliente configurado, a recusa honesta quando a instalação não
 * está preparada e o GET único imprimindo só metadados seguros.
 */
#[Group('nfse-live')]
class NfseAdnLiveProbeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('certificates');
        Storage::fake('local');
        Http::preventStrayRequests();
    }

    /**
     * O canário configurado é o que o probe consulta: o `fiscal.nfse_live_client`
     * aponta um cliente capturável, o comando o encontra no banco do teste e o
     * GET único sai — aqui dentro, para o `Http::fake()` da fixture real.
     */
    public function test_o_probe_consulta_o_cliente_do_canario_e_imprime_so_metadados_seguros(): void
    {
        config(['fiscal.nfse_enabled' => true]);

        [$client] = $this->clienteComCertificado();
        config(['fiscal.nfse_live_client' => $client->getKey()]);

        Http::fake(['*' => Http::response(
            file_get_contents(base_path('tests/Fixtures/fiscal/nfse-adn/lote-real.json')),
            200,
        )]);

        $this->artisan('fiscal:nfse-probe', ['--client' => (string) config('fiscal.nfse_live_client')])
            ->expectsOutputToContain('Status HTTP: 200')
            ->expectsOutputToContain('Itens do lote: 36')
            ->assertExitCode(0);

        Http::assertSentCount(1);
    }

    /**
     * O `fiscal.nfse_live_client` que aponta um cliente sem A1 utilizável é
     * recusado pelo escopo `capturable` — e a recusa é honesta: nenhum GET sai
     * para a ADN por causa de um id que ninguém conferiu.
     */
    public function test_o_canario_que_aponta_cliente_sem_a1_recusa_antes_de_qualquer_chamada(): void
    {
        config(['fiscal.nfse_enabled' => true]);

        $account = Account::factory()->create();
        $client = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_id' => '00000000000191',
            'state' => 'SP',
        ]);

        config(['fiscal.nfse_live_client' => $client->getKey()]);

        Http::fake(['*' => Http::response('{}', 200)]);

        $this->artisan('fiscal:nfse-probe', ['--client' => (string) config('fiscal.nfse_live_client')])
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    /**
     * @return array{0: Client}
     */
    private function clienteComCertificado(): array
    {
        $account = Account::factory()->create();

        $client = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_id' => '00000000000191',
            'state' => 'SP',
        ]);

        ClientCertificate::factory()->withPassword()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
        ]);

        return [$client->refresh()];
    }
}
