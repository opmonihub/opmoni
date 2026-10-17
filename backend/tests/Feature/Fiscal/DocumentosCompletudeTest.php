<?php

namespace Tests\Feature\Fiscal;

use App\Enums\FiscalModel;
use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Models\User;
use App\Tenant\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\BusinessTestCase;

/**
 * A listagem contábil publica apenas XML completo verificado; resumos e eventos
 * ficam fora da lista pronta e aparecem na visão de processamento.
 */
class DocumentosCompletudeTest extends BusinessTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('fiscal');
        Storage::fake('certificates');
        CarbonImmutable::setTestNow('2026-09-28 12:00:00');
    }

    public function test_so_resumo_nao_aparece_na_lista_pronta(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $cliente = $this->cliente($account, 'Cliente 1 Com Resumo');
        $this->resumo($cliente);

        $this->actingAs($this->membroDe($account, 'operador'), 'web')
            ->getJson('/api/v1/fiscal/documents')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_documento_completo_verificado_aparece_uma_vez(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $cliente = $this->cliente($account, 'Cliente 1 Completo');

        $chave = '35260914200166000166550010000123451000123456';
        $this->resumo($cliente, ['chave_acesso' => $chave]);
        $documento = $this->documento($cliente, ['chave_acesso' => $chave]);

        $this->actingAs($this->membroDe($account, 'operador'), 'web')
            ->getJson('/api/v1/fiscal/documents')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $documento->getKey());
    }

    public function test_documento_completo_com_xml_armazenado_aparece(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $cliente = $this->cliente($account, 'Cliente 1 Autorizado');
        $documento = $this->documento($cliente);

        $this->actingAs($this->membroDe($account, 'operador'), 'web')
            ->getJson('/api/v1/fiscal/documents')
            ->assertOk()
            ->assertJsonPath('data.0.id', $documento->getKey());
    }

    public function test_cte_nao_tem_completude_mesmo_com_so_resumo(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $cliente = $this->cliente($account, 'Cliente 1 Transporte');
        $resumo = $this->resumo($cliente, ['model' => FiscalModel::Cte]);

        // "Aguardando XML" é a pergunta da manifestação do destinatário, que é
        // um mecanismo de NF-e. O CT-e chega por outra distribuição e a célula
        // fica vazia — pintar "resumo aguardando XML" nele afirmaria que uma
        // manifestação poderia destravá-lo, e ela não pode.
        $this->actingAs($this->membroDe($account, 'operador'), 'web')
            ->getJson('/api/v1/fiscal/documents')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_resumo_e_evento_nao_aparecem_na_lista_pronta(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $cliente = $this->cliente($account, 'Cliente 1 Com Evento');

        $chave = '35260914200166000166550010000123451000123457';
        $this->resumo($cliente, ['chave_acesso' => $chave]);
        $this->evento($cliente, $chave, '110110', 901);

        $this->actingAs($this->membroDe($account, 'operador'), 'web')
            ->getJson('/api/v1/fiscal/documents')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_resumo_proprio_emitente_fica_fora_da_lista_pronta(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $cliente = $this->cliente($account, 'Cliente 1 Emitente');
        $this->resumo($cliente, ['emitente_cnpj' => $cliente->tax_id]);

        $this->actingAs($this->membroDe($account, 'operador'), 'web')
            ->getJson('/api/v1/fiscal/documents')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_resumo_proprio_emitente_com_cnpj_mascarado_fica_fora(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $cliente = $this->cliente($account, 'Cliente 1 Emitente Mascarado');

        $digitos = (string) $cliente->tax_id;
        $cliente->forceFill([
            'tax_id' => substr($digitos, 0, 2).'.'.substr($digitos, 2, 3).'.'
                .substr($digitos, 5, 3).'/'.substr($digitos, 8, 4).'-'.substr($digitos, 12, 2),
        ])->save();

        $this->resumo($cliente, ['emitente_cnpj' => $digitos]);

        $this->actingAs($this->membroDe($account, 'operador'), 'web')
            ->getJson('/api/v1/fiscal/documents')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_documento_de_outra_account_nao_entra_na_lista_pronta(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $outra = Account::factory()->create();

        $chave = '35260914200166000166550010000123451000123458';
        $cliente = $this->cliente($account, 'Cliente Da Conta');
        $this->resumo($cliente, ['chave_acesso' => $chave]);

        $clienteVizinho = $this->cliente($outra, 'Cliente De Outra Conta');
        $this->documento($clienteVizinho, ['chave_acesso' => $chave]);

        $this->actingAs($this->membroDe($account, 'operador'), 'web')
            ->getJson('/api/v1/fiscal/documents')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_resposta_nao_traz_segredos_nem_material_de_manifestacao(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $cliente = $this->cliente($account, 'Cliente 1 Com Segredo');
        $documento = $this->documento($cliente);

        $resposta = $this->actingAs($this->membroDe($account, 'operador'), 'web')
            ->getJson('/api/v1/fiscal/documents')
            ->assertOk();

        $linha = $resposta->json('data.0');
        $this->assertEquals($documento->getKey(), $linha['id']);
        $this->assertNotContains('manifestacao', array_keys($linha));
        $this->assertNotContains('manifestation', array_keys($linha));

        // "xml" não pode ser needle: o próprio código `summary_awaiting_xml`
        // o carrega. O segredo é o conteúdo e o caminho do arquivo, que são
        // os valores que um vazamento escreveria.
        $resposta->assertDontSee('storage_path', false)
            ->assertDontSee((string) $documento->storage_path, false)
            ->assertDontSee('sha256', false)
            ->assertDontSee('xml_bytes', false)
            ->assertDontSee('xml_preview', false)
            ->assertDontSee('<nfeProc', false)
            ->assertDontSee('password_encrypted', false);
    }

    private function cliente(Account $account, string $name): Client
    {
        return Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'name' => $name,
        ]);
    }

    private function documento(Client $cliente, array $attributes = []): FiscalDocument
    {
        return FiscalDocument::factory()->withStoredXml()->create(array_merge([
            'client_id' => $cliente->getKey(),
            'account_id' => $cliente->account_id,
        ], $attributes));
    }

    /**
     * O resumo da distribuição: a entrega que traz os campos da nota sem o XML
     * dela — a linha que a completude pinta como "aguardando XML".
     */
    private function resumo(Client $cliente, array $attributes = []): FiscalDocument
    {
        return FiscalDocument::factory()->summary()->create(array_merge([
            'client_id' => $cliente->getKey(),
            'account_id' => $cliente->account_id,
        ], $attributes));
    }

    /**
     * Evento de uma chave de acesso já captada: a terceira etapa da
     * distribuição, que não é linha de documento e não pode carregar estado
     * de completude.
     */
    private function evento(Client $cliente, string $chave, string $eventId, int $nsu, array $attributes = []): FiscalDocument
    {
        return FiscalDocument::factory()->event($eventId)->create(array_merge([
            'client_id' => $cliente->getKey(),
            'account_id' => $cliente->account_id,
            'chave_acesso' => $chave,
            'nsu' => $nsu,
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
        $this->selectBusinessAccount($user, $account->getKey());

        return $user->refresh();
    }
}
