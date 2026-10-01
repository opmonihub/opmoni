<?php

namespace Tests\Feature\Fiscal;

use App\Enums\FiscalKind;
use App\Enums\FiscalModel;
use App\Enums\FiscalStage;
use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A visão fiscal por cliente: o agregado por cliente que alimenta os cartões.
 *
 * A direção sai do cruzamento de CNPJ: `emitente_cnpj` igual aos dígitos do
 * `tax_id` do cliente é saída, o resto é entrada — inclusive `NULL`, que é a
 * nota de entrada cujo emitente a distribuição não trouxe. O `tax_id` pode vir
 * com máscara, então a comparação é só-dígitos dos dois lados.
 */
class FiscalClientsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('fiscal');
        Storage::fake('certificates');
    }

    public function test_exige_sessao(): void
    {
        $this->getJson('/api/fiscal/clients')->assertUnauthorized();
    }

    public function test_emitente_igual_ao_dono_e_saida(): void
    {
        $account = Account::factory()->create();
        $cliente = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_id' => '11222333000181',
        ]);
        $this->documento($cliente, [
            'emitente_cnpj' => '11222333000181',
            'valor_total' => '100.00',
        ]);

        $linha = $this->linhas($account)[$cliente->getKey()];

        $this->assertSame(1, $linha['total']);
        $this->assertSame(1, $linha['saidas']['qtd']);
        $this->assertSame('100.00', $linha['saidas']['valor']);
        $this->assertSame(0, $linha['entradas']['qtd']);
    }

    public function test_emitente_de_terceiro_e_entrada(): void
    {
        $account = Account::factory()->create();
        $cliente = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_id' => '11222333000181',
        ]);
        $this->documento($cliente, [
            'emitente_cnpj' => '99999999999999',
            'valor_total' => '50.00',
        ]);

        $linha = $this->linhas($account)[$cliente->getKey()];

        $this->assertSame(0, $linha['saidas']['qtd']);
        $this->assertSame(1, $linha['entradas']['qtd']);
        $this->assertSame('50.00', $linha['entradas']['valor']);
    }

    public function test_emitente_ausente_conta_como_entrada(): void
    {
        $account = Account::factory()->create();
        $cliente = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_id' => '11222333000181',
        ]);
        $this->documento($cliente, ['emitente_cnpj' => null]);

        $linha = $this->linhas($account)[$cliente->getKey()];

        $this->assertSame(1, $linha['total']);
        $this->assertSame(0, $linha['saidas']['qtd']);
        $this->assertSame(1, $linha['entradas']['qtd']);
    }

    public function test_tax_id_com_mascara_compara_pelos_digitos(): void
    {
        $account = Account::factory()->create();
        $cliente = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_id' => '11.222.333/0001-81',
        ]);
        $this->documento($cliente, ['emitente_cnpj' => '11222333000181']);

        $linha = $this->linhas($account)[$cliente->getKey()];

        $this->assertSame(1, $linha['saidas']['qtd']);
    }

    public function test_conta_volume_por_modelo_e_ultima_emissao(): void
    {
        $account = Account::factory()->create();
        $cliente = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_id' => '11222333000181',
        ]);
        $this->documento($cliente, [
            'model' => FiscalModel::Nfe,
            'emitente_cnpj' => '11222333000181',
            'emissao_at' => '2026-08-10 10:00:00',
        ]);
        $this->documento($cliente, [
            'model' => FiscalModel::Cte,
            'emitente_cnpj' => '99999999999999',
            'emissao_at' => '2026-09-05 10:00:00',
        ]);

        $linha = $this->linhas($account)[$cliente->getKey()];

        $this->assertSame(2, $linha['total']);
        $this->assertSame(['cte' => 1, 'nfe' => 1], $linha['por_modelo']);
        $this->assertSame('2026-09-05T10:00:00.000000Z', $linha['ultima_emissao_at']);
    }

    public function test_um_cliente_por_vez_e_somente_da_conta(): void
    {
        $account = Account::factory()->create();
        $outra = Account::factory()->create();
        $cliente = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $vizinho = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $estrangeiro = Client::factory()->company()->create(['account_id' => $outra->getKey()]);
        $this->documento($cliente);
        $this->documento($vizinho);
        $this->documento($estrangeiro);

        $linhas = $this->linhas($account);

        $linhasOrdenadas = array_keys($linhas);
        sort($linhasOrdenadas);
        $esperados = [$cliente->getKey(), $vizinho->getKey()];
        sort($esperados);
        $this->assertSame($esperados, $linhasOrdenadas);
    }

    public function test_cliente_removido_nao_entra_no_agregado(): void
    {
        $account = Account::factory()->create();
        $cliente = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $removido = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $this->documento($cliente);
        $this->documento($removido);
        $removido->delete();

        $linhas = $this->linhas($account);

        $this->assertSame([$cliente->getKey()], array_keys($linhas));
    }

    public function test_evento_de_cancelamento_nao_infla_o_total(): void
    {
        $account = Account::factory()->create();
        $cliente = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_id' => '11222333000181',
        ]);
        $this->documento($cliente, ['emitente_cnpj' => '11222333000181']);
        FiscalDocument::factory()->event('110111-1')->create([
            'client_id' => $cliente->getKey(),
            'account_id' => $account->getKey(),
        ]);

        $linha = $this->linhas($account)[$cliente->getKey()];

        $this->assertSame(1, $linha['total']);
    }

    public function test_resumo_e_documento_completo_contam_uma_vez_e_resumo_isolado_permanece(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_id' => '11222333000181',
        ]);
        $summary = $this->documento($client, [
            'stage' => FiscalStage::Summary,
            'emitente_cnpj' => '11222333000181',
            'valor_total' => '100.00',
        ]);
        $this->documento($client, [
            'chave_acesso' => $summary->chave_acesso,
            'emitente_cnpj' => '11222333000181',
            'valor_total' => '100.00',
        ]);
        $this->documento($client, [
            'stage' => FiscalStage::Summary,
            'emitente_cnpj' => '11222333000181',
            'valor_total' => '25.00',
        ]);

        $row = $this->linhas($account)[$client->getKey()];

        $this->assertSame(2, $row['total']);
        $this->assertSame(['qtd' => 2, 'valor' => '125.00'], $row['saidas']);
        $this->assertSame(['nfe' => 2], $row['por_modelo']);
    }

    public function test_prefere_metadados_do_completo_antes_de_filtrar_o_periodo(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'tax_id' => '11222333000181',
        ]);
        $summary = $this->documento($client, [
            'stage' => FiscalStage::Summary,
            'emitente_cnpj' => '11222333000181',
            'valor_total' => '100.00',
            'emissao_at' => '2026-08-10 10:00:00',
        ]);
        $this->documento($client, [
            'chave_acesso' => $summary->chave_acesso,
            'emitente_cnpj' => '99999999999999',
            'valor_total' => '120.00',
            'emissao_at' => '2026-09-05 10:00:00',
        ]);
        $this->actingAs($this->membroDe($account, 'user'), 'sanctum');

        $this->getJson('/api/fiscal/clients?issued_from=2026-08-01&issued_to=2026-08-31')
            ->assertOk()
            ->assertJsonPath('data', []);
        $this->getJson('/api/fiscal/clients?issued_from=2026-09-01&issued_to=2026-09-30')
            ->assertOk()
            ->assertJsonPath('data.0.total', 1)
            ->assertJsonPath('data.0.saidas', ['qtd' => 0, 'valor' => '0.00'])
            ->assertJsonPath('data.0.entradas', ['qtd' => 1, 'valor' => '120.00'])
            ->assertJsonPath('data.0.ultima_emissao_at', '2026-09-05T10:00:00.000000Z');
    }

    public function test_completo_de_outro_cliente_nao_exclui_resumo_da_mesma_chave(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $neighbor = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $summary = $this->documento($client, ['stage' => FiscalStage::Summary]);
        $this->documento($neighbor, ['chave_acesso' => $summary->chave_acesso]);

        $rows = $this->linhas($account);

        $this->assertSame(1, $rows[$client->getKey()]['total']);
        $this->assertSame(1, $rows[$neighbor->getKey()]['total']);
    }

    public function test_filtra_por_modelo_e_periodo(): void
    {
        $account = Account::factory()->create();
        $cliente = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $this->documento($cliente, [
            'model' => FiscalModel::Nfe,
            'emissao_at' => '2026-09-05 10:00:00',
        ]);
        $this->documento($cliente, [
            'model' => FiscalModel::Cte,
            'emissao_at' => '2026-09-05 10:00:00',
        ]);

        $membro = $this->membroDe($account, 'user');

        $this->actingAs($membro, 'sanctum')
            ->getJson('/api/fiscal/clients?model[]=nfe')
            ->assertOk()
            ->assertJsonPath('data.0.total', 1);

        $this->actingAs($membro, 'sanctum')
            ->getJson('/api/fiscal/clients?issued_from=2026-09-01&issued_to=2026-09-30')
            ->assertOk()
            ->assertJsonPath('data.0.total', 2);

        $this->actingAs($membro, 'sanctum')
            ->getJson('/api/fiscal/clients?issued_from=2026-10-01')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_filtro_invalido_e_422(): void
    {
        $account = Account::factory()->create();

        $this->actingAs($this->membroDe($account, 'user'), 'sanctum')
            ->getJson('/api/fiscal/clients?model[]=modelo_que_nao_existe')
            ->assertStatus(422);
    }

    private function linhas(Account $account): array
    {
        $corpo = $this->actingAs($this->membroDe($account, 'user'), 'sanctum')
            ->getJson('/api/fiscal/clients')
            ->assertOk()
            ->json('data');

        $porCliente = [];

        foreach ($corpo as $linha) {
            $porCliente[$linha['client']['id']] = $linha;
        }

        return $porCliente;
    }

    private function documento(Client $cliente, array $attributes = []): FiscalDocument
    {
        return FiscalDocument::factory()->create(array_merge([
            'client_id' => $cliente->getKey(),
            'account_id' => $cliente->account_id,
            'kind' => FiscalKind::Document,
        ], $attributes));
    }

    private function membroDe(Account $account, string $role): User
    {
        $user = User::factory()->create();
        AccountUser::create([
            'account_id' => $account->getKey(),
            'user_id' => $user->getKey(),
            'role' => $role,
        ]);
        $user->forceFill(['current_account_id' => $account->getKey()])->save();

        return $user->refresh();
    }
}
