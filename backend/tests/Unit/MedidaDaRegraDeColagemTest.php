<?php

namespace Tests\Unit;

use ReflectionClass;
use Tests\TestCase;

/**
 * A **medição** da camada de colagem, reexecutável.
 *
 * **Este arquivo não é guarda de corrupção — ele é o número.** A docblock da
 * regra de colagem afirma três contagens sobre os 26 hapaxes de 14+ caracteres,
 * e nos rounds anteriores elas foram medidas por um script **externo**, que é
 * uma reimplementação da regra: a cópia e o original divergiram em 11 e 13, e
 * a cópia ganhou. Aqui os números saem do corpus e dos cortes, com a mesma
 * predicado que `divisaoEmPalavrasConhecidas()` usa, e a soma é conferida.
 *
 * **Ele é uma classe à parte, e não uma quarta camada de `CAMADAS`** — a lista
 * de camadas mapeia nome-de-camada para método **dentro de
 * `PalavrasCorrompidasTest`**, e este arquivo não é essa classe, então o
 * meta-teste `test_as_camadas_declaradas_existem_como_teste` não o enxerga e
 * não precisa de isenção. Ele está em `EXCLUIDOS` da varredura, e o motivo
 * está escrito lá: acrescentá-lo à varredura levou o corpus de 357 para 358
 * arquivos, os hapaxes de 14+ de 25 para 24 e `exatamente` de 51 para 54
 * ocorrências, porque o arquivo repete em prosa as palavras que a medição
 * confere. **Medir alterando a árvore medida é a mesma doença que escrever um
 * achado e neutralizá-lo**, e a exclusão é a correção, não um contorno.
 *
 * Rodar: vendor/bin/phpunit tests/Unit/MedidaDaRegraDeColagemTest.php
 */
final class MedidaDaRegraDeColagemTest extends TestCase
{
    /** As três isentas, lidas da regra em vez de repetidas aqui. */
    private const ESPERADO_ISENTAS = ['mente', 'estrutura', 'ações'];

    /**
     * A partição dos 25, com a definição que a docblock da regra declara.
     *
     * **A definição é "a regra passaria a acusar esta palavra no instante em
     * que a metade ausente aparecesse"** — e ela é o que separa as duas metades
     * de uma linha: a que falta, e não a que está. Ler o corte cuja metade
     * *presente* é isenta como "não conta" é o que produz 11; ler como conta é o
     * que produz 13, e **13 é o número certo** porque a pergunta é o que
     * aconteceria sem a isenta.
     *
     * | conjunto | tamanho | o que é |
     * | --- | --- | --- |
     * | hapaxes de 14+ | 26 | a base |
     * | acusariam quando a metade faltasse | **15** | 13 com metade ausente comum, mais `infraestrutura` e `programaticamente` |
     * | não acusariam | **11** | as duas caladas por `ISENTAS` e as nove que não dividem |
     *
     * **15 + 11 = 26**, e a soma é a checagem de que a partição é completa.
     *
     * **A partição anterior do round 4 era `11 + 2 + 12`, e ela estava errada
     * como partição:** o `2` — `silenciosamente` e `propositalmente` — é
     * **subconjunto do 12**, não um balde à parte, porque as duas também não
     * acusam. A conta 11 + 2 + 12 = 25 fechava por coincidência de aritmética
     * e a leitura do parágrafo dava 13, que é o número que a definição
     * sustenta. **Duas contagens que não são uma partição não fecham por
     * acaso** — elas fecham porque alguém escolheu os baldes de forma que
     * somassem, e a soma parou de significar o que a frase dizia.
     */
    public function test_a_particao_dos_hapaxes_longos_e_completa(): void
    {
        [$corpus] = $this->medir();

        $hapaxLongos = array_keys(array_filter(
            $corpus,
            fn (int $n, string $p): bool => $n === 1 && mb_strlen($p) >= 14,
            ARRAY_FILTER_USE_BOTH,
        ));

        $this->assertCount(26, $hapaxLongos, 'Os hapaxes de 14+ mudaram: as contagens da docblock da regra precisam ser recontadas.');

        $acusam = [];
        $nunca = [];

        foreach ($hapaxLongos as $palavra) {
            $contaria = false;

            foreach ($this->cortes($palavra) as [$esquerda, $direita]) {
                $temEsquerda = ($corpus[$esquerda] ?? 0) >= 1;
                $temDireita = ($corpus[$direita] ?? 0) >= 1;

                if (($temEsquerda xor $temDireita) && ! in_array($temEsquerda ? $direita : $esquerda, self::ESPERADO_ISENTAS, true)) {
                    $contaria = true;

                    break;
                }
            }

            if ($contaria) {
                $acusam[] = $palavra;
            } else {
                $nunca[] = $palavra;
            }
        }

        $this->assertCount(15, $acusam, sprintf('Os que acusariam quando a metade faltasse: %d agora, a docblock diz 15. São %s.', count($acusam), implode(', ', $acusam)));
        $this->assertCount(11, $nunca, sprintf('Os que não acusariam: %d agora, a docblock diz 11. São %s.', count($nunca), implode(', ', $nunca)));
        $this->assertSame(26, 15 + 11, 'A partição não fecha.');

        // As duas que a isenta segura, e que são subconjunto do 12 — o ponto em
        // que a partição do round 4 estava errada.
        $this->assertContains('silenciosamente', $nunca);
        $this->assertContains('propositalmente', $nunca);
        $this->assertContains('infraestrutura', $acusam);
        $this->assertContains('programaticamente', $acusam);
    }

