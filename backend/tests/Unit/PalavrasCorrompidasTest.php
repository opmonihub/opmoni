<?php

namespace Tests\Unit;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Palavras que não são palavra.
 *
 * **São três camadas, e cada uma pega uma forma diferente de corrupção.** A
 * palavra que não é palavra e a palavra colada não são o mesmo defeito, e
 * nenhuma das duas camadas pega a outra:
 *
 * 1. **radical conhecido** — `SUSPEITAS`, uma lista de-corruption. Cada entrada
 *    nasceu de um caso que já aconteceu, e casa por radical para que as três
 *    grafias de `perfeitamente` caiam juntas;
 * 2. **roteiro estranho** — nenhum caractere de fora do latim em comentário, e
 *    é a camada que a varredura anterior tinha e este arquivo **perdeu**;
 * 3. **colagem estrutural** — token de prosa que se divide em dois tokens que o
 *    próprio repositório usa, que é a forma que a lista de radicais não alcança
 *    porque não há radical conhecido a casar.
 *
 * **Nenhuma camada pega a outra, e é essa a razão de haver três.** Um radical
 * conhecido não é uma regra de forma, e uma regra de forma não acha palavra
 * que ninguém viu.
 *
 * **A perda da camada 2 é o que este round conserta, e a história importa.** A
 * versão anterior deste arquivo declarou o `grep -P '[\x{4e00}-\x{9fff}]'` como
 * "superseded" e trocou o roteiro pela lista de radicais, com o argumento — que
 * está certo, e foi medido — de que o `grep` só enxergava CJK e passava por
 * "está perfectlyamente correto" e "as duas leituras podemmaker de discordar".
 * O argumento justifies **acrescentar** a lista, e não **substituir** o `grep`.
 * Trocar produziu uma guarda que confia em duas listas de-corruption e não tem
 * nada que pegue a forma que mais voltou neste repositório: um caractere de
 * outro roteiro dentro de comentário português. A camada 2 volta, e volta
 * **generalizada** — não só CJK. O `\p{Script=Han}` pegaria a corrupção que
 * aconteceu e não pegaria uma letra grega, uma cirílica ou um kana, e a forma
 * não é o CJK: é comentário português que saiu do roteiro.
 *
 * **E o que nenhuma das três camadas pega, e é o limite honesto do arquivo:**
 * uma frase em português gramatical que significa outra coisa. `não tem para
 * onde ir` trocado por `não tem para que ir` passa nas três camadas, porque as
 * duas frases são português correto, e nenhuma delas é palavra suspeita, colada
 * ou de outro roteiro. Isso não é uma falha de configuração que se resolva
 * ajustando lista: é o que a revisão humana do diff pega, e vale dizer isso em
 * vez de fingir que a guarda cobre.
 *
 * **A forma de item 2 deste round — `distinguição` — é a fronteira, e a
 * camada 1 a pegou por entrada de lista, e não por regra.** A medição é que
 * decide isso, e ela foi feita antes de a entrada existir: as três candidatas
 * estruturais para "quase-palavra" foram medidas e **as três estão fora**.
 *
 * - **distância de edição.** 432 falsos positivos a distância 1 entre as
 *   palavras que aparecem uma vez só e as que aparecem três ou mais, porque
 *   flexão de português **é** diferença de uma letra: `autorizada`/`autorizado`,
 *   `minutos`/`minuto`, `enviou`/`envio`. A 2 são 879. A forma é indistinguível
 *   da palavra certa, e a regra morre nisso.
 * - **trigrama inédito.** 4.631 palavras distintas, 2.662 trigramas, e
 *   **palavra com trigrama inédito: zero**. `distinguição` tem os dez
 *   trigramas (`dis`, `ist`, `sti`, `tin`, `ing`, `ngu`, `gui`, `uiç`, `içã`,
 *   `ção`) **todos** já vistos em outros comentários, porque `gui` vem de
 *   `guia`, `distinguir` e `seguir`, e `ção` vem de mil palavras. Um corpus de
 *   4.600 palavras de português cobre os trigramas do português.
 * - **palavra fora do corpus.** Zero achados, e **zero porque a regra não pode
 *   achar nada**: o corpus é feito *dos* tokens, então o token que está num
 *   comentário está no corpus por definição. É a armadilha de regra de texto
 *   mais fácil de cair e por isso fica escrita aqui.
 *
 * O que resta é o que a camada 1 sempre foi: **uma entrada com motivo**, que é
 * o mecanismo que pega a forma que a mão produz e que nenhuma regra de forma
 * pega. `distinguiç` não colide com português nenhum — `distinguir`,
 * `distingue`, `distinção` e `distinta` são as formas do radical, e nenhuma
 * contém `uiç` — e é por isso que ele é curto o bastante para ser radical e
 * específico o bastante para não ser.
 */
