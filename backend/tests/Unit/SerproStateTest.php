<?php

namespace Tests\Unit;

use App\Enums\SerproAuthorizationTermState;
use App\Enums\SerproConnectionState;
use App\Enums\SerproPowerOfAttorneyState;
use App\Enums\SerproSyncItemState;
use App\Enums\SerproSyncRunState;
use PHPUnit\Framework\TestCase;

/**
 * O vocabulário de estados que os planos 02 a 05 falam, fixado em um lugar só.
 *
 * Estes valores não são implementação: eles são o que a API publica e o que o
 * cliente ramifica, e viram coluna `state` nas tabelas que os planos 02 a 04
 * criam. Um valor renomeado aqui não quebra o produtor — `->value` continua
 * existindo — e quebra o consumidor, que passa a receber uma palavra que nenhum
 * `switch` dele conhece, sem erro em lugar nenhum. Por isso a lista é escrita
 * por extenso, como em `SerproFailureTest` para a taxonomia de falha: um
 * `assertSame(Enum::cases(), Enum::cases())` seria tautologia, e o que este
 * arquivo guarda é a decisão de mudar um valor, não o efeito colateral de um
 * `case` renomeado.
 *
 * **O que este arquivo não guarda, e por quê:** nenhuma regra proíbe que um
 * valor apareça em dois dos cinco vocabulários. Houve aqui uma que proibia, e ela
 * foi retirada por ser uma regra que ninguém combinou: cada vocabulário pertence
 * à sua própria coluna e à sua própria resposta, nada compara um valor de um
 * eixo com o de outro em tempo de execução, e um plano posterior tem direito de
 * escolher um `falhou` no eixo da execução sem pedir permissão a um teste. A
 * violação que importa — alguém digitar o valor errado — já é pego pelas cinco
 * listas exatas acima, que fixam valor e ordem.
 */
class SerproStateTest extends TestCase
{
    /**
     * Estado da credencial da plataforma, e não do cliente: uma linha só, lida
     * por quem opera a integração e não por nenhuma conta.
     */
    public function test_lista_exata_dos_estados_da_conexao(): void
    {
        $this->assertSame(
            ['not_configured', 'configured', 'invalid', 'unavailable'],
            array_column(SerproConnectionState::cases(), 'value'),
        );
    }

    /**
     * O eixo da execução inteira. `partial` é o caso que não pode ser omitido:
     * sem ele, uma execução em que alguns clientes entraram e outros não só
     * caberia em `completed` ou em `failed`, e o relatório mentiria nos dois
     * sentidos.
     */
    public function test_lista_exata_dos_estados_da_execucao(): void
    {
        $this->assertSame(
            ['queued', 'running', 'completed', 'partial', 'failed'],
            array_column(SerproSyncRunState::cases(), 'value'),
        );
    }

    /**
     * O eixo do cliente dentro da execução, e em português porque é o que o
     * operador lê na tela da carteira.
     */
    public function test_lista_exata_dos_estados_do_item_da_execucao(): void
    {
        $this->assertSame(
            ['sincronizado', 'ignorado', 'falhou', 'indeterminado', 'nao_processado'],
            array_column(SerproSyncItemState::cases(), 'value'),
        );
    }

    /**
     * `indeterminado` responde a uma pergunta sobre o provedor — "pode ter sido
     * aplicado e ninguém sabe" — e é o único estado que a plan 04 conta sem
     * `failed`.
     *
     * O que este teste separa é `SerproFailure::NotSent`, a falha local que
     * acontece *antes* de a requisição existir: uma pasta temporária sem
     * gravação, um cifrado guardado que não abre. A pergunta "pode ter sido
     * aplicado?" é falsa por construção para ela, e tratá-la como
     * `indeterminado` produziria uma execução em que nenhum item falhou e todos
     * ficaram indeterminados. A resposta a isso não é um estado novo: o item
     * simplesmente não tem estado para `not_sent`, e a falha é contada.
     *
     * O que este teste **não** afirma é que `indeterminado` e `indeterminate`
     * sejam palavras diferentes. São, hoje, e a diferença é incidental: nenhum
     * dos dois enums obriga o outro a falar um idioma, e unificar as grafias não
     * perderia nada semântico — deve, por isso, ser uma decisão que não quebre
     * teste nenhum. Por isso a comparação de grafia entre os dois fica de fora
     * de propósito. O que é semântico — o estado do provedor e a falha local não
     * colapsam em um só — está na afirmação que sobrou e nos docblocks de
     * `SerproSyncItemState` e de `SerproFailure`.
     *
     * E ela é uma só porque as outras duas eram guarda sem nada atrás: só
     * falhariam se alguém declarasse um item de execução com estado `not_sent`,
     * e essa é uma decisão que ninguém toma por engano — o item tem cinco
     * estados, nenhum deles é esse, e a lista está fixada no teste logo acima.
     * Um teste que só quebra por adulteração proposital não guarda nada, e
     * fingir que guarda é pior do que não ter: dá a aparência de uma garantia
     * que a plan 04 não tem.
     */
    public function test_indeterminado_e_o_desfecho_do_provedor_e_nao_a_falha_local_que_nada_enviou(): void
    {
        // O que a plan 04 vai ler para decidir a contagem: `indeterminado` é o
        // desfecho do provedor, e `NotSent` — a falha que aconteceu antes de
        // qualquer requisição — não tem nome de estado aqui. A lista exata dos
        // cinco estados está no teste acima, e é ela que garante que `not_sent`
        // não entra: uma afirmação sobre o vocabulário alheio não pode vigiar o
        // próprio.
        $this->assertSame('indeterminado', SerproSyncItemState::Indeterminate->value);
    }

