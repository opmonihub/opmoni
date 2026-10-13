<?php

namespace Tests\Unit;

use App\Enums\SerproFailure;
use App\Services\SerproException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SerproFailureTest extends TestCase
{
    /**
     * @return list<array{0: int, 1: string, 2: SerproFailure}>
     */
    public static function classifications(): array
    {
        return [
            [200, '[Sucesso-REGIME]', SerproFailure::Success],
            [401, '', SerproFailure::Reauthenticate],
            [403, 'AcessoNegado-ICGERENCIADOR-013', SerproFailure::Reauthenticate],
            [403, 'AcessoNegado-ICGERENCIADOR-041', SerproFailure::Reauthenticate],
            [403, 'AcessoNegado-ICGERENCIADOR-020', SerproFailure::ResubmitTerm],
            [403, '[AcessoNegado-ICGERENCIADOR-020]', SerproFailure::ResubmitTerm],
            [403, 'AcessoNegado-ICGERENCIADOR-042', SerproFailure::ResubmitTerm],
            [403, 'AcessoNegado-ICGERENCIADOR-016', SerproFailure::DoNotRetry],
            [403, 'AcessoNegado-ICGERENCIADOR-019', SerproFailure::DoNotRetry],
            [403, 'AcessoNegado-ICGERENCIADOR-022', SerproFailure::DoNotRetry],
            [403, 'AcessoNegado-ICGERENCIADOR-054', SerproFailure::DoNotRetry],
            [400, 'EntradaIncorreta-ICGERENCIADOR-006', SerproFailure::DoNotRetry],
            [400, 'EntradaIncorreta-ICGERENCIADOR-040', SerproFailure::DoNotRetry],
            [429, '900807', SerproFailure::Throttled],
            [500, '', SerproFailure::Upstream],
            [503, '', SerproFailure::Upstream],
            [504, 'Erro-REGIME-058', SerproFailure::Indeterminate],
        ];
    }

    #[DataProvider('classifications')]
    public function test_it_classifies_a_provider_rejection(int $status, string $code, SerproFailure $expected): void
    {
        $this->assertSame($expected, SerproException::classify($status, $code));
    }

    public function test_a_timeout_is_indeterminate_and_never_throttled(): void
    {
        $this->assertSame(SerproFailure::Indeterminate, SerproException::classify(504, ''));
    }

    public function test_it_exposes_a_readable_label(): void
    {
        $this->assertNotSame('', SerproFailure::Indeterminate->label());
    }

    /**
     * O vocabulário é o contrato com o consumidor: quem decide o que fazer com
     * uma falha — a sincronização da plan 04, o relatório de consumo, a fila —
     * ramifica por estes valores, e um valor renomeado ou removido muda o
     * comportamento de quem consome sem quebrar quem produz.
     *
     * A lista é escrita por extenso, como em `WorkTemplateTest` para
     * `TaskStatus::values()`: um `assertSame(SerproFailure::values(), [...])`
     * seria tautologia, e um `assertContains` seria o que o caso já prova. O que
     * este teste fixa é a lista, para que a mudança de um valor seja uma decisão
     * e não um efeito colateral de um `case` renomeado.
     */
    public function test_values_fixa_o_vocabulario_da_taxonomia_de_falha(): void
    {
        $this->assertSame([
            'success',
            'reauthenticate',
            'resubmit_term',
            'do_not_retry',
            'throttled',
            'upstream',
            'indeterminate',
            'not_sent',
        ], SerproFailure::values());
    }

    /**
     * Este teste só pode provar o vocabulário; a classificação de cada produtor
     * é de `SerproTokenProviderTest`, de `SerproConnectionApiTest` e de
     * `SerproConnectivityTest`, onde a falha é levantada de verdade. O que este
     * arquivo guarda é a distinção que o docblock promete: `NotSent` é o que
     * acontece *antes* de a requisição existir, e não a resposta a uma pergunta
     * sobre o provedor.
     */
    public function test_not_sent_e_o_que_acontece_antes_da_requisicao_e_nao_indeterminate(): void
    {
        // `Indeterminate` significa "pode ter sido aplicado e ninguém sabe", e
        // isso é falso por construção para uma falha que acontece antes de
        // qualquer requisição existir. O caso novo é o que impede uma pasta
        // temporária sem gravação de virar uma execução com todo cliente
        // `indeterminado` e `failed = 0`. Confundir os dois enfaqueceria a
        // distinção que a plan 04 vai consumir.
        //
        // O rótulo entra porque é ele que o operador e a tela de execuções leem,
        // e um rótulo vazio — ou o mesmo rótulo do outro caso — apagaria a
        // diferença que o valor carrega. Já a comparação de `->value` com
        // `Indeterminate` foi retirada: os dois valores são fixados pela lista
        // de `test_values_fixa_o_vocabulario_da_taxonomia_de_falha`, e um
        // `assertNotSame` entre dois literais de enums que ninguém muda em
        // silêncio só dá a aparência de guardar o que a lista já guarda.
        $this->assertNotSame('', SerproFailure::NotSent->label());
        $this->assertNotSame(SerproFailure::Indeterminate->label(), SerproFailure::NotSent->label());
    }
}
