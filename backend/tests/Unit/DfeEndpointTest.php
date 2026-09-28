<?php

namespace Tests\Unit;

use App\Services\Fiscal\Support\DfeEndpoint;
use RuntimeException;
use Tests\TestCase;

/**
 * O bloco de `fiscal.endpoints` e as nove chaves que ele precisa ter.
 *
 * A lista de chaves é o contrato, e o teste a percorre inteira: um bloco escrito
 * pela metade precisa recusar **nomeando** a chave que falta, porque quem lê o
 * erro é quem corrige o `config`, e "Undefined array key" — o que acontecia antes
 * desta classe — é um `ErrorException` que escapa das guardas da reconciliação e
 * não diz nada sobre qual serviço estava escrito errado.
 *
 * A URL do ambiente que o processo não vai usar entra na conferência pelo mesmo
 * motivo: um bloco sem `homologacao` quebra no dia em que alguém corrigir
 * `FISCAL_ENVIRONMENT`, e o aviso agora vale para a chave que falta.
 */
class DfeEndpointTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private function bloco(bool $cte = true): array
    {
        /** @var array<string, string> $bloco */
        $bloco = (array) config($cte ? 'fiscal.endpoints.cte_distribuicao' : 'fiscal.endpoints.nfe_distribuicao');

        return $bloco;
    }

    public function test_o_bloco_configurado_de_cada_servico_passa_na_conferencia(): void
    {
        $this->assertCount(
            9,
            DfeEndpoint::of((array) config('fiscal.endpoints'), 'cte_distribuicao'),
            'O bloco de CT-e precisa ter as nove chaves.',
        );
        $this->assertCount(
            9,
            DfeEndpoint::of((array) config('fiscal.endpoints'), 'nfe_distribuicao'),
            'O bloco de NF-e precisa ter as nove chaves.',
        );
    }

    public function test_cada_chave_ausente_e_recusada_pelo_nome(): void
    {
        foreach (DfeEndpoint::CHAVES as $chave) {
            $bloco = $this->bloco();
            unset($bloco[$chave]);

            try {
                DfeEndpoint::of(['cte_distribuicao' => $bloco], 'cte_distribuicao');

                $this->fail("Um bloco sem '{$chave}' precisa ser recusado.");
            } catch (RuntimeException $exception) {
                $this->assertSame(
                    "Bloco de endpoint sem o parâmetro '{$chave}'.",
                    $exception->getMessage(),
                    "A recusa precisa nomear a chave '{$chave}'.",
                );
            }
        }
    }

    public function test_uma_chave_vazia_também_e_recusa_e_não_uma_que_falta(): void
    {
        $bloco = $this->bloco();
        $bloco['version'] = '';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Bloco de endpoint sem o parâmetro 'version'.");

        DfeEndpoint::of(['cte_distribuicao' => $bloco], 'cte_distribuicao');
    }

    public function test_uma_fonte_que_o_config_não_conhece_recusa_com_a_mensagem_da_fonte(): void
    {
        // A diferença importa para quem lê: "não configurado" é defeito de
        // versão do `config`, e "sem o parâmetro" é um campo faltando. Um bloco
        // de serviço que não existe não tem chave nenhuma para nomear.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Endpoint de distribuição não configurado.');

        DfeEndpoint::of((array) config('fiscal.endpoints'), 'cte_que_nao_existe');
    }
}