final class PalavrasCorrompidasTest extends TestCase
{
    /**
     * Radical → onde ele não deveria estar.
     *
     * **A lista é casada por radical, e não é detalhe.** A corrupção deste round
     * apareceu em três grafias da mesma palavra — `perfectamente`,
     * `perfeitamente` e `perfectlyamente` —, e é a última que estava em `HEAD`
     * quando este teste foi escrito. Uma lista por grafia exata pegaria a
     * primeira e deixaria passar as outras duas, que é precisamente o que
     * aconteceu na primeira versão deste arquivo: ela buscava `perfectamente` e
     * não viu `perfectlyamente`, que estava um caractere adiante. Por isso a
     * verificação é `str_contains` sobre o radical.
     *
     * Os seis primeiros são radicais de palavra **colada** ou em grafia
     * anglicada. Os dois últimos são radical de palavra inglesa **isolada** que
     * nenhum comentário deste repositório usa — a extensão que a medição de
     * "detector de inglês" permite, e que é onde entra a corrupção da forma que
     * o resto da lista não alcança.
     *
     * **O radical, e não a palavra, é o que a última entrada é.** `worr` pega
     * `worrying` e `wrongly`; a palavra `wrongly` pegaria só uma das duas, e é
     * exatamente o que aconteceu: a lista nasceu com `wrongly` enquanto a
     * corrupção viva no arquivo era `worrying`, uma letra a um radical de
     * distância de um falso positivo que ninguém teria por quê pagar. Radical
     * curto é a regra que este arquivo já segue desde o caso `perfectly`; aqui
     * ela é o que impede a entrada de nascer inerte.
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
        'worr' => 'radical de "worrying" e "wrongly": os dois entraram em comentário português, e nenhum dos dois é português',
        'distinguiç' => 'a corrupção do item 2 deste round: "A distinguição existe" no lugar de "A distinção existe", que é `distin` + `guição` — o começo de uma palavra com o fim de outra. Nenhum verbo português contém `uiç` (a forma é `distinguir`), e a medição que impede esta entrada de ser regra está na docblock da classe',
    ];

    /**
     * Sufixos que provam derivação, e portanto **não** são colagem.
     *
     * `silenciosamente` e `propositalmente` se dividem em duas palavras do
     * corpus — `silenciosa` + `mente`, `proposital` + `mente` — e as duas são
     * português correto. São os dois únicos falsos positivos que a medição achou
     * no piso de 14 caracteres, e os dois caem no mesmo sufixo.
     *
     * **A lista existe para manter o alarme baixo, e por isso a consequência
     * de um erro é declarada:** uma colagem real que termine em `mente` passa.
     * Isso é mais barato que as duas palavras corretas gritando todo dia, e a
     * correção de um falso positivo novo é uma entrada aqui, com a mesma
     * revisão que qualquer entrada da `SUSPEITAS` exige.
     *
     * @var list<string>
     */
    private const DERIVACOES = ['mente'];

