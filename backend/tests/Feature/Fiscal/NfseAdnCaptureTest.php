<?php

namespace Tests\Feature\Fiscal;

use App\Enums\FiscalModel;
use App\Enums\FiscalSource;
use App\Jobs\CaptureFiscalDocumentsJob;
use App\Models\Account;
use App\Models\Client;
use App\Models\ClientCertificate;
use App\Models\FiscalCursor;
use App\Models\FiscalDocument;
use App\Services\Fiscal\Capture\FiscalCaptureDispatcher;
use App\Services\Fiscal\Capture\FiscalCaptureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use JsonException;
use Tests\TestCase;

/**
 * A captura da fonte `nfse_adn` de ponta a ponta: o conector real com
 * `Http::fake()`, o writer real, o cursor real.
 *
 * Nenhum teste aqui toca a rede, e nenhum escreve fora do disco falso.
 */
class NfseAdnCaptureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('fiscal');
        Storage::fake('certificates');
        Storage::fake('local');
        Http::preventStrayRequests();
    }

    /**
     * O pipeline da NFS-e é o de sempre: lote gravado antes da posição andar, e
     * a posição gravada é a que a resposta devolveu — a maior NSU do lote, e
     * não a local somada de um.
     */
    public function test_a_captura_grava_o_documento_nfse_e_avanca_o_cursor_com_a_posicao_devolvida(): void
    {
        [$account, $client] = $this->clienteComCertificado();

        Http::fake(['*' => Http::response($this->fixture('lote-real.json'), 200)]);

        $itens = $this->loteReal()['LoteDFe'];
        $primeiroNfse = $itens[0];

        $outcome = resolve(FiscalCaptureService::class)->capture($client, FiscalSource::NfseAdn);

        $this->assertTrue($outcome->ran);
        $this->assertSame(count($itens), $outcome->stored);
        $this->assertSame(max(array_column($itens, 'NSU')), $outcome->toNsu);

        $documento = FiscalDocument::query()->where('chave_acesso', $primeiroNfse['ChaveAcesso'])->firstOrFail();

        $this->assertSame(FiscalSource::NfseAdn->value, $documento->source->value);
        $this->assertSame(FiscalModel::Nfse->value, $documento->model->value);

        // Os 50 dígitos passam inteiros pela coluna alargada — trunco, a
        // identidade fiscal do documento seria outra.
        $this->assertSame(50, strlen((string) $documento->chave_acesso));
        $this->assertTrue(Storage::disk('fiscal')->exists($documento->storage_path));

        $cursor = FiscalCursor::query()
            ->where('client_id', $client->getKey())
            ->where('source', FiscalSource::NfseAdn->value)
            ->firstOrFail();

        $this->assertSame(max(array_column($itens, 'NSU')), (int) $cursor->last_nsu);
    }

    /**
     * O gate de instalação na fronteira do despacho: com `nfse_enabled`
     * desligada, a fonte não é enfileirada — nem no upload do certificado, nem
     * no despacho da tela — e a recusa diz a chave que a liga.
     */
    public function test_o_gate_desligado_recusa_a_fonte_e_nada_e_enfileirado(): void
    {
        config(['fiscal.nfse_enabled' => false]);

        [$account, $client] = $this->clienteComCertificado();

        Bus::fake();

        $dispatcher = resolve(FiscalCaptureDispatcher::class);

        $this->assertNotNull($dispatcher->recusaDeFonte(FiscalSource::NfseAdn));

        $resultado = $dispatcher->capturar($client);

        Bus::assertNotDispatched(CaptureFiscalDocumentsJob::class, fn (CaptureFiscalDocumentsJob $job): bool => $job->source === FiscalSource::NfseAdn);

        // A recusa é da fonte, não do cliente: a NF-e segue capturável.
        $this->assertSame(['nfe_distribuicao'], $resultado['sources']);
    }

    public function test_o_gate_ligado_enfileira_a_fonte(): void
    {
        config(['fiscal.nfse_enabled' => true]);

        [$account, $client] = $this->clienteComCertificado();

        Bus::fake();

        $dispatcher = resolve(FiscalCaptureDispatcher::class);

        $this->assertNull($dispatcher->recusaDeFonte(FiscalSource::NfseAdn));

        $resultado = $dispatcher->capturar($client);

        Bus::assertDispatched(CaptureFiscalDocumentsJob::class, fn (CaptureFiscalDocumentsJob $job): bool => $job->source === FiscalSource::NfseAdn);

        $this->assertContains('nfse_adn', $resultado['sources']);
    }

    /**
     * @return array{0: Account, 1: Client}
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

        return [$account, $client->refresh()];
    }

    private function fixture(string $name): string
    {
        $contents = file_get_contents(base_path("tests/Fixtures/fiscal/nfse-adn/{$name}"));

        $this->assertIsString($contents, "Fixture ausente: {$name}.");

        return $contents;
    }

    /**
     * A resposta real do canário, decodificada — as expectativas derivam dela,
     * e não de números fixados à mão.
     *
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function loteReal(): array
    {
        $conteudo = $this->fixture('lote-real.json');

        return json_decode($conteudo, true, 512, JSON_THROW_ON_ERROR);
    }
}
