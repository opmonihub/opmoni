<?php

namespace Tests\Feature\Fiscal;

use App\Enums\FiscalModel;
use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A completude do XML na listagem de documentos: `summary_awaiting_xml`
 * quando a chave tem só o resumo da distribuição, `complete` quando o
 * documento completo chegou, `null` quando a pergunta não se aplica à linha.
 *
 * A completude é derivada dos registros de distribuição da chave — resumo
 * versus documento autorizado — e nunca da tabela de manifestação: ela diz o
 * que a distribuição entregou, não o que o escritório declarou ao fisco.
 */
class DocumentosCompletudeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('fiscal');
        Storage::fake('certificates');
        CarbonImmutable::setTestNow('2026-09-28 12:00:00');
    }

    public function test_linha_com_so_resumo_responde_resumo_aguardando_xml(): void
    {
        $account = Account::factory()->create();
        $cliente = $this->cliente($account, 'Cliente 1 Com Resumo');
        $resumo = $this->resumo($cliente);

        $resposta = $this->actingAs($this->membroDe($account, 'operador'), 'sanctum')
            ->getJson('/api/fiscal/documents')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $resumo->getKey())
            ->assertJsonPath('data.0.completude', 'summary_awaiting_xml');
    }

    public function test_resumo_com_documento_completo_na_chave_responde_completo(): void
    {
        $account = Account::factory()->create();
        $cliente = $this->cliente($account, 'Cliente 1 Completo');

        $chave = '35260914200166000166550010000123451000123456';
        $resumo = $this->resumo($cliente, ['chave_acesso' => $chave]);
        $this->documento($cliente, ['chave_acesso' => $chave]);

        // A página carrega as duas linhas da chave, e a completude é da chave
        // — não de cada linha isolada. O resumo que já tem o documento ao lado
        // não é mais "aguardando XML".
        $resposta = $this->actingAs($this->membroDe($account, 'operador'), 'sanctum')
            ->getJson('/api/fiscal/documents')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $porId = collect($resposta->json('data'))->keyBy('id');
        $this->assertSame('complete', $porId[$resumo->getKey()]['completude']);
    }

    public function test_documento_completo_responde_completo(): void
    {
        $account = Account::factory()->create();
        $cliente = $this->cliente($account, 'Cliente 1 Autorizado');
        $documento = $this->documento($cliente);

        $this->actingAs($this->membroDe($account, 'operador'), 'sanctum')
            ->getJson('/api/fiscal/documents')
            ->assertOk()
            ->assertJsonPath('data.0.id', $documento->getKey())
            ->assertJsonPath('data.0.completude', 'complete');
    }

    public function test_cte_nao_tem_completude_mesmo_com_so_resumo(): void
    {
        $account = Account::factory()->create();
        $cliente = $this->cliente($account, 'Cliente 1 Transporte');
        $resumo = $this->resumo($cliente, ['model' => FiscalModel::Cte]);

        // "Aguardando XML" é a pergunta da manifestação do destinatário, que é
        // um mecanismo de NF-e. O CT-e chega por outra distribuição e a célula
        // fica vazia — pintar "resumo aguardando XML" nele afirmaria que uma
        // manifestação poderia destravá-lo, e ela não pode.
        $this->actingAs($this->membroDe($account, 'operador'), 'sanctum')
            ->getJson('/api/fiscal/documents')
            ->assertOk()
            ->assertJsonPath('data.0.id', $resumo->getKey())
            ->assertJsonPath('data.0.completude', null);
    }

    public function test_linha_de_evento_nao_tem_completude(): void
    {
        $account = Account::factory()->create();
        $cliente = $this->cliente($account, 'Cliente 1 Com Evento');

        $chave = '35260914200166000166550010000123451000123457';
        $resumo = $this->resumo($cliente, ['chave_acesso' => $chave]);
        $evento = $this->evento($cliente, $chave, '110110', 901);

        $resposta = $this->actingAs($this->membroDe($account, 'operador'), 'sanctum')
            ->getJson('/api/fiscal/documents')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $porId = collect($resposta->json('data'))->keyBy('id');
        $this->assertSame('summary_awaiting_xml', $porId[$resumo->getKey()]['completude']);
        $this->assertNull($porId[$evento->getKey()]['completude']);
    }

    public function test_nfe_emitida_pelo_proprio_cnpj_do_cliente_nao_tem_completude(): void
    {
        $account = Account::factory()->create();
        $cliente = $this->cliente($account, 'Cliente 1 Emitente');
        $resumo = $this->resumo($cliente, ['emitente_cnpj' => $cliente->tax_id]);

        // A completude pergunta se falta destravar o XML de terceiro. Na nota
        // que o próprio cliente emitiu não há terceiro que destrave nada, e a
        // célula fica vazia — "aguardando XML" aqui sugeriria uma manifestação
        // contra a própria emissão.
        $this->actingAs($this->membroDe($account, 'operador'), 'sanctum')
            ->getJson('/api/fiscal/documents')
            ->assertOk()
            ->assertJsonPath('data.0.id', $resumo->getKey())
            ->assertJsonPath('data.0.completude', null);
    }

    public function test_nfe_do_proprio_cliente_com_tax_id_mascarado_nao_tem_completude(): void
    {
        $account = Account::factory()->create();
        $cliente = $this->cliente($account, 'Cliente 1 Emitente Mascarado');

        // O `tax_id` é campo editável e pode guardar a máscara que o operador
        // digitou: `12.345.678/0001-90` é o mesmo CNPJ de `12345678000190`, e
        // a comparação que decide "nota emitida pelo próprio cliente" tem de
        // ler os dígitos — a estrita pintaria `summary_awaiting_xml` numa nota
        // da própria emissão, o falso positivo que o `null` existe para
        // suprimir.
        $digitos = (string) $cliente->tax_id;
        $cliente->forceFill([
            'tax_id' => substr($digitos, 0, 2).'.'.substr($digitos, 2, 3).'.'
                .substr($digitos, 5, 3).'/'.substr($digitos, 8, 4).'-'.substr($digitos, 12, 2),
        ])->save();

        $resumo = $this->resumo($cliente, ['emitente_cnpj' => $digitos]);

        $this->actingAs($this->membroDe($account, 'operador'), 'sanctum')
            ->getJson('/api/fiscal/documents')
            ->assertOk()
            ->assertJsonPath('data.0.id', $resumo->getKey())
            ->assertJsonPath('data.0.completude', null);
    }

    public function test_documento_de_outra_account_nao_preenche_completude_da_pagina(): void
    {
        $account = Account::factory()->create();
        $outra = Account::factory()->create();

        // A mesma chave de acesso existe nas duas contas — o documento fiscal
        // é o mesmo, o que muda é quem o capturou. Se a consulta da completude
        // esquecer a conta, o `complete` do vizinho vaza para o resumo da
        // conta corrente.
        $chave = '35260914200166000166550010000123451000123458';
        $cliente = $this->cliente($account, 'Cliente Da Conta');
        $resumo = $this->resumo($cliente, ['chave_acesso' => $chave]);

        $clienteVizinho = $this->cliente($outra, 'Cliente De Outra Conta');
        $this->documento($clienteVizinho, ['chave_acesso' => $chave]);

        $this->actingAs($this->membroDe($account, 'operador'), 'sanctum')
            ->getJson('/api/fiscal/documents')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $resumo->getKey())
            ->assertJsonPath('data.0.completude', 'summary_awaiting_xml');
    }

    public function test_resposta_nao_traz_segredos_nem_material_de_manifestacao(): void
    {
        $account = Account::factory()->create();
        $cliente = $this->cliente($account, 'Cliente 1 Com Segredo');
        $resumo = $this->resumo($cliente);

        $resposta = $this->actingAs($this->membroDe($account, 'operador'), 'sanctum')
            ->getJson('/api/fiscal/documents')
            ->assertOk();

        $linha = $resposta->json('data.0');
        $this->assertEquals($resumo->getKey(), $linha['id']);
        $this->assertContains('completude', array_keys($linha));
        $this->assertNotContains('manifestacao', array_keys($linha));
        $this->assertNotContains('manifestation', array_keys($linha));

        // "xml" não pode ser needle: o próprio código `summary_awaiting_xml`
        // o carrega. O segredo é o conteúdo e o caminho do arquivo, que são
        // os valores que um vazamento escreveria.
        $resposta->assertDontSee('storage_path', false)
            ->assertDontSee((string) $resumo->storage_path, false)
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
        return FiscalDocument::factory()->create(array_merge([
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
        $user->forceFill(['current_account_id' => $account->getKey()])->save();

        return $user->refresh();
    }
}