    /**
     * Estado da procuração e-CAC no serviço do SERPRO, e é ele — não o dado
     * sincronizado, que ainda não existe — que decide se o cliente é elegível.
     */
    public function test_lista_exata_dos_estados_da_procuracao(): void
    {
        $this->assertSame(
            ['pending', 'established', 'rejected', 'expired'],
            array_column(SerproPowerOfAttorneyState::cases(), 'value'),
        );
    }

    /**
     * Estado do termo de autorização do escritório, e a ordem é o ciclo:
     * `validado` é o documento que o provedor aceitou e `autenticado` é o que já
     * rendeu token de autorização — e apresentar ao escritório um termo
     * "autorizado" que nenhuma chamada ao gateway aceitou é o defeito que a
     * ordem dos dois casos impede. `ausente` e `recusado` são os dois que o
     * provedor não produz: o primeiro é o que o escritório ainda não assinou, o
     * segundo é o que ele não pode assinar sem corrigir algo.
     */
    public function test_lista_exata_dos_estados_do_termo_de_autorizacao(): void
    {
        $this->assertSame(
            ['ausente', 'pendente', 'validado', 'autenticado', 'vencido', 'recusado'],
            array_column(SerproAuthorizationTermState::cases(), 'value'),
        );
    }

    /**
     * A regra do repositório é `TitleCase` para chave de enum, e aqui ela é
     * load-bearing: o nome do caso é o que o `match` do resource e o `cast` do
     * model citam, e um `NAO_PROCESSADO` escrito à mão passaria pelo PHP sem
     * reclamar e quebraria o `match` que o consome.
     *
     * As duas condições são necessárias porque nenhuma basta sozinha: a forma
     * aceita `ESTABLISHED`, que é o defeito que o teste existe para pegar, e a
     * letra minúscula sozinha aceitaria `notConfigured`. Juntas, aceitam
     * `NotConfigured` e não aceitam nem o grito nem o `snake_case` colado no
     * lugar.
     *
     * A garantia de "nenhum enum vazio" é por enum, e não um total somado no fim
     * do laço. O total era um terceiro lugar a editar a cada adição legítima — e
     * a aritmética já está fixada pelas cinco listas exatas acima —, e quando
     * falhasse não diria *qual* dos cinco ficou vazio, que é a informação que o
     * operador do arquivo precisa. O que esta linha impede é o que importa: um
     * `enum` vazio passa em qualquer verificação de forma, porque o laço de formas
     * simplesmente não roda.
     */
    public function test_as_chaves_dos_cinco_enums_sao_title_case_e_nenhum_ficou_vazio(): void
    {
        foreach ($this->enums() as $enum) {
            $this->assertNotEmpty(
                $enum::cases(),
                "{$enum} não pode ficar sem nenhum caso: é a forma exata de um arquivo que ninguém preencheu.",
            );

            foreach ($enum::cases() as $case) {
                $this->assertMatchesRegularExpression(
                    '/^[A-Z][A-Za-z0-9]*$/',
                    $case->name,
                    "{$enum} precisa de chave TitleCase; veio {$case->name}.",
                );
                $this->assertMatchesRegularExpression(
                    '/[a-z]/',
                    $case->name,
                    "{$enum} precisa de chave TitleCase, e não em maiúsculas; veio {$case->name}.",
                );
            }
        }
    }

    /**
     * @return list<class-string<\BackedEnum>>
     */
    private function enums(): array
    {
        return [
            SerproConnectionState::class,
            SerproSyncRunState::class,
            SerproSyncItemState::class,
            SerproPowerOfAttorneyState::class,
            SerproAuthorizationTermState::class,
        ];
    }
}
