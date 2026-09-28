<?php

namespace Tests\Unit;

use App\Enums\SerproAuthorizationTermState;
use App\Enums\SerproConnectionState;
use App\Enums\SerproFailure;
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
     * O caso que este teste separa é `SerproFailure::NotSent`, que é a falha
     * local que acontece *antes* de a requisição existir: uma pasta temporária
     * sem gravação, um cifrado guardado que não abre. A pergunta "pode ter sido
     * aplicado?" é falsa por construção para ela, e tratá-la como
     * `indeterminado` produziria uma execução em que nenhum item falhou e todos
     * ficaram indeterminados. O item não ganha um estado para `not_sent` — a
     * falha é contada, e o conserto é consertar a máquina, não reenviar.
     */
    public function test_indeterminado_e_o_desfecho_do_provedor_e_nao_a_falha_local_que_nada_enviou(): void
    {
        $this->assertSame('indeterminado', SerproSyncItemState::Indeterminate->value);

        // O que a plan 04 vai ler para decidir a contagem: este estado é o do
        // provedor, e nenhum dos dois é o do erro que aconteceu antes de enviar.
        $this->assertNotSame(SerproFailure::Indeterminate->value, SerproSyncItemState::Indeterminate->value);
        $this->assertNotSame(SerproFailure::NotSent->value, SerproSyncItemState::Indeterminate->value);
        $this->assertNotContains(
            SerproFailure::NotSent->value,
            array_column(SerproSyncItemState::cases(), 'value'),
        );
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
     * Quatro dos cinco enums serializam a mesma chave — `state` — em respostas
     * diferentes, e três deles falam português. Um valor repetido entre dois eixos
     * tornaria a resposta ambígua sem nenhum erro: quem lesse `state` não teria
     * como saber se lia um termo, um item ou uma procuração.
     */
    public function test_nenhum_valor_de_estado_aparece_em_dois_vocabularios(): void
    {
        foreach ($this->vocabularios() as $eixo => $valores) {
            foreach ($this->vocabularios() as $outroEixo => $outros) {
                if ($eixo === $outroEixo) {
                    continue;
                }

                $this->assertSame(
                    [],
                    array_values(array_intersect($valores, $outros)),
                    "O valor de {$eixo} não pode repetir o de {$outroEixo}: a chave é a mesma nos dois eixos.",
                );
            }
        }
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
     * A contagem fecha o arquivo: um `enum` vazio passa em qualquer verificação
     * de forma — é a forma exata de um arquivo que ninguém preencheu.
     */
    public function test_as_chaves_dos_cinco_enums_sao_title_case_e_nenhum_ficou_vazio(): void
    {
        $chaves = [];

        foreach ($this->enums() as $enum) {
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

                $chaves[] = $case->name;
            }
        }

        // 4 + 5 + 5 + 4 + 6: a soma dos cinco vocabulários declarados acima.
        $this->assertCount(24, $chaves);
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

    /**
     * @return array<string, list<string>>
     */
    private function vocabularios(): array
    {
        $vocabularios = [];

        foreach ($this->enums() as $enum) {
            $vocabularios[$enum] = array_column($enum::cases(), 'value');
        }

        return $vocabularios;
    }
}
