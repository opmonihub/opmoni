<?php

namespace Tests\Unit;

use App\Services\CertificatePkcs12;
use App\Services\LegacyPkcs12Ciphertext;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
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
 * **Os PFX legados são gerados em tempo de execução e não são versionados.**
 * `*.pfx` e `*.p12` estão no `.gitignore` da raiz, e a regra é do arquivo
 * inteiro: PFX é o formato de procuração eletrônica — chave junto com
 * certificado — e um arquivo sintético não deixa de ser o formato que não se
 * versiona. São dois containers, pelo mesmo motivo: um com RC2, que é o que a
 * ICP-Brasil emitiu, e um com RC4, que dá o mesmo `unsupported` sem nenhum OID de
 * RC2 nos bytes. O gerador de fixture é **duplicado** de
 * `ClientCertificateVaultLegacyPfxTest` em vez de extraído para um trait
 * compartilhado: aquele teste é a prova de que a extração não mudou o
 * comportamento do cofre de cliente, e mexer no arquivo que faz essa prova
 * invalidaria a própria prova. O preço é uma duplicata intencional de um gerador
 * de descarte.
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

    private static ?string $singleRdnConfig = null;

    private static ?string $singleRdnDirectory = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        [self::$legacyPfx, self::$legacyUnavailable] = self::buildLegacyPfx();
        [self::$otherLegacyPfx, self::$otherLegacyUnavailable] = self::buildOtherLegacyPfx();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$singleRdnDirectory !== null) {
            @unlink(self::$singleRdnConfig ?? '');
            @rmdir(self::$singleRdnDirectory);
        }

        self::$singleRdnConfig = null;
        self::$singleRdnDirectory = null;

        parent::tearDownAfterClass();
    }

    public function test_pfx_com_a_senha_certa_devolve_o_contrato_declarado(): void
    {
        $bytes = $this->pfx(['CN' => 'Escritorio Contabil Andre Siqueira'], 30);
        $before = Carbon::now()->subMinute();

        $inspected = (new CertificatePkcs12)->inspect($bytes, self::PASSWORD);

        // A chave privada e o certificado saem do mesmo `openssl_pkcs12_read` que
        // os metadados: quem precisa assinar usa estes dois, quem precisa do
        // histórico usa os outros. Um contrato sem a chave seria um contrato que
        // obriga a reabrir o arquivo para assinar.
        $this->assertStringContainsString('BEGIN CERTIFICATE', $inspected['cert']);
        $this->assertStringContainsString('PRIVATE KEY', $inspected['pkey']);
        $this->assertNotSame('', $inspected['serial']);
        $this->assertNotSame('desconhecido', $inspected['serial']);
        $this->assertSame(hash('sha256', $bytes), $inspected['sha256']);

        $this->assertTrue($inspected['valid_from']->betweenIncluded($before, Carbon::now()->addMinute()));
        $this->assertTrue($inspected['valid_until']->greaterThan($inspected['valid_from']));
        $this->assertEqualsWithDelta(30, abs($inspected['valid_until']->diffInDays($inspected['valid_from'])), 1);
    }

    public function test_subject_com_varios_campos_vem_do_subject_completo(): void
    {
        // Um e-CNPJ real tem subject com vários RDN — CN com razão social,
        // `serialNumber` com o CNPJ, país, ICP-Brasil — e é justamente nesses
        // casos que o OpenSSL deixa o `name` de uma linha só de fora. O que o
        // cofre grava em `subject` é o que o produto já gravava; este teste fixa
        // a forma em vez de deixar a remontagem só no código.
        $bytes = $this->pfx(['CN' => 'Escritorio Contabil Andre Siqueira'], 30);

        $subject = (new CertificatePkcs12)->inspect($bytes, self::PASSWORD)['subject'];

        $this->assertStringStartsWith('/CN=', $subject);
        $this->assertStringContainsString('CN=Escritorio Contabil Andre Siqueira', $subject);
    }

    public function test_subject_de_um_campo_so_usa_o_name_do_openssl(): void
    {
        // O outro ramo: com um único campo no subject, o OpenSSL preenche `name` e
        // não há o que remontar. Os dois ramos são documentados; deixar um deles
        // sem teste é deixar a escolha do `name` para quem lê o código em vez de
        // para quem vê a falha.
        $bytes = $this->pfx(['CN' => 'Escritorio Contabil Andre Siqueira'], 30, self::singleRdnConfig());

        $subject = (new CertificatePkcs12)->inspect($bytes, self::PASSWORD)['subject'];

        $this->assertSame('/CN=Escritorio Contabil Andre Siqueira', $subject);
    }

    public function test_o_contrato_nao_traz_a_senha_nem_a_que_fora_do_contrato(): void
    {
        $bytes = $this->pfx(['CN' => 'Escritorio Contabil Andre Siqueira'], 30);

        $inspected = (new CertificatePkcs12)->inspect($bytes, self::PASSWORD);

        // A senha entra no PFX e o PFX sai daqui: quem chama precisa de material
        // de assinatura, não de volta a senha que o protege. O conjunto de chaves
        // é o contrato inteiro, para que acrescentar um campo vaze uma coisa
        // nova seja uma falha de teste e não uma leitura de tela.
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

        $this->fail('A leitura deveria ter sido recusada.');
    }

    /**
     * Um PKCS#12 descartável, gerado agora. Nenhum fixture de PFX é versionado.
     *
     * @param  array<string, string>  $subject
     */
    private function pfx(array $subject, int $days, ?string $config = null): string
    {
        $config ??= file_exists('/etc/ssl/openssl.cnf') ? '/etc/ssl/openssl.cnf' : null;
        $options = $config === null ? [] : ['config' => $config];

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
     * Um `openssl.cnf` de descarte cujo `distinguished_name` tem um campo só, para
     * o certificado sair com subject de um RDN. O do sistema serve para o outro
     * ramo — ele acrescenta país, estado e organização ao CN.
     */
    private static function singleRdnConfig(): string
    {
        if (self::$singleRdnConfig !== null) {
            return self::$singleRdnConfig;
        }

        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pkcs12-dn-'.bin2hex(random_bytes(6));

        if (! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            $this->markTestSkipped(sprintf('Não foi possível criar o diretório temporário em %s', sys_get_temp_dir()));
        }

        $path = $directory.DIRECTORY_SEPARATOR.'openssl.cnf';
        file_put_contents($path, "[req]\ndistinguished_name = dn\nprompt = no\n\n[dn]\nCN = descarte\n");

        self::$singleRdnDirectory = $directory;

        return self::$singleRdnConfig = $path;
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
     **produto** não conseguir abri-lo. A detecção lê a fila de erro depois de
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
