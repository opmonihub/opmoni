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

    public function test_uma_falha_local_que_nada_enviou_tem_caso_proprio(): void
    {
        // `Indeterminate` significa "pode ter sido aplicado e ninguém sabe", e
        // isso é falso por construção para uma falha que acontece antes de
        // qualquer requisição existir. O caso novo é o que impede uma pasta
        // temporária sem gravação de virar uma execução com todo cliente
        // `indeterminado` e `failed = 0`.
        $this->assertSame('not_sent', SerproFailure::NotSent->value);
        $this->assertNotSame('', SerproFailure::NotSent->label());
        $this->assertContains(SerproFailure::NotSent->value, SerproFailure::values());
    }
}
