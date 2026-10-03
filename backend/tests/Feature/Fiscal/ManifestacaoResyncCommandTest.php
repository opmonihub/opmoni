<?php

namespace Tests\Feature\Fiscal;

use App\Enums\FiscalKind;
use App\Enums\FiscalManifestationEventType;
use App\Enums\FiscalManifestationOutcome;
use App\Enums\FiscalModel;
use App\Enums\FiscalSource;
use App\Enums\FiscalStage;
use App\Models\Account;
use App\Models\Client;
use App\Models\ClientCertificate;
use App\Models\FiscalDocument;
use App\Models\FiscalManifestation;
use App\Services\Fiscal\Manifestacao\FiscalManifestationStore;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * O comando `fiscal:resync-manifestacoes` e a entrada de agenda dele: o gate
 * da feature decide se a varredura existe, e a varredura só toca os resumos da
 * conta de cada cliente — a agenda roda sem `CurrentTenant`, como toda agenda.
 */
class ManifestacaoResyncCommandTest extends TestCase
{
    use RefreshDatabase;

    private const CHAVE = '35220499999999999999550010020000001240556600';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('fiscal');
        Storage::fake('certificates');
        Storage::fake('local');
        Http::preventStrayRequests();
        Cache::store('array')->clear();
    }

    public function test_com_o_gate_desligado_a_varredura_nao_consulta_nada(): void
    {
        config(['fiscal.manifestacao_enabled' => false]);

        [$cliente] = $this->clienteComCertificado();

        $this->resumoGravado($cliente, self::CHAVE);
        $this->cienciaRegistrada($cliente, self::CHAVE);

        Http::fake(['*' => Http::response($this->fixture('retDistDFeInt_138_consChNFe.xml'), 200)]);

        Artisan::call('fiscal:resync-manifestacoes');

        // O resumo manifestado continua lá, mas sem o gate nada é consultado:
        // a ressincronização é da feature, e a feature está desligada.
        Http::assertNothingSent();
        $this->assertSame(0, FiscalDocument::query()->where('stage', FiscalStage::Document)->count());
    }

    public function test_a_varredura_respeita_a_conta_de_cada_resumo(): void
    {
        config(['fiscal.manifestacao_enabled' => true]);

        [$cliente] = $this->clienteComCertificado();
        $outraConta = Account::factory()->create();

        // O resumo manifestado de uma conta não pode sair na consulta de
        // outra: a agenda roda sem tenant corrente, e é o `account_id` gravado
        // — não o escopo global — que delimita cada lado.
        $this->resumoGravado($cliente, self::CHAVE);
        $this->cienciaRegistrada($cliente, self::CHAVE);

        $clienteEstrangeiro = Client::factory()->company()->create([
            'account_id' => $outraConta->getKey(),
            'tax_id' => '00000000000191',
            'state' => 'SP',
        ]);

        ClientCertificate::factory()->withPassword('segredo-unico-9f2b')->create([
            'account_id' => $outraConta->getKey(),
            'client_id' => $clienteEstrangeiro->getKey(),
        ]);

        $this->resumoGravado($clienteEstrangeiro, self::OUTRA_CHAVE_RESYNC);
        // Estrangeiro sem manifestação nenhuma: a consulta seria vaga gasta
        // numa chave que o CNPJ ainda não manifestou.

        Http::fake(['*' => Http::response($this->fixture('retDistDFeInt_138_consChNFe.xml'), 200)]);

        Artisan::call('fiscal:resync-manifestacoes');

        // Uma consulta só: a da chave manifestada. A do estrangeiro nunca saiu.
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_contains($request->body(), '<chNFe>'.self::CHAVE.'</chNFe>'));
        Http::assertNotSent(fn ($request): bool => str_contains($request->body(), self::OUTRA_CHAVE_RESYNC));

        $this->assertTrue(FiscalDocument::query()
            ->where('client_id', $cliente->getKey())
            ->where('chave_acesso', self::CHAVE)
            ->where('stage', FiscalStage::Document)
            ->exists());
    }

    public function test_a_agenda_registra_a_rotina_quando_o_gate_esta_ligado(): void
    {
        config(['fiscal.manifestacao_enabled' => true]);

        $schedule = resolve(Schedule::class);

        $events = collect($schedule->events())->filter(
            fn ($event): bool => str_contains((string) $event->command, 'fiscal:resync-manifestacoes'),
        );

        $this->assertNotEmpty($events, 'A agenda deveria ter a ressincronização registrada com o gate ligado.');
    }

    private const OUTRA_CHAVE_RESYNC = '35220499999999999999550010020000001345678901';

    private function resumoGravado(Client $cliente, string $chave): FiscalDocument
    {
        return FiscalDocument::factory()->create([
            'account_id' => $cliente->account_id,
            'client_id' => $cliente->getKey(),
            'source' => FiscalSource::NfeDistribuicao,
            'model' => FiscalModel::Nfe,
            'kind' => FiscalKind::Document,
            'stage' => FiscalStage::Summary,
            'chave_acesso' => $chave,
            'emissao_at' => now()->subDays(5),
        ]);
    }

    private function cienciaRegistrada(Client $cliente, string $chave): FiscalManifestation
    {
        $registro = resolve(FiscalManifestationStore::class)->registrarPedido(
            accountId: (int) $cliente->account_id,
            clientId: (int) $cliente->getKey(),
            chaveAcesso: $chave,
            eventType: FiscalManifestationEventType::CienciaEmissao,
            eventSeq: 1,
            requestedBy: 'job:SendFiscalManifestationJob',
        );

        $registro->forceFill(['outcome' => FiscalManifestationOutcome::Sent])->save();

        return $registro->refresh();
    }

    /** @return array{0: Client} */
    private function clienteComCertificado(): array
    {
        $account = Account::factory()->create();

        $cliente = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_id' => '00000000000191',
            'state' => 'SP',
        ]);

        ClientCertificate::factory()->withPassword('segredo-unico-9f2b')->create([
            'account_id' => $account->getKey(),
            'client_id' => $cliente->getKey(),
        ]);

        return [$cliente->refresh()];
    }

    private function fixture(string $name): string
    {
        $contents = file_get_contents(base_path("tests/Fixtures/fiscal/{$name}"));

        $this->assertIsString($contents, "Fixture ausente: {$name}.");

        return $contents;
    }
}
