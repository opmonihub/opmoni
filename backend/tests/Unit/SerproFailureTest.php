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
        $this->assertSame('not_sent', SerproFailure::NotSent->value);
        $this->assertNotSame(SerproFailure::Indeterminate->value, SerproFailure::NotSent->value);
        $this->assertNotSame('', SerproFailure::NotSent->label());
        $this->assertNotSame(SerproFailure::Indeterminate->label(), SerproFailure::NotSent->label());
    }
}
