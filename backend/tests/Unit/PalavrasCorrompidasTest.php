<?php

namespace Tests\Unit;

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
     * O que segue são radicais de palavra **colada** ou em grafia anglicada, e
     * não palavra solta: uma palavra isolada em inglês dentro de comentário
     * português é nome de arquivo, tipo de mídia ou nome de classe, e distingui-la
     * de tradução pela metade é leitura, não filtro.
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
    ];

    /**
     * Arquivos de produção e de teste onde comentário em português é a norma.
     *
     * A lista é explícita e não um `glob`, por dois motivos: um filtro por
     * diretório pegaria `vendor/`, e pegaria o `resources/` do ICP-Brasil; e a
     * lista é a revisão que este teste tem de fazer quando um arquivo novo
     * entra no escopo — acrescentar o caminho é o que faz o teste cobri-lo, e
     * esquecer é o modo dele ficar vazio sem ninguém perceber.
     *
     * @var list<string>
     */
    private const ARQUIVOS = [
        'app/Models/AccountCertificate.php',
        'app/Models/Account.php',
        'app/Policies/AccountCertificatePolicy.php',
        'app/Http/Controllers/Tenant/AccountCertificateController.php',
        'app/Http/Requests/Tenant/UploadAccountCertificateRequest.php',
        'app/Http/Requests/Admin/UpsertSerproConnectionRequest.php',
        'app/Http/Resources/AccountCertificateResource.php',
        'app/Services/AccountCertificateVault.php',
        'app/Services/CertificatePkcs12.php',
        'app/Services/SerproTermSigner.php',
        'app/Support/SerproSigner.php',
        'database/factories/AccountCertificateFactory.php',
        'database/migrations/2026_09_28_085929_create_account_certificates_table.php',
        'tests/Feature/SerproAccountCertificateTest.php',
        'tests/Feature/SerproConnectionApiTest.php',
        'tests/Unit/SerproTermSignerTest.php',
    ];

    public function test_nenhuma_palavra_corrompida_em_comentario(): void
    {
        $achados = [];

        foreach (self::ARQUIVOS as $relativo) {
            $caminho = base_path($relativo);
            $this->assertFileExists($caminho);

            foreach (file($caminho) as $numero => $linha) {
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
     * diferentes — um limite pegaria a primeira e deixaria passar as outras
     * duas, que foi o que aconteceu na primeira versão deste arquivo. Com
     * radical, as três caem; e o custo é uma palavra portuguesa que contenha o
     * radical por acaso também cair, que é o motivo de os radicais serem curtos.
     */
    private function contem(string $linha, string $radical): bool
    {
        return str_contains(mb_strtolower($linha), mb_strtolower($radical));
    }
}
