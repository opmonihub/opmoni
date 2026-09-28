<?php

namespace Tests\Unit;

use App\Services\CertificatePkcs12;
use App\Services\LegacyPkcs12Ciphertext;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/**
 * A leitura de um PKCS#12 é a mesma operação para o certificado do cliente e para
 * o e-CNPJ do escritório, e é o mesmo erro nos dois casos: senha errada,
 * arquivo ilegível e container legado com RC2 são três respostas diferentes do
 * mesmo `openssl_pkcs12_read` que só a fila de erro do OpenSSL distingue. Este
 * teste fixa essa distinção **no lugar onde ela é decidida**, que é o ponto
 * único compartilhado — o cofre de cliente, que a extração não pode alterar, e o
 * cofre do Account, que ainda não existe, consomem a mesma resposta.
 *
 * Aqui também mora a frase de RC2 com o nome do cliente, que é montada em dois
 * arquivos e que nenhum outro teste do repositório vê inteira — o teste
 * congelado do cofre confere duas partes dela, o que passa igual para a frase
 * certa e para a frase estragada.
 *
 * **Nenhum PFX é versionado.** `*.pfx` e `*.p12` estão no `.gitignore` da raiz, e
 * a regra é do arquivo inteiro: PFX é o formato de procuração eletrônica — chave
 * junto com certificado — e um arquivo sintético não deixa de ser o formato que
 * não se versiona. Os containers legados são dois, pelo mesmo motivo: um com RC2,
 * que é o que a ICP-Brasil emitiu, e um com RC4, que dá o mesmo `unsupported` sem
 * nenhum OID de RC2 nos bytes. O gerador de fixture é **duplicado** de
 * `ClientCertificateVaultLegacyPfxTest` em vez de extraído para um trait
 * compartilhado: aquele teste é a prova de que a extração não mudou o
 * comportamento do cofre de cliente, e mexer no arquivo que faz essa prova
 * invalidaria a própria prova. O preço é uma duplicata intencional de um gerador
 * de descarte.
 *
 * **Nenhum `openssl.cnf` do host é lido.** Todos os certificados de descarte saem
 * de um config escrito por esta classe — ver `opensslConfig()`.
 */
class CertificatePkcs12Test extends TestCase
{
    private const PASSWORD = 'senha-de-teste';

    private const WRONG_PASSWORD = 'senha-errada';

    /**
     * O PKCS#12 legado, ou o motivo pelo qual este ambiente não o tem. Ver
     * `ClientCertificateVaultLegacyPfxTest` para o mesmo critério de "serviu".
     */
    private static ?string $legacyPfx = null;

    private static ?string $legacyUnavailable = null;

    private static ?string $otherLegacyPfx = null;

    private static ?string $otherLegacyUnavailable = null;

    private static ?string $opensslConfig = null;

    private static ?string $emptySubjectConfig = null;

