<?php

namespace Tests\Feature\Fiscal;

use App\Models\Account;
use App\Models\Client;
use App\Models\ClientCertificate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * O comando de canário `fiscal:nfse-probe`: um GET por execução, sem fila, sem
 * escrita em banco — só metadados seguros na saída e, com `--save-fixture`, um
 * arquivo anonimizado em disco.
 *
 * Nenhum teste aqui toca a rede: `preventStrayRequests` faz qualquer chamada
 * fora do `Http::fake()` explodir.
 */
class NfseAdnProbeCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('certificates');
        Storage::fake('local');
        Http::preventStrayRequests();
    }

    public function test_o_gate_desligado_recusa_antes_de_qualquer_chamada(): void
    {
        config(['fiscal.nfse_enabled' => false]);

        Http::fake(['*' => Http::response('{}', 200)]);

        $this->artisan('fiscal:nfse-probe', ['--client' => '1'])->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_cliente_nao_capturavel_sai_com_o_motivo_e_nada_e_consultado(): void
    {
        config(['fiscal.nfse_enabled' => true]);

        $account = Account::factory()->create();
        $client = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_id' => '00000000000191',
            'state' => 'SP',
        ]);

        Http::fake(['*' => Http::response('{}', 200)]);

        $this->artisan('fiscal:nfse-probe', ['--client' => (string) $client->getKey()])->assertExitCode(1);

        Http::assertNothingSent();
    }

    /**
     * O caminho feliz: um GET, e a saída descreve a resposta sem expor
     * conteúdo — status, campos de topo, contagem e NSU por item. A resposta
     * é a real do canário: 36 itens, NSUs 1 a 36.
     */
    public function test_o_probe_faz_uma_consulta_e_imprime_so_metadados_seguros(): void
    {
        config(['fiscal.nfse_enabled' => true]);

        [$client] = $this->clienteComCertificado();

        Http::fake(['*' => Http::response(
            file_get_contents(base_path('tests/Fixtures/fiscal/nfse-adn/lote-real.json')),
            200,
        )]);

        $this->artisan('fiscal:nfse-probe', ['--client' => (string) $client->getKey(), '--nsu' => '900'])
            ->expectsOutputToContain('Status HTTP: 200')
            ->expectsOutputToContain('StatusProcessamento: DOCUMENTOS_LOCALIZADOS')
            ->expectsOutputToContain('Itens do lote: 36')
            ->expectsOutputToContain('NSU 1 — NFSE')
            ->expectsOutputToContain('NSU 36 — NFSE')
            ->assertExitCode(0);

        Http::assertSentCount(1);
    }

    public function test_o_save_fixture_grava_o_json_anonimizado(): void
    {
        config(['fiscal.nfse_enabled' => true]);

        [$client] = $this->clienteComCertificado();

        Http::fake(['*' => Http::response(json_encode([
            'StatusProcessamento' => 'DOCUMENTOS_LOCALIZADOS',
            'LoteDFe' => [[
                'NSU' => 200,
                'ChaveAcesso' => '35260911222333000181000100000012345678901234567892',
                'TipoDocumento' => 'NFSE',
                'ArquivoXml' => '<NFSe><emit><CNPJ>11222333000181</CNPJ><xNome>Prestador de Verdade</xNome></emit>'
                    .'<chNFSe>35260911222333000181000100000012345678901234567892</chNFSe></NFSe>',
                'DataHoraGeracao' => '2026-10-01T10:00:00.0',
            ]],
        ]), 200)]);

        // Execuções anteriores falhadas podem ter deixado fixture para trás
        // (o teste grava em disco real): o teste começa do diretório limpo e
        // lê o arquivo que a própria execução gravou.
        foreach (glob(base_path('tests/Fixtures/fiscal/nfse-adn/probe-nsu-0-*.json')) ?: [] as $resto) {
            @unlink($resto);
        }

        $this->artisan('fiscal:nfse-probe', ['--client' => (string) $client->getKey(), '--save-fixture' => true])
            ->assertExitCode(0);

        $fixtures = glob(base_path('tests/Fixtures/fiscal/nfse-adn/probe-nsu-0-*.json'));

        $this->assertCount(1, $fixtures, 'A fixture do probe não foi gravada.');

        $conteudo = (string) file_get_contents($fixtures[0]);

        // CNPJ e nome de pessoa não entram; a chave de acesso (identificador
        // fiscal público) e a estrutura seguem. O CNPJ aparece dentro da
        // chave de acesso, que é mantida de propósito — por isso a busca é
        // pela forma do elemento XML, e não pelo número cru.
        $this->assertStringNotContainsString('<CNPJ>11222333000181</CNPJ>', $conteudo);
        $this->assertStringNotContainsString('Prestador de Verdade', $conteudo);
        $this->assertStringContainsString('«redigido»', $conteudo);
        $this->assertStringContainsString('35260911222333000181000100000012345678901234567892', $conteudo);

        @unlink($fixtures[0]);
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
