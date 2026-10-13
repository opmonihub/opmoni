<?php

namespace Tests\Unit;

use ReflectionClass;
use Tests\TestCase;

/**
 * A medição da camada de colagem de `PalavrasCorrompidasTest`.
 *
 * Nada aqui fixa contagem do corpus vivo. O corpus é todo comentário do
 * repositório, e um número tirado dele fica velho no próximo comentário
 * escrito, em qualquer arquivo. O que este arquivo confere é o que não muda
 * com o corpus: o comportamento da regra sobre um corpus montado à mão, e a
 * previsão de acusação feita com o próprio predicado da regra, e não com uma
 * cópia dele.
 *
 * O predicado é lido por reflexão de `divisaoEmPalavrasConhecidas()`. Uma
 * reimplementação aqui já divergiu do original uma vez: tratava como acusável
 * o corte cuja metade direita presente é isenta, que a regra real cala.
 */
final class MedidaDaRegraDeColagemTest extends TestCase
{
    public function test_a_regra_acusa_colagem_e_cala_a_metade_direita_isenta(): void
    {
        $corpus = array_fill_keys(['continua', 'existindo', 'silenciosa', 'mente', 'infra', 'estrutura'], 1);

        $this->assertSame(['continua', 'existindo'], $this->divisao('continuaexistindo', $corpus));
        $this->assertNull($this->divisao('silenciosamente', $corpus));
        $this->assertNull($this->divisao('infraestrutura', $corpus));
    }

    public function test_a_regra_exige_quatro_caracteres_em_cada_metade(): void
    {
        $corpus = array_fill_keys(['abc', 'defghijklmnopq', 'abcdefghijklmn', 'opq'], 1);

        $this->assertNull($this->divisao('abcdefghijklmnopq', $corpus));
    }

    public function test_a_regra_devolve_o_primeiro_corte_que_fecha(): void
    {
        $corpus = array_fill_keys(['correntes', 'produzem', 'correntesprod', 'uzem'], 1);

        $this->assertSame(['correntes', 'produzem'], $this->divisao('correntesproduzem', $corpus));
    }

    /**
     * Toda palavra longa que aparece uma vez só cai em exatamente um de dois
     * conjuntos: a regra passaria a acusá-la se a metade ausente de algum corte
     * aparecesse no corpus, ou não passaria. A previsão simula a metade ausente
     * e chama o predicado real.
     */
    public function test_a_previsao_de_acusacao_usa_o_predicado_da_regra(): void
    {
        $corpus = $this->corpusVivo();
        $minimo = $this->constante('COMPRIMENTO_MINIMO');

        $hapaxLongos = array_keys(array_filter(
            $corpus,
            fn (int $vezes, string $palavra): bool => $vezes === 1 && mb_strlen($palavra) >= $minimo,
            ARRAY_FILTER_USE_BOTH,
        ));

        $this->assertNotSame([], $hapaxLongos, 'O corpus não tem palavra longa de ocorrência única: a varredura não abriu os comentários.');

        $acusariam = [];
        $naoAcusariam = [];

        foreach ($hapaxLongos as $palavra) {
            $this->assertNull($this->divisao($palavra, $corpus), sprintf('`%s` já é acusada hoje, e a camada de colagem deveria ter falhado.', $palavra));

            if ($this->acusariaComAMetadeAusente($palavra, $corpus)) {
                $acusariam[] = $palavra;
            } else {
                $naoAcusariam[] = $palavra;
            }
        }

        $this->assertSame([], array_intersect($acusariam, $naoAcusariam));
        $this->assertCount(count($hapaxLongos), [...$acusariam, ...$naoAcusariam]);
    }

    /**
     * A docblock de `ISENTAS` separa as doze palavras de prosa que a lista
     * silencia pelo comprimento, que é fato da palavra e não do corpus.
     */
    public function test_as_palavras_isentas_citadas_tem_o_comprimento_que_a_docblock_declara(): void
    {
        $minimo = $this->constante('COMPRIMENTO_MINIMO');

        $abaixoDoPiso = ['realmente', 'raramente', 'exatamente', 'localmente', 'corretamente', 'inteiramente', 'precisamente', 'separadamente'];
        $noPisoOuAcima = ['deliberadamente', 'silenciosamente', 'propositalmente', 'estruturalmente'];

        foreach ($abaixoDoPiso as $palavra) {
            $this->assertLessThan($minimo, mb_strlen($palavra), sprintf('`%s` não está abaixo do piso.', $palavra));
        }

        foreach ($noPisoOuAcima as $palavra) {
            $this->assertGreaterThanOrEqual($minimo, mb_strlen($palavra), sprintf('`%s` está abaixo do piso.', $palavra));
        }

        $this->assertSame(['mente', 'estrutura', 'ações'], $this->constante('ISENTAS'));
    }

    /**
     * @param  array<string, int>  $corpus
     */
    private function acusariaComAMetadeAusente(string $palavra, array $corpus): bool
    {
        $tamanho = mb_strlen($palavra);

        for ($corte = 4; $corte <= $tamanho - 4; $corte++) {
            foreach ([mb_substr($palavra, 0, $corte), mb_substr($palavra, $corte)] as $metade) {
                if (($corpus[$metade] ?? 0) === 0 && $this->divisao($palavra, [...$corpus, $metade => 1]) !== null) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, int>  $corpus
     * @return array{0: string, 1: string}|null
     */
    private function divisao(string $palavra, array $corpus): ?array
    {
        return (new ReflectionClass(PalavrasCorrompidasTest::class))
            ->getMethod('divisaoEmPalavrasConhecidas')
            ->invoke($this->regra(), $palavra, $corpus);
    }

    /**
     * O corpus montado pelos mesmos caminhos que a regra usa.
     *
     * @return array<string, int>
     */
    private function corpusVivo(): array
    {
        $reflexao = new ReflectionClass(PalavrasCorrompidasTest::class);
        $regra = $this->regra();
        $corpus = [];

        foreach ($reflexao->getMethod('arquivos')->invoke($regra) as $arquivo) {
            foreach (file($arquivo) as $linha) {
                if (! $reflexao->getMethod('ehComentario')->invoke($regra, $linha)) {
                    continue;
                }

                foreach ($reflexao->getMethod('palavrasDeProsa')->invoke($regra, $linha) as $palavra) {
                    $corpus[$palavra] = ($corpus[$palavra] ?? 0) + 1;
                }
            }
        }

        return $corpus;
    }

    private function constante(string $nome): mixed
    {
        return (new ReflectionClass(PalavrasCorrompidasTest::class))->getConstant($nome);
    }

    private function regra(): PalavrasCorrompidasTest
    {
        return new PalavrasCorrompidasTest('test_nunca_chamado');
    }
}
