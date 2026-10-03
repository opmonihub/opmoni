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
 * A busca por texto (`q`) da tabela de documentos: número exato, chave de
 * acesso de 44 dígitos e nome do cliente por substring, com os curingas do
 * `LIKE` como texto literal e a conjunção com os demais filtros.
 */
class FiscalDocumentSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('fiscal');
        Storage::fake('certificates');
        CarbonImmutable::setTestNow('2026-09-28 12:00:00');
    }

    public function test_busca_pelo_numero_da_nota_traz_so_a_linha_daquele_numero(): void
    {
        $account = Account::factory()->create();
        $cliente = $this->cliente($account, 'Cliente 1 Alvo');
        $outro = $this->cliente($account, 'Cliente 2 Vizinho');

        // Igualdade, não prefixo: a nota `12345` não pode trazer a `123456`.
        $alvo = $this->documento($cliente, ['numero' => '12345']);
        $parecido = $this->documento($cliente, ['numero' => '123456']);
        $outraNota = $this->documento($outro, ['numero' => '98765']);

        // A linha de evento da mesma chave não carrega o `numero` da nota: a
        // busca casa linha a linha, e o evento continua alcançável pelo
        // detalhe da folha, como em qualquer outro filtro.
        $this->evento($cliente, (string) $alvo->chave_acesso, '110111', 900);

        $resposta = $this->actingAs($this->membroDe($account, 'operador'), 'sanctum')
            ->getJson('/api/fiscal/documents?q=12345')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->assertEquals($alvo->getKey(), $resposta->json('data.0.id'));
        $this->assertNotEquals($parecido->getKey(), $resposta->json('data.0.id'));
        $this->assertNotEquals($outraNota->getKey(), $resposta->json('data.0.id'));
    }

    public function test_busca_pela_chave_de_44_digitos_traz_as_linhas_e_os_eventos_da_chave(): void
    {
        $account = Account::factory()->create();
        $cliente = $this->cliente($account, 'Cliente 1 Alvo');

        $documento = $this->documento($cliente, ['numero' => '55100']);
        $chave = (string) $documento->chave_acesso;

        // A chave é a coluna que documento e evento compartilham: as linhas da
        // linha do tempo entram juntas na busca pela chave inteira.
        $evento = $this->evento($cliente, $chave, '110111', 901);
        $deOutraChave = $this->documento($cliente);

        $resposta = $this->actingAs($this->membroDe($account, 'operador'), 'sanctum')
            ->getJson('/api/fiscal/documents?q='.$chave)
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $ids = collect($resposta->json('data'))->pluck('id')->all();
        $this->assertContains($documento->getKey(), $ids);
        $this->assertContains($evento->getKey(), $ids);
        $this->assertNotContains($deOutraChave->getKey(), $ids);
    }

    public function test_busca_pelo_nome_do_cliente_e_insensivel_a_caixa(): void
    {
        $account = Account::factory()->create();
        // O nome gravado tem maiúsculas onde a busca tem minúsculas: quem
        // digita de memória não lembra da caixa, e o `LIKE` é sobre
        // `lower(clients.name)`.
        $cliente = $this->cliente($account, 'Padaria BELA Vista');
        $outro = $this->cliente($account, 'Mercearia Centro');

        $nota = $this->documento($cliente);
        $notaDeOutro = $this->documento($outro);

        $resposta = $this->actingAs($this->membroDe($account, 'user'), 'sanctum')
            ->getJson('/api/fiscal/documents?q=bela%20vista')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $nota->getKey());

        $this->assertNotEquals($notaDeOutro->getKey(), $resposta->json('data.0.id'));

        // E o fragmento do meio casa também: é substring, não prefixo.
        $this->actingAs($this->membroDe($account, 'user'), 'sanctum')
            ->getJson('/api/fiscal/documents?q=BELA')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $nota->getKey());
    }

    public function test_busca_combina_com_o_filtro_de_modelo_por_and(): void
    {
        $account = Account::factory()->create();
        $cliente = $this->cliente($account, 'Cliente 1 Com Duas Notas');

        // O mesmo número nos dois modelos: a busca sozinha devolve as duas
        // linhas, e com o filtro de modelo devolve só a que satisfaz os dois.
        $nfe = $this->documento($cliente, ['numero' => '55100', 'model' => FiscalModel::Nfe]);
        $cte = $this->documento($cliente, ['numero' => '55100', 'model' => FiscalModel::Cte]);

        $this->actingAs($this->membroDe($account, 'operador'), 'sanctum')
            ->getJson('/api/fiscal/documents?q=55100')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->actingAs($this->membroDe($account, 'operador'), 'sanctum')
            ->getJson('/api/fiscal/documents?q=55100&model[]=cte')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $cte->getKey());

        $this->assertNotEquals($nfe->getKey(), $cte->getKey());
    }

    public function test_curinga_da_busca_e_tratado_como_texto_literal(): void
    {
        $account = Account::factory()->create();
        // `Empresa 500` contém `Empresa 50`: sem escape, o `%` da busca traria
        // as duas. Com escape, só o nome que termina em `%` literal.
        $comPorcento = $this->cliente($account, 'Empresa 50%');
        $semPorcento = $this->cliente($account, 'Empresa 500');

        $alvo = $this->documento($comPorcento);
        $naoAlvo = $this->documento($semPorcento);

        $resposta = $this->actingAs($this->membroDe($account, 'operador'), 'sanctum')
            ->getJson('/api/fiscal/documents?q='.urlencode('Empresa 50%'))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $alvo->getKey());

        $this->assertNotEquals($naoAlvo->getKey(), $resposta->json('data.0.id'));

        // Um curinga solto é literal: `%` casa só o nome que contém o próprio
        // caractere, e não a carteira inteira.
        $this->actingAs($this->membroDe($account, 'operador'), 'sanctum')
            ->getJson('/api/fiscal/documents?q='.urlencode('%'))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $alvo->getKey());

        // E nenhum nome contém um `_` literal.
        $this->actingAs($this->membroDe($account, 'operador'), 'sanctum')
            ->getJson('/api/fiscal/documents?q='.urlencode('_'))
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        // O `\` também é curinga de `LIKE` no papel: sem o escape dele, o
        // padrão `\1` casaria com qualquer nome que contivesse `1`, e não só
        // com o nome que tem a contra-barra literal.
        $comBarra = $this->cliente($account, 'Empresa \\1');
        $semBarra = $this->cliente($account, 'Empresa 1');

        $doNomeComBarra = $this->documento($comBarra);
        $doNomeSemBarra = $this->documento($semBarra);

        $this->actingAs($this->membroDe($account, 'operador'), 'sanctum')
            ->getJson('/api/fiscal/documents?q='.urlencode('\\1'))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $doNomeComBarra->getKey());

        $this->assertNotEquals($doNomeSemBarra->getKey(), $doNomeComBarra->getKey());
    }

    public function test_busca_nao_alcancada_em_outra_conta(): void
    {
        $account = Account::factory()->create();
        $alheia = Account::factory()->create();

        $cliente = $this->cliente($account, 'Cliente 1 Da Casa');
        $clienteAlheio = $this->cliente($alheia, 'Cliente 2 Alheio');

        $notaDaCasa = $this->documento($cliente, ['numero' => '777']);

        // Mesmo número e mesma chave na outra conta: a busca é sobre a conta
        // corrente, e nada dela pode atravessar.
        $notaAlheia = $this->documento($clienteAlheio, ['numero' => '777']);
        $chaveRepetida = (string) $notaDaCasa->chave_acesso;
        $this->documento($clienteAlheio, ['chave_acesso' => $chaveRepetida]);

        $this->actingAs($this->membroDe($account, 'operador'), 'sanctum')
            ->getJson('/api/fiscal/documents?q=777')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $notaDaCasa->getKey());

        $this->actingAs($this->membroDe($account, 'operador'), 'sanctum')
            ->getJson('/api/fiscal/documents?q='.$chaveRepetida)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $notaDaCasa->getKey());

        // A conta alheia, consultada por um membro dela, também não vê a casa.
        $this->actingAs($this->membroDe($alheia, 'operador'), 'sanctum')
            ->getJson('/api/fiscal/documents?q=777')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $notaAlheia->getKey());
    }

    public function test_busca_sem_resultado_responde_200_com_a_lista_vazia(): void
    {
        $account = Account::factory()->create();
        $this->documento($this->cliente($account, 'Cliente 1 Unico'));

        $this->actingAs($this->membroDe($account, 'operador'), 'sanctum')
            ->getJson('/api/fiscal/documents?q=nada%20disso')
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonCount(0, 'data');
    }

    public function test_busca_vazia_ou_so_com_espacos_nao_filtra_nada(): void
    {
        $account = Account::factory()->create();
        $cliente = $this->cliente($account, 'Cliente 1 Unico');

        $primeira = $this->documento($cliente, ['numero' => '100']);
        $segunda = $this->documento($cliente, ['numero' => '200']);

        $this->actingAs($this->membroDe($account, 'operador'), 'sanctum')
            ->getJson('/api/fiscal/documents?q=')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->actingAs($this->membroDe($account, 'operador'), 'sanctum')
            ->getJson('/api/fiscal/documents?q='.urlencode('   '))
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        // E o valor com espaço nas bordas entra trimmed: é o número que casa.
        $resposta = $this->actingAs($this->membroDe($account, 'operador'), 'sanctum')
            ->getJson('/api/fiscal/documents?q='.urlencode('  100  '))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $primeira->getKey());

        $this->assertNotEquals($segunda->getKey(), $resposta->json('data.0.id'));
    }

    public function test_busca_acima_do_teto_de_200_responde_422_nomeando_q(): void
    {
        $account = Account::factory()->create();
        $this->documento($this->cliente($account, 'Cliente 1 Unico'));

        $this->actingAs($this->membroDe($account, 'operador'), 'sanctum')
            ->getJson('/api/fiscal/documents?q='.str_repeat('a', 201))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['q']);
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
     * Evento de uma chave de acesso já captada, na terceira posição da
     * distribuição: a identidade é a chave composta
     * `(client_id, chave_acesso, event_id)`.
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