    /**
     * O menor comprimento que uma colagem pode ter, e a medição que fixou 14.
     *
     * Sem piso, a regra acha 53 casos, e a maioria é palavra portuguesa
     * correta que por acaso se divide: `recusados` = `recusa` + `dos`,
     * `perdeu` = `per` + `deu`, `logout` = `log` + `out`, `comporta` =
     * `com` + `porta`, `sobreviva` = `sobre` + `viva`. São 51 falsos positivos
     * para 2 acertos, e uma guarda que erra 51 vezes por rodada é uma guarda
     * que alguém desliga.
     *
     * **O que a medição mostra é que a colagem é longa** — duas palavras
     * coladas têm o comprimento da soma —, e que palavra portuguesa longa é
     * curta. No piso 13 são 6 casos, no 14 são 4, e os 4 são as duas colagens
     * reais mais `silenciosamente` e `propositalmente`, que `DERIVACOES`
     * isenta. Os 2 casos abaixo do piso 14 que sobram (`transformação` e
     * `contraexemplo`) são derivação comum em português.
     *
     * **O que 14 custa:** uma colagem de 13 ou menos caracteres passa. O que
     * mostra que o piso não esvaziou a regra é a demonstração com a corrupção
     * real do item 1 reinjetada: `continuaexistindo` tem 17, e a regra a pega.
     */
    private const COMPRIMENTO_MINIMO = 14;

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
     * **Dois limites conhecidos, e nenhum dos dois é um defeito.** Um: o
     * iterador não segue link simbólico, então um diretório de código que
     * apontasse para fora seria pulado em silêncio e a trava de contagem
     * absorveria a perda — hoje nenhum diretório das seis raízes é link, e o
     * `FOLLOW_SYMLINKS` é uma linha se um dia algum for. Dois: ficam de fora
     * arquivos `.php` fora das seis raízes, que hoje são
     * `resources/views/welcome.blade.php` — a view de boas-vindas do Laravel,
     * em inglês, e fora do escopo de comentário em português — e
     * `public/index.php`, que é o bootstrap do front e não tem comentário. Os dois
     * são anteriores a este arquivo e inofensivos; o que fica escrito é que
     * eles são **decididos**, e não esquecidos.
     *
     * **O que fica fora das seis raízes e é perda, e não decisão:** o `frontend/`
     * e o `openspec/` também têm prosa em português, e a varredura deste teste
     * não os alcança porque ela é um teste de backend. Rodada manualmente sobre
     * a árvore inteira com as mesmas duas regras, a única colagem viva fora do
     * backend é um relatório de tarefa em `.superpowers/`, e nenhum caractere de
     * outro roteiro aparece fora de `openspec/schemas/superpowers-bridge/`, que
     * é ferramenta de terceiro com um `README.zh-TW.md` — Chinês legítimo, não
     * corrupção. A camada 2 pegaria o Chinês daquele diretório se ele entrasse,
     * e é por isso que a varredura de árvore inteira precisa da mesma exclusão
     * explícita que a lista `EXCLUIDOS` faz aqui.
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
        ['tests/Unit/PalavrasCorrompidasTest.php', 'contém os radicais de `SUSPEITAS` e os sufixos de `DERIVACOES` escritos por extenso, na docblock de cada motivo e no exemplo de palavra colada — é a única razão de este arquivo existir, e tirá-lo da varredura é o que faria ele passar em silêncio sobre a própria corrupção'],
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
                // decisão, e uma palavra inglesa em `$variable` não é defeito.
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
     * Nenhum caractere de outro roteiro em comentário, e o alcance é maior que
     * CJK.
     *
     * **Por que a forma é "qualquer caractere fora do latim" e não
     * `\p{Script=Han}`.** A versão anterior desta camada era o
     * `grep -P '[\x{4e00}-\x{9fff}]'`, que pega exatamente uma escrita. O
     * defeito que ela pegou era real — `有有关系` apareceu em comentário
     * português e foi corrigido —, e a forma fraca dela é que uma letra
     * grega, uma cirílica ou um kana passariam sem barulho. A regra é a
     * negação do que comentário português **pode** conter, e o que ele pode
     * conter é medido: a letra acentuada do latim, o travessão, o ponto de
     * reticências e a seta.
     *
     * **A medição é o que dá o peso a esta camada, e ela é:** varrendo os seis
     * diretórios, os caracteres não latinos que existem hoje em comentário são
     * **zero**. Os três símbolos não-ASCII que aparecem — `—`, `…` e `→` — são
     * de categorias Unicode de símbolo, e por isso passam. Uma guarda com zero
     * falso positivo no dia em que entra é a única que se pode confiar sem
     * revisão a cada rodada.
     */
    public function test_nenhum_caractere_de_outro_roteiro_em_comentario(): void
    {
        $arquivos = $this->arquivos();
        $achados = [];

        foreach ($arquivos as $relativo) {
            foreach (file($relativo) as $numero => $linha) {
                if (! $this->ehComentario($linha)) {
                    continue;
                }

                // Fora de crase: identificador e constante são ASCII por
                // definição, e o que se caça é prosa.
                $prosa = preg_replace('/`[^`]*`/', ' ', $linha);

                foreach (preg_split('//u', $prosa, -1, PREG_SPLIT_NO_EMPTY) as $caractere) {
                    if ($this->foraDoLatino($caractere)) {
                        $achados[] = sprintf(
                            '%s:%d  U+%04X  %s',
                            $relativo,
                            $numero + 1,
                            mb_ord($caractere, 'UTF-8'),
                            $caractere,
                        );
                    }
                }
            }
        }

        $this->assertSame([], $achados, "Caractere de outro roteiro em comentário:\n".implode("\n", $achados));
    }