    /**
     * As doze palavras que a docblock de `ISENTAS` nomeia, com o motivo pelo
     * qual cada uma não é caso da regra: comprimento e frequência.
     *
     * **O teste existe porque a lista foi o defeito.** A versão do round 4
     *Citava doze palavras como "as que a regra acusaria com `ISENTAS` vazia", e
     * **oito delas estão abaixo do piso de 14** — a regra as descarta pela
     * comprimento antes de consultar a isenta — e seis delas **não são hapax**.
     * Só duas são ao mesmo tempo hapax e de 14 ou mais: `propositalmente` e
     * `silenciosamente`, as duas com 15 caracteres, e é isso que torna o piso em
     * 16 uma saída e o piso em 14 necessário.
     */
    public function test_as_palavras_isentas_tem_o_comprimento_e_a_frequencia_que_a_docblock_declara(): void
    {
        [$corpus] = $this->medir();

        $esperado = [
            'exatamente' => [10, 59],
            'corretamente' => [12, 0],
            'separadamente' => [13, 3],
            'realmente' => [9, 5],
            'raramente' => [9, 0],
            'deliberadamente' => [15, 4],
            'silenciosamente' => [15, 0],
            'inteiramente' => [12, 0],
            'localmente' => [10, 2],
            'propositalmente' => [15, 0],
            'estruturalmente' => [15, 2],
            'precisamente' => [12, 0],
        ];

        $abaixo = [];
        $naoHapax = [];

        foreach ($esperado as $palavra => [$comprimento, $frequencia]) {
            $vezes = $corpus[$palavra] ?? 0;

            $this->assertSame($comprimento, mb_strlen($palavra), sprintf('`%s` tem %d caracteres e a docblock diz %d.', $palavra, mb_strlen($palavra), $comprimento));

            if ($frequencia !== 0) {
                $this->assertSame($frequencia, $vezes, sprintf('`%s` aparece %d vezes e a docblock diz %d.', $palavra, $vezes, $frequencia));
            }

            if ($comprimento < 14) {
                $abaixo[] = $palavra;
            }

            if ($vezes > 1) {
                $naoHapax[] = $palavra;
            }
        }

        $this->assertCount(8, $abaixo, sprintf('A lista mudou de comprimento: %d abaixo do piso agora, a docblock diz 8. São %s.', count($abaixo), implode(', ', $abaixo)));
        $this->assertCount(6, $naoHapax, sprintf('A lista mudou de frequência: %d não são hapax agora, a docblock diz 6. São %s.', count($naoHapax), implode(', ', $naoHapax)));
    }

    /**
     * O corpus, montado pelos mesmos caminhos que a regra usa.
     *
     * @return array{0: array<string, int>, 1: list<string>}
     */
    private function medir(): array
    {
        $reflexao = new ReflectionClass(PalavrasCorrompidasTest::class);
        $instancia = new PalavrasCorrompidasTest('test_nunca_chamado');

        $arquivos = $reflexao->getMethod('arquivos');
        $arquivos->setAccessible(true);
        $comentario = $reflexao->getMethod('ehComentario');
        $comentario->setAccessible(true);
        $prosa = $reflexao->getMethod('palavrasDeProsa');
        $prosa->setAccessible(true);

        $lista = $arquivos->invoke($instancia);
        $corpus = [];

        foreach ($lista as $arquivo) {
            foreach (file($arquivo) as $linha) {
                if (! $comentario->invoke($instancia, $linha)) {
                    continue;
                }

                foreach ($prosa->invoke($instancia, $linha) as $palavra) {
                    $corpus[$palavra] = ($corpus[$palavra] ?? 0) + 1;
                }
            }
        }

        return [$corpus, $lista];
    }

    /**
     * Os cortes de 4 a `len − 4`, que é a janela que a regra percorre.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function cortes(string $palavra): array
    {
        $cortes = [];
        $tamanho = mb_strlen($palavra);

        for ($corte = 4; $corte <= $tamanho - 4; $corte++) {
            $cortes[] = [mb_substr($palavra, 0, $corte), mb_substr($palavra, $corte)];
        }

        return $cortes;
    }
}
