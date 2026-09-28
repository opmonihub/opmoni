<?php

namespace Tests\Unit;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Palavras que não são palavra.
 *
 * Uma varredura de corrupção de texto vale pelo que ela **não** pega. A anterior
 * era `grep -P '[\x{4e00}-\x{9fff}]'`, que só enxerga CJK — e ela passou por
 * "está perfectlyamente correto" e "as duas leituras podemmaker de discordar", que
 * são português com uma palavra colada ou uma palavra em inglês, exatamente as
 * duas formas que a mão produz quando ela muda de idioma no meio da frase.
 *
 * Esta lista existe porque **palavra colada e palavra estrangeira são as duas
 * corrupções mais prováveis em comentário**, e as duas são invisíveis para um
 * filtro de "caractere não latino".
 *
 * **A lista é curta de propósito, e o motivo está escrito nela.** Wordlist
 * pega palavra **colada** — `perfectamente`, `podemmaker` —, que é a corrupção
 * que a mão produz ao trocar de idioma no meio da frase. Ela **não** pega palavra
 * isolada em inglês, porque nome de arquivo, tipo de mídia e nome de classe são
 * inglês legítimo em comentário português, e distinguir isso é leitura, não
 * filtro. Ampliar a lista é revisão, e o custo de uma entrada errada é um falso
 * positivo em comentário — que se resolve removendo a entrada, como aconteceu
 * com duas delas.
 *
 * **E "ampliar" foi medido, não presumido.** A extensão óbvia seria um detector
 * de palavra inglesa, e ela é inviável aqui: o repositório tem comentários
 * **inteiros** em inglês — `AccountPolicy`, `Client`, `Plan`, `ClientController`,
 * `UserFactory` —, e a varredura é só de linha de comentário. Medido antes de
 * decidir: `that` aparece 12 vezes, `from` 11, `should` 5, `are` 5, `where` 4.
 * Um filtro de inglês não pegaria uma corrupção sem levantar um alarme sobre a
 * língua que já existe. O que entra na lista, portanto, é palavra da
 * **corrupção** — palavra isolada em inglês que nenhum comentário deste
 * repositório usa, e que entra no lugar de um verbo português. `suffer` no
 * lugar de "sofre" é a forma: um verbo regular em inglês, que nenhuma técnica
 * de comentário plausível escreveria aqui.
 *
 * O que este teste **não** faz, e é o limite honesto dele: ele não sabe dizer se
 * uma frase inteira faz sentido. Uma corrupção que produzisse uma frase em
 * português gramatical e com sentido plausível passa, e a revisão humana do diff é
 * o que pega. O que ele faz é pegar o que a mão produz, que é a palavra única
 * fora do lugar.
 */
final class PalavrasCorrompidasTest extends TestCase
{
    /**
     * Radical → onde ele não deveria estar.
     *
     * **A lista é casada por radical, e não é detalhe.** A corrupção deste round
     * apareceu em três grafias da mesma palavra — `perfectamente`,
     * `perfeitamente` e `perfectlyamente` — e é a última que estava em `HEAD`
     * quando este teste foi escrito. Uma lista por grafia exata pegaria a
     * primeira e deixaria passar as outras duas, que é precisamente o que
     * aconteceu na primeira versão deste arquivo: ela buscava `perfectamente` e
     * não viu `perfectlyamente`, que estava um caractere adiante. Por isso a
     * verificação é `str_contains` sobre o radical.
     *
     * Os seis primeiros são radicais de palavra **colada** ou em grafia
     * anglicada. Os dois últimos são palavra inglesa **isolada** que nenhum
     * comentário deste repositório usa — a extensão que a medição de "detector
     * de inglês" permite, e que é onde entra a corrupção da forma que o resto
     * da lista não alcança.
     *
     * @var array<string, string>
     */
    private const SUSPEITAS = [
        'perfectly' => 'radical de "perfeitamente" em grafia anglicada',
        'perfectament' => '"perfeitamente" com grafia errada',
        'podemmake' => 'colagem de "podem" + "make"',
        'quedado' => 'colagem de "que" + "dado"',
        'dedado' => 'colagem de "de" + "dado"',
        'nãosó' => 'colagem de "não" + "só"',
        'suffer' => 'verbo inglês no lugar de um verbo português, que é a forma da corrupção de review anterior',
        'wrongly' => 'advérbio inglês no lugar de um advérbio português, mesma forma',
    ];

    /**
     * Onde a varredura procura.
     *
     * São os diretórios onde **comentário em português é a norma** — código de
     * produção, teste, migration, factory, configuração e rota. A lista é
     * derivada do disco, e não escrita à mão, e a razão é o que este arquivo
     * passou a garantir: uma lista escrita à mão é vazia para todo arquivo que
     * ninguém lembrou de acrescentar, e esse é o modo de uma guarda sumir sem
     * que nenhum teste fique vermelho.
     *
     * @var list<string>
     */
    private const DIRETORIOS = ['app', 'bootstrap', 'config', 'database', 'routes', 'tests'];