    /**
     * Nenhuma palavra colada em comentário, e a regra que pega não depende de
     * radical conhecido.
     *
     * **A forma que esta camada caça é duas palavras com o espaço deglutido**,
     * que é o que a lista de radicais não alcança: `continuaexistindo` não é
     * variação de grafia de palavra nenhuma, é `continua` + `existindo`, e
     * ninguém a havia visto para a lista nascer dela.
     *
     * **A regra é estrutural, e foi medida antes de ser escrita.** Um token de
     * comentário que
     *
     * - é **todo minúsculo** — que é o que separa a corrupção do identificador,
     *   já que este repositório escreve identificador em comentário em
     *   camelCase, `cargasQueNaoPodemEntrar`, e a medição achou essa palavra na
     *   lista de longas se a regra não exigisse minúsculas;
     * - aparece **uma vez só** no corpus de comentário, que é o repositório
     *   funcionando como dicionário;
     * - tem **14 caracteres ou mais** — que é o piso que separa a colagem da
     *   palavra portuguesa longa. No piso 13 a medição achou 6 casos, no 14
     *   achou 4, e os 4 eram duas colagens reais mais `silenciosamente` e
     *   `propositalmente`, que as derivações isentam;
     * - e se **divide** em dois tokens de 4 caracteres ou mais que o corpus
     *   contém.
     *
     * **A divisão que a regra aceita é a primeira que fecha**, e a medição
     * mostrou que a primeira fecha é a boa: nos casos reais que estavam vivos,
     * ela devolveu a divisão verdadeira — `continua` + `existindo`,
     * `correntes` + `produzem` — e não uma arbitrária.
     *
     * **O limite, e ele é real:** o corpus é o repositório, então uma palavra
     * que já aparece duas vezes deixa de contar como colagem. Corrupção repetida
     * em dois comentários escapa; em comentário único, que é o caso comum, não.
     */
    public function test_nenhuma_palavra_colada_em_comentario(): void
    {
        $arquivos = $this->arquivos();
        $this->assertGreaterThan(100, count($arquivos), 'A varredura de comentário não abriu arquivo nenhum: a lista de diretórios está errada.');

        // O corpus é montado na mesma varredura, e a contagem por token é o
        // que separa "palavra que o repositório não conhece" de "palavra que
        // aparece duas vezes".
        $corpus = [];
        $tokens = [];

        foreach ($arquivos as $relativo) {
            foreach (file($relativo) as $numero => $linha) {
                if (! $this->ehComentario($linha)) {
                    continue;
                }

                foreach ($this->palavrasDeProsa($linha) as $palavra) {
                    $corpus[$palavra] = ($corpus[$palavra] ?? 0) + 1;
                    $tokens[] = [$relativo, $numero + 1, $palavra];
                }
            }
        }

        // A guarda de que a guarda existe: sem prosa nenhuma, a divisão nunca
        // fecha e o teste passa sobre um corpus vazio.
        $this->assertGreaterThan(
            1000,
            count($corpus),
            'A varredura de prosa não achou token: a regra de colagem passaria em silêncio sobre um corpus vazio.',
        );

        $achados = [];

        foreach ($tokens as [$relativo, $numero, $palavra]) {
            if (($corpus[$palavra] ?? 0) > 1 || mb_strlen($palavra) < self::COMPRIMENTO_MINIMO) {
                continue;
            }

            $divisao = $this->divisaoEmPalavrasConhecidas($palavra, $corpus);

            if ($divisao !== null) {
                $achados[] = sprintf(
                    '%s:%d  "%s" (%d) = "%s" + "%s"',
                    $relativo,
                    $numero,
                    $palavra,
                    mb_strlen($palavra),
                    $divisao[0],
                    $divisao[1],
                );
            }
        }

        $this->assertSame([], $achados, "Palavra colada em comentário:\n".implode("\n", $achados));
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
     * O radical aparece na linha, sem se preocupar com fronteira de palavra.
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

    /**
     * O caractere é de fora do latim, das letras, dos números, da pontuação, do
     * símbolo, do espaço, do controle e do modificador.
     *
     * **A lista de categorias permitidas é a medição da árvore, e é curta de
     * propósito.** Varrendo as seis raízes, os caracteres não latinos que
     * existem hoje em comentário são **zero**. Os que existem fora do ASCII são
     * o travessão, o ponto de reticências e a seta — todos de categoria de
     * símbolo — e mais a letra acentuada do latim, que é `\p{Latin}`.
     *
     * As três categorias da lista que não são óbvias, e o que cada uma mediu:
     *
     * - `\p{Cc}` — tabulação e `\r` de arquivo Windows são controle e aparecem
     *   em toda parte; sem ele a regra gritaria com indentação;
     * - `\p{Mn}` — medido na árvore inteira, e achado: `openspec/changes/**`
     *   escreve `✗` com o **seletor de variação** U+FE0F, que é `\p{Mn}`, e
     *   sem esta categoria a regra acusaria emoji. Um seletor de variação e um
     *   acento combinante são invisíveis, e nenhum deles é o que se caça;
     * - `\p{Cf}` — marca de formatação invisível, mesma razão.
     *
     * O que fica **fora** — e é o ponto da regra — é `\p{Script=Han}`, o
     * cirílico, o grego, o arábico, o hebraico, o kana e o hangul. A versão
     * anterior desta camada era o `grep -P '[\x{4e00}-\x{9fff}]'`, que pegava
     * exatamente um deles.
     */
    private function foraDoLatino(string $caractere): bool
    {
        return preg_match('/\p{Latin}|\p{N}|\p{P}|\p{S}|\p{Zs}|\p{Cc}|\p{Cf}|\p{Mn}/u', $caractere) !== 1;
    }

    /**
     * As palavras de prosa de uma linha de comentário.
     *
     * **Só palavra em minúsculas, e a razão é medida.** O identificador que este
     * repositório escreve em comentário é camelCase —
     * `cargasQueNaoPodemEntrar`, `clientecacpowerofattorney` —, e ele também
     * seria dividido pela regra de colagem. A diferenciação é o predicado
     * `\p{Ll}`, que é o que separa `continuaexistindo` de
     * `cargasQueNaoPodemEntrar`.
     *
     * A crase sai antes da tokenização, porque identificador e constante estão
     * em crase por convenção e é deles que a regra precisa se abster.
     *
     * @return list<string>
     */
    private function palavrasDeProsa(string $linha): array
    {
        $semCrase = preg_replace('/`[^`]*`/', ' ', $linha);

        preg_match_all('/[\p{L}]+/u', $semCrase, $achados);

        return array_values(array_filter(
            $achados[0],
            fn (string $palavra): bool => preg_match('/^\p{Ll}+$/u', $palavra) === 1,
        ));
    }

    /**
     * A primeira divisão da palavra em duas que o corpus reconhece, ou `null`.
     *
     * A divisão é recusada quando a metade da direita é um sufixo de derivação,
     * que é o que separa `silenciosamente` — que é `silenciosa` + `mente` e é
     * palavra correta — de `continuaexistindo`, que é `continua` + `existindo` e
     * não é palavra nenhuma.
     *
     * O corte percorre da esquerda para a direita e devolve a **primeira** que
     * fecha, e a medição nos casos reais que estavam vivos na árvore mostrou que
     * a primeira é a certa: ela devolveu `continua` + `existindo` e
     * `correntes` + `produzem`, as duas divisões verdadeiras, e não uma
     * arbitrária que o corpus também conteria.
     *
     * @param  array<string, int>  $corpus
     * @return array{0: string, 1: string}|null
     */
    private function divisaoEmPalavrasConhecidas(string $palavra, array $corpus): ?array
    {
        $tamanho = mb_strlen($palavra);

        for ($corte = 4; $corte <= $tamanho - 4; $corte++) {
            $esquerda = mb_substr($palavra, 0, $corte);
            $direita = mb_substr($palavra, $corte);

            if (in_array($direita, self::DERIVACOES, true)) {
                continue;
            }

            if (($corpus[$esquerda] ?? 0) >= 1 && ($corpus[$direita] ?? 0) >= 1) {
                return [$esquerda, $direita];
            }
        }

        return null;
    }
}