    /** @var list<string> */
    private static array $configDirectories = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        [self::$legacyPfx, self::$legacyUnavailable] = self::buildLegacyPfx();
        [self::$otherLegacyPfx, self::$otherLegacyUnavailable] = self::buildOtherLegacyPfx();
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$configDirectories as $directory) {
            @unlink($directory.DIRECTORY_SEPARATOR.'openssl.cnf');
            @rmdir($directory);
        }

        self::$configDirectories = [];
        self::$opensslConfig = null;
        self::$emptySubjectConfig = null;

        parent::tearDownAfterClass();
    }

    public function test_pfx_com_a_senha_certa_devolve_o_contrato_declarado(): void
    {
        $bytes = $this->pfx(['CN' => 'Escritorio Contabil Andre Siqueira'], 30);
        $before = Carbon::now()->subMinute();

        $inspected = (new CertificatePkcs12)->inspect($bytes, self::PASSWORD);

        // A chave privada e o certificado saem do mesmo `openssl_pkcs12_read` que
        // os metadados: quem precisa assinar usa estes dois, quem precisa do
        // histórico usa os outros. E a chave não é campo opcional — a unidade
        // recusa o arquivo em vez de devolver `pkey` vazio, porque string vazia
        // cumpriria o tipo declarado sem ser material de assinatura.
        $this->assertStringContainsString('BEGIN CERTIFICATE', $inspected['cert']);
        $this->assertStringContainsString('PRIVATE KEY', $inspected['pkey']);
        $this->assertNotSame('', $inspected['serial']);
        $this->assertNotSame('desconhecido', $inspected['serial']);
        $this->assertSame(hash('sha256', $bytes), $inspected['sha256']);

        $this->assertTrue($inspected['valid_from']->betweenIncluded($before, Carbon::now()->addMinute()));
        $this->assertTrue($inspected['valid_until']->greaterThan($inspected['valid_from']));
        $this->assertEqualsWithDelta(30, abs($inspected['valid_until']->diffInDays($inspected['valid_from'])), 1);
    }

    public function test_subject_identifica_o_certificado_pelo_dn_inteiro(): void
    {
        // Um e-CNPJ real tem subject com vários campos — CN com razão social,
        // `serialNumber` com o CNPJ, país, ICP-Brasil —, e o que o cofre grava em
        // `subject` é o DN inteiro, na ordem que o OpenSSL traz.
        $bytes = $this->pfx(['CN' => 'Escritorio Contabil Andre Siqueira'], 30);

        $subject = (new CertificatePkcs12)->inspect($bytes, self::PASSWORD)['subject'];

        $this->assertSame(
            '/CN=Escritorio Contabil Andre Siqueira/C=BR/O=ICP-Brasil',
            $subject,
        );
    }

    public function test_certificado_sem_subject_e_gravado_como_desconhecido(): void
    {
        // O último fallback: um certificado sem subject volta com o `name` vazio e
        // o array vazio, e a unidade precisa de uma palavra para essa coluna.
        // `desconhecido` diz que o arquivo é legível e que o subject é que não se
        // sabe, o que meia verdade não diria. O certificado sem subject é
        // degenerado mas construível; um `name` vazio num certificado que tem
        // subject, não é — e é por isso que o ramo do meio, que remonta o
        // subject a partir do array, continua sem teste.
        $bytes = $this->pfx([], 30, self::emptySubjectConfig());

        $inspected = (new CertificatePkcs12)->inspect($bytes, self::PASSWORD);

        $this->assertSame('desconhecido', $inspected['subject']);
        $this->assertStringContainsString('BEGIN CERTIFICATE', $inspected['cert']);
    }

    public function test_trocar_o_sujeto_da_frase_de_rc2_monta_a_frase_do_cliente(): void
    {
        // A frase que o operador lê quando o certificado do cliente não abre é
        // montada a partir de uma frase que vive em outro arquivo, e este é o
        // único lugar do repositório que a vê inteira. O teste congelado do cofre
        // confere duas partes dela — o nome do cliente e a palavra "criptografia
        // legada RC2" —, o que passa igual para a frase certa e para a frase
        // estragada.
        $named = LegacyPkcs12Ciphertext::becauseRc2()
            ->withSubject('O certificado do cliente Cliente RC2 Seguro');

        $this->assertSame(
            'O certificado do cliente Cliente RC2 Seguro usa criptografia legada RC2 e precisa ser exportado novamente sem a opção legacy.',
            $named->errors()['certificate'][0],
        );
        $this->assertArrayNotHasKey('password', $named->errors());
    }

    public function test_trocar_o_sujeto_de_uma_frase_reescrita_falha_em_vez_de_cortar(): void
    {
        // A regressão que já aconteceu uma vez nesta task: uma frase reescrita
        // que deixou de começar com o sujeito trocável produzia
        // "O certificado do cliente Xtificado usa criptografia legada RC2…" —
        // lixo para o cliente, com a suíte inteira verde. Aqui a reescrita falha
        // no lugar onde ela é usada, e a falha é de programador, não de
        // validação: ninguém resolve um código quebrado digitando outra senha.
        $this->expectException(LogicException::class);

        LegacyPkcs12Ciphertext::sentenceFor(
            'Este certificado usa criptografia legada RC2 e precisa ser exportado novamente sem a opção legacy.',
            'O certificado do cliente Cliente RC2 Seguro',
        );
    }

    public function test_o_contrato_nao_traz_a_senha_nem_a_que_fora_do_contrato(): void
    {
        $bytes = $this->pfx(['CN' => 'Escritorio Contabil Andre Siqueira'], 30);

        $inspected = (new CertificatePkcs12)->inspect($bytes, self::PASSWORD);

        // A senha entra no PFX e o PFX sai daqui: quem chama precisa de material
        // de assinatura, não de volta a senha que o protege. O conjunto de chaves
        // é o contrato inteiro, para que acrescentar um campo que vaze algo novo
        // seja uma falha de teste e não uma leitura de tela.
        $this->assertSame(
            ['cert', 'pkey', 'subject', 'serial', 'valid_from', 'valid_until', 'sha256'],
            array_keys($inspected),
        );
        $this->assertArrayNotHasKey('password', $inspected);

        foreach ($inspected as $value) {
            $this->assertStringNotContainsString(self::PASSWORD, is_string($value) ? $value : '');
        }
    }

    public function test_senha_errada_recusa_pela_chave_password_sem_vazar_o_arquivo(): void
    {
        $bytes = $this->pfx(['CN' => 'Escritorio Contabil Andre Siqueira'], 30);

        try {
            (new CertificatePkcs12)->inspect($bytes, self::WRONG_PASSWORD);
            $this->fail('Uma senha errada deveria recusar o arquivo.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('password', $exception->errors());
            $this->assertArrayNotHasKey('certificate', $exception->errors());

            $message = $exception->errors()['password'][0] ?? '';
            $this->assertStringNotContainsString(self::WRONG_PASSWORD, $message);
            $this->assertStringNotContainsString($bytes, $message);
        }
    }

    public function test_arquivo_que_nao_e_pkcs12_recusa_pela_chave_password(): void
    {
        // Um `.pfx` que não é PKCS#12 é o mesmo que senha errada para o chamador:
        // a validação de extensão e tamanho acontece antes, no request, e o
        // `openssl_pkcs12_read` não diz qual das duas coisas houve. O que muda é o
        // texto — e o texto não pode prometer RC2 sem ser RC2.
        $bytes = 'isto nao e um pkcs12';

        try {
            (new CertificatePkcs12)->inspect($bytes, self::PASSWORD);
            $this->fail('Bytes que não são PKCS#12 deveriam ser recusados.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('password', $exception->errors());
        }
    }

    public function test_container_legado_de_outro_algoritmo_nao_vira_rc2(): void
    {
        if (self::$otherLegacyPfx === null) {
            $this->markTestSkipped(sprintf(
                'O container legado de outro algoritmo não foi gerado: %s.',
                self::$otherLegacyUnavailable ?? 'motivo não determinado',
            ));
        }

        $bytes = (string) self::$otherLegacyPfx;

        // Um container com RC4 dá exatamente o mesmo sintoma na fila de erro — o
        // `unsupported` do provedor legado ausente — e não traz nenhum dos OIDs
        // de RC2 nos bytes. É a razão de a detecção olhar as duas coisas:
        // `unsupported` sozinho não diz **qual** algoritmo, e dizer RC2 de um
        // container que não é RC2 manda o cliente refazer um export que já está
        // certo.
        //
        // A mensagem que sobra é a de senha errada, e essa é a redação que o
        // produto já tinha: a leitura compartilhada só promete RC2 quando tem
        // byte de RC2. Quem quiser prometer mais precisa antes saber mais.
        try {
            (new CertificatePkcs12)->inspect($bytes, self::PASSWORD);
            $this->fail('Um container que o OpenSSL não abre deveria ser recusado.');
        } catch (ValidationException $exception) {
            $this->assertNotInstanceOf(LegacyPkcs12Ciphertext::class, $exception);
            $this->assertArrayHasKey('password', $exception->errors());
        }
    }

    public function test_a_falha_de_outra_leitura_nao_decide_o_destino_desta(): void
    {
        if (self::$legacyPfx === null) {
            $this->markTestSkipped(sprintf(
                'A leitura do PFX com RC2 não foi exercitada: %s. Comando para refazer o fixture: docblock de ClientCertificateVaultLegacyPfxTest.',
                self::$legacyUnavailable ?? 'motivo não determinado',
            ));
        }

        $legacy = (string) self::$legacyPfx;

        // O mesmo container legado, cortado no meio: os OIDs do RC2 continuam
        // dentro dos bytes e a leitura falha por um motivo que não é RC2 — erro
        // de ASN.1, que é o que um upload pela metade produz. É o par exato do
        // teste de RC2 com a detecção desligada: mesmos bytes legados, sintoma
        // diferente na fila.
        $cut = substr($legacy, 0, (int) (strlen($legacy) * 0.6));
        $probe = [];
        $this->assertFalse(@openssl_pkcs12_read($cut, $probe, self::PASSWORD));

        // As mesmas duas strings que o produto procura, em minúsculas como ele
        // procura — a fila é drenada uma vez só, porque a segunda leitura da fila
        // leria o vazio e passaria sem provar nada.
        $erros = strtolower($this->drainOpenSslErrors());
        $this->assertStringNotContainsString('rc2', $erros);
        $this->assertStringNotContainsString('unsupported', $erros);

        // Agora a fila de erro é de outra leitura: o container legado inteiro,
        // aberto sem sucesso, deixando `unsupported` para quem vier ler depois. A
        // fila é por thread e ninguém avisa que foi ela quem encheu.
        $this->assertFalse(@openssl_pkcs12_read($legacy, $probe, self::PASSWORD));

        try {
            (new CertificatePkcs12)->inspect($cut, self::PASSWORD);
            $this->fail('O container cortado deveria ser recusado.');
        } catch (ValidationException $exception) {
            $this->assertNotInstanceOf(
                LegacyPkcs12Ciphertext::class,
                $exception,
                'Um `unsupported` deixado por outra leitura não é o motivo desta.',
            );
            $this->assertArrayHasKey('password', $exception->errors());
        }
    }

    /**
     * A fila de erro do OpenSSL depois da última leitura, esvaziada.
     */
    private function drainOpenSslErrors(): string
    {
        $errors = '';

        while (($error = openssl_error_string()) !== false) {
            $errors .= $error."\n";
        }

        return $errors;
    }

    public function test_pfx_legado_rc2_recusa_pela_chave_certificate_com_mensagem_distinta(): void
    {
        if (self::$legacyPfx === null) {
            // O mesmo critério honesto do cofre: um PFX que este OpenSSL abre não
            // exercita a detecção, porque `openssl_pkcs12_read` não falha e a fila
            // de erro não tem o que ler. Pular com o motivo escrito vale mais que
            // passar sem ter passado por nada.
            $this->markTestSkipped(sprintf(
                'A leitura do PFX com RC2 não foi exercitada: %s. Comando para refazer o fixture: docblock de ClientCertificateVaultLegacyPfxTest.',
                self::$legacyUnavailable ?? 'motivo não determinado',
            ));
        }

        $bytes = (string) self::$legacyPfx;

        try {
            (new CertificatePkcs12)->inspect($bytes, self::PASSWORD);
            $this->fail('Um PFX com RC2 não deveria ser aceito.');
        } catch (LegacyPkcs12Ciphertext $exception) {
            // A chave `certificate` é o que a tela já escuta hoje, e a subclasse
            // é o que permite ao cofre dizer de quem é o arquivo sem a unidade
            // compartilhada conhecer cliente nem escritório.
            $this->assertArrayHasKey('certificate', $exception->errors());

            $message = $exception->errors()['certificate'][0] ?? '';
            $this->assertStringContainsString('criptografia legada RC2', $message);
            $this->assertStringNotContainsString(self::PASSWORD, $message);
            $this->assertStringNotContainsString($bytes, $message);

            // "Mensagem distinta" quer dizer distinta *da de senha errada*: os dois
            // casos saem do mesmo `openssl_pkcs12_read` que não diz qual foi, e o
            // que o operador faz depois — refazer o export ou digitar a senha de
            // novo — não é o mesmo nos dois.
            $modern = $this->pfx(['CN' => 'Escritorio Contabil Andre Siqueira'], 30);
            $this->assertNotSame(
                $this->rejectionFor($modern, self::WRONG_PASSWORD)->errors()['password'][0] ?? '',
                $message,
            );
        }
    }

    /**
     * A recusa que `inspect()` devolve para os bytes dados, sem impedir que o
     * teste siga depois de compará-la com outra.
     */
    private function rejectionFor(string $bytes, string $password): ValidationException
    {
        try {
            (new CertificatePkcs12)->inspect($bytes, $password);
        } catch (ValidationException $exception) {
            return $exception;
        }

        // `fail()` lança, e devolver o que ele lança satisfaz o tipo de retorno
        // para quem lê o código — e o tipo declarado está aqui justamente para
        // dizer que este método não tem como devolver outra coisa.
        return $this->fail('A leitura deveria ter sido recusada.');
    }

    /**
     * Um PKCS#12 descartável, gerado agora. Nenhum fixture de PFX é versionado.
     *
     * @param  array<string, string>  $subject
     */
    private function pfx(array $subject, int $days, ?string $config = null): string
    {
        $options = ['config' => $config ?? self::opensslConfig()];

        $key = openssl_pkey_new(array_merge(
            ['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA],
            $options,
        ));
        $this->assertNotFalse($key, 'O par de chaves de descarte não pôde ser gerado.');

        $csr = openssl_csr_new($subject, $key, array_merge(['digest_alg' => 'sha256'], $options));
        $this->assertNotFalse($csr, 'O CSR de descarte não pôde ser gerado.');

        $certificate = openssl_csr_sign($csr, null, $key, $days, array_merge(['digest_alg' => 'sha256'], $options));
        $this->assertNotFalse($certificate, 'O certificado de descarte não pôde ser assinado.');

        $bytes = '';
        $this->assertTrue(
            openssl_pkcs12_export($certificate, $bytes, $key, self::PASSWORD, $options),
            'O PFX de descarte não pôde ser exportado.',
        );

        return $bytes;
    }

    /**
     * O `openssl.cnf` dos certificados de descarte, escrito aqui.
     *
     * **Não é o do host, e essa é a diferença que importa.** Com o
     * `/etc/ssl/openssl.cnf` da máquina, o `dn` do certificado — e portanto o
     * `subject` que a unidade devolve — dependia do que aquele arquivo tivesse em
     * `[ req_distinguished_name ]`: o `CN` vinha do argumento e o resto vinha de um
     * `countryName_default = AU`, um `stateOrProvinceName_default = Some-State` e
     * um `organizationName_default = Internet Widgits Pty Ltd` que ninguém neste
     * repositório escolheu. Um teste que dependesse disso passaria ou falharia por
     * causa da máquina, e a máquina muda. Aqui o `dn` tem três campos porque este
     * arquivo diz que tem três, e o OpenSSL precisa do `config` para gerar par de
     * chaves e CSR neste ambiente — o que significa que uma máquina sem aquele
     * arquivo deixaria a classe inteira em erro, e não em branco.
     */
    private static function opensslConfig(): string
    {
        return self::$opensslConfig ??= self::writeOpensslConfig('multi-rdn', <<<'OPENSSL'
            [ req ]
            distinguished_name = dn
            prompt = no

            [ dn ]
            CN = substituido pelo argumento
            countryName_default = BR
            0.organizationName_default = ICP-Brasil
            OPENSSL);
    }

    /**
     * O `openssl.cnf` de um certificado **sem subject nenhum**, para o `name` do
     * OpenSSL vir vazio e o último fallback da unidade ser alcançável.
     */
    private static function emptySubjectConfig(): string
    {
        return self::$emptySubjectConfig ??= self::writeOpensslConfig('sem-subject', <<<'OPENSSL'
            [ req ]
            distinguished_name = dn
            prompt = no

            [ dn ]
            OPENSSL);
    }

    /**
     * @return string o caminho do arquivo, que só existe enquanto a classe roda
     */
    private static function writeOpensslConfig(string $slug, string $content): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pkcs12-dn-'.$slug.'-'.bin2hex(random_bytes(6));

        // Exceção e não pulo, ao contrário do gerador de container legado: um
        // ambiente que não produz PKCS#12 legado é uma capacidade ausente e o
        // teste honesto é pular com o motivo, mas um `sys_get_temp_dir()` que
        // não aceita diretório é um ambiente quebrado, e pular dele esconde a
        // quebra.
        if (! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Não foi possível criar o diretório temporário em %s', sys_get_temp_dir()));
        }

        self::$configDirectories[] = $directory;

        $path = $directory.DIRECTORY_SEPARATOR.'openssl.cnf';
        file_put_contents($path, $content."\n");

        return $path;
    }

    /**
     * O container com RC2, ou o motivo pelo qual este ambiente não o tem.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private static function buildLegacyPfx(): array
    {
        return self::buildUnreadablePfx('rc2', 'legado-rc2', []);
    }

    /**
     * O container com RC4, o mesmo `unsupported` e nenhum OID de RC2 nos bytes.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private static function buildOtherLegacyPfx(): array
    {
        return self::buildUnreadablePfx('rc4', 'legado-outro', ['-certpbe', 'rc4', '-keypbe', 'rc4']);
    }

    /**
     * Um container que este OpenSSL recusa por não trazer o provedor legado, ou o
     * motivo pelo qual ele não saiu. `$pbe` são os argumentos de cifragem das
     * bags: vazios, o `-legacy` já troca AES por RC2-40-CBC.
     *
     * O critério de "serviu" não é ter arquivo: é o `openssl_pkcs12_read` do
     * **produto** não conseguir abri-lo. A detecção lê a fila de erro depois de
     * uma falha, e um container que este OpenSSL abre não tem o que ler.
     *
     * @param  list<string>  $pbe
     * @return array{0: string|null, 1: string|null}
     */
    private static function buildUnreadablePfx(string $label, string $slug, array $pbe): array
    {
        $binary = self::opensslBinary();

        if ($binary === null) {
            return [null, 'o executável openssl não está no PATH'];
        }

        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pkcs12-'.$slug.'-'.bin2hex(random_bytes(6));

        if (! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            return [null, sprintf('não foi possível criar o diretório temporário em %s', sys_get_temp_dir())];
        }

        $certificate = $directory.DIRECTORY_SEPARATOR.'cert.pem';
        $key = $directory.DIRECTORY_SEPARATOR.'key.pem';
        $pfx = $directory.DIRECTORY_SEPARATOR.'legacy.pfx';

        try {
            if (! self::runOpenssl($binary, [
                'req', '-x509', '-newkey', 'rsa:1024', '-nodes',
                '-keyout', $key, '-out', $certificate,
                '-days', '1', '-subj', '/CN=Legado',
            ], $directory)) {
                return [null, 'o par autossinado de descarte não pôde ser gerado'];
            }

            if (! self::runOpenssl($binary, array_merge([
                'pkcs12', '-export', '-legacy',
                '-in', $certificate, '-inkey', $key,
                '-passout', 'pass:'.self::PASSWORD, '-out', $pfx,
            ], $pbe), $directory)) {
                return [null, sprintf('o openssl deste ambiente recusou o export legado com %s', $label)];
            }

            $bytes = file_get_contents($pfx);

            if ($bytes === false || $bytes === '') {
                return [null, 'o export produziu um arquivo vazio'];
            }

            if (@openssl_pkcs12_read($bytes, $parsed, self::PASSWORD)) {
                return [null, sprintf('o openssl deste ambiente abre o container com %s, então a leitura não falha e a detecção não tem o que ler', $label)];
            }

            // A leitura de conferência deixou o erro dela na fila desta thread, e
            // a fila é por thread. `inspect()` começa limpando a fila, e
            // `test_a_falha_de_outra_leitura_nao_decide_o_destino_desta` depende
            // disso — ela reenche a fila de propósito.
            while (openssl_error_string() !== false) {
                // Esvazia a fila deixada pela leitura de conferência.
            }

            return [$bytes, null];
        } finally {
            foreach ([$pfx, $key, $certificate] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }

            @rmdir($directory);
        }
    }

    private static function opensslBinary(): ?string
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $path) {
            $candidate = rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'openssl';

            if ($path !== '' && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $arguments
     */
    private static function runOpenssl(string $binary, array $arguments, string $workingDirectory): bool
    {
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $descriptors = [
            0 => ['file', $null, 'r'],
            1 => ['file', $null, 'a'],
            2 => ['file', $null, 'a'],
        ];

        // O comando vai como array: `proc_open` recebe cada argumento separadamente
        // e não passa por shell, o que mantém o caminho do diretório temporário
        // fora de qualquer quoting. A saída vai para o dispositivo nulo porque o
        // que interessa é o código de saída.
        $process = proc_open(array_merge([$binary], $arguments), $descriptors, $pipes, $workingDirectory);

        if (! is_resource($process)) {
            return false;
        }

        return proc_close($process) === 0;
    }
}