    /**
     * Arquivos que a varredura **não** abre, e por quê.
     *
     * Cada entrada é uma decisão, e a decisão é o oposto do que era antes: um
     * arquivo só sai da varredura por aqui, com motivo escrito. Antes a lista
     * era de inclusão, e nenhum arquivo novo era coberto sem que alguém
     * acrescentasse o caminho.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const EXCLUIDOS = [
        ['tests/Unit/PalavrasCorrompidasTest.php', 'cita as palavras corrompidas como exemplo, e as cita justamente para poder pegá-las'],
    ];

    public function test_nenhuma_palavra_corrompida_em_comentario(): void
    {
        $arquivos = $this->arquivos();

        // A guarda de que a guarda existe. Uma varredura que não abriu arquivo
        // nenhum passa em silêncio e parece cobertura: é a mesma classe de
        // defeito da lista vazia, um nível acima. As duas condições abaixo
        // fecham os dois lados — diretório renomeado, caminho base errado ou
        // lista de exclusão com entrada errada viram teste vermelho.
        $this->assertGreaterThan(100, count($arquivos), 'A varredura de comentário não abriu arquivo nenhum: a lista de diretórios está errada.');

        $achados = [];

        foreach ($arquivos as $relativo) {
            foreach (file($relativo) as $numero => $linha) {
                // Só comentário: código pode ter identificador em inglês por
                // decisão, e uma palavra english em `$variable` não é defeito.
                if (! $this->ehComentario($linha)) {
                    continue;
                }

                foreach (self::SUSPEITAS as $palavra => $porque) {
                    if ($this->contem($linha, $palavra)) {
                        $achados[] = sprintf('%s:%d  "%s" — %s', $relativo, $numero + 1, trim($linha), $porque);
                    }
                }
            }
        }

        $this->assertSame([], $achados, "Palavra corrompida em comentário:\n".implode("\n", $achados));
    }

    /**
     * Toda exclusão aponta para um arquivo que existe, e toda exclusão tem
     * motivo escrito.
     *
     * Sem este caso, uma entrada de `EXCLUIDOS` com o caminho trocado não
     * exclui nada — o arquivo segue varrido e o teste passa como se a exclusão
     * valesse —, e uma entrada vazia de motivo é uma exclusão que ninguém
     * revisou. A lista de exclusão é o lugar onde a cobertura se perde de
     * propósito, então é o lugar onde precisa de trava.
     */
    public function test_toda_exclusao_aponta_para_um_arquivo_que_existe_e_tem_motivo(): void
    {
        $this->assertNotSame([], self::EXCLUIDOS, 'A lista de exclusão não pode ser removida sem revisão: ela é a única coisa entre o arquivo e a varredura.');

        foreach (self::EXCLUIDOS as [$relativo, $porque]) {
            $this->assertFileExists(base_path($relativo), sprintf('A exclusão `%s` aponta para um arquivo que não existe.', $relativo));
            $this->assertNotSame('', trim($porque), sprintf('A exclusão `%s` precisa do motivo.', $relativo));
        }
    }

    /**
     * Os arquivos da varredura, em caminho relativo à raiz do backend.
     *
     * @return list<string>
     */
    private function arquivos(): array
    {
        $excluidos = array_column(self::EXCLUIDOS, 0);
        $arquivos = [];

        foreach (self::DIRETORIOS as $diretorio) {
            $caminho = base_path($diretorio);

            if (! is_dir($caminho)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($caminho, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $arquivo) {
                if (! $arquivo->isFile() || $arquivo->getExtension() !== 'php') {
                    continue;
                }

                $relativo = ltrim(str_replace(base_path(), '', $arquivo->getPathname()), '/');

                if (in_array($relativo, $excluidos, true)) {
                    continue;
                }

                $arquivos[] = base_path($relativo);
            }
        }

        sort($arquivos);

        return $arquivos;
    }

    /**
     * A linha é comentário, e não uma string ou um identificador.
     *
     * Os três formatos de comentário do PHP — `//`, `*` de docblock e `#` — mais
     * a abertura `/*`. Uma string de código não é contada de propósito: nome de
     * arquivo e tipo de mídia são inglês legítimo, e o defeito que este teste
     * caça é de prosa, não de código.
     */
    private function ehComentario(string $linha): bool
    {
        $codigo = ltrim($linha);

        return str_starts_with($codigo, '//')
            || str_starts_with($codigo, '*')
            || str_starts_with($codigo, '#')
            || str_starts_with($codigo, '/*');
    }

    /**
     * O radical aparece na linha, sem se worrying com fronteira de palavra.
     *
     * `str_contains` e não expressão regular com fronteira de palavra, e a
     * mudança é deliberada: o defeito que a lista caça é a **grafia** errada, e
     * `perfectamente`, `perfeitamente` e `perfectlyamente` têm três fronteiras
     * diferentes — um limite pegaria a primeira e deixaria passar as outras duas,
     * que foi o que aconteceu na primeira versão deste arquivo. Com
     * radical, as três caem; e o custo é uma palavra portuguesa que contenha o
     * radical por acaso também cair, que é o motivo de os radicais serem curtos.
     */
    private function contem(string $linha, string $radical): bool
    {
        return str_contains(mb_strtolower($linha), mb_strtolower($radical));
    }
}
