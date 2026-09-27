<?php

namespace Tests\Unit;

use App\Models\Account;
use App\Models\Client;
use App\Services\ClientCertificateVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * O PKCS#12 que o cliente exportou com `-legacy`: chaves RC2 que o OpenSSL 3 não
 * traz. É o arquivo que um certificado real de verdade é, e a detecção existe
 * porque `openssl_pkcs12_read` não diz qual arquivo não abriu.
 *
 * **O fixture é gerado em tempo de execução e não é versionado.** `*.pfx` está no
 * `.gitignore` da raiz e a regra é do arquivo inteiro, não do caso: PFX é o
 * formato de procuração eletrônica — chave junto com certificado — e um arquivo
 * sintético não deixa de ser o formato que não se versiona. "Mas este aqui é de
 * teste" é precisamente a exceção que a regra existe para não admitir. O caminho
 * honesto do repositório para isto é o inverso do `ca-bundle.crt`: deploy
 * precisa do arquivo no repositório, teste precisa que o arquivo seja
 * reproduzível.
 *
 * Para refazer à mão, com o OpenSSL 3 do ambiente de teste:
 *
 *     openssl req -x509 -newkey rsa:1024 -nodes -keyout key.pem \
 *         -out cert.pem -days 1 -subj '/CN=Legado RC2'
 *     openssl pkcs12 -export -legacy -in cert.pem -inkey key.pem \
 *         -passout pass:secret -out legacy-rc2.pfx
 *
 * `-legacy` é o que troca os algarismos de container do PKCS#12 — AES — pelos
 * legados, RC2-40-CBC. Sem ele o `openssl_pkcs12_read` do PHP abre o arquivo e o
 * caminho de detecção não acontece. `ClientCertificateFactory` e
 * `openssl_pkcs12_export` em PHP não servem aqui: em OpenSSL 3 eles produzem o
 * container moderno, e a factory é justamente o que os outros testes usam.
 */
class ClientCertificateVaultLegacyPfxTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'secret';

    /**
     * Os bytes do PFX legado, gerados uma vez por classe, e o motivo pelo qual
     * não foram. Nulo é "este ambiente não reproduz o caso", e o teste conta isso
     * em vez de fingir que exercitou.
     */
    private static ?string $legacyPfx = null;

    private static ?string $unavailable = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        [$pfx, $reason] = self::buildLegacyPfx();

        self::$legacyPfx = $pfx;
        self::$unavailable = $reason;
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('certificates');
    }

    public function test_legacy_rc2_pfx_failure_names_the_client_without_leaking_secret_material(): void
    {
        if (self::$legacyPfx === null) {
            // O que não foi exercitado está na frase, porque um teste que pulou
            // sem dizer o quê é contabilidade de cobertura honesta só se o motivo
            // estiver escrito. Um hard failure aqui quebraria todo ambiente sem
            // provedor legado, e a ausência do provedor não é defeito dele.
            $this->markTestSkipped(sprintf(
                'O caminho de tradução do PFX com RC2 não foi exercitado: %s. Sem um OpenSSL que produza e não abra um PKCS#12 legado, o `openssl_pkcs12_read` do produto não falha e a detecção não tem o que ler. Comando para refazer o fixture: docblock desta classe.',
                self::$unavailable ?? 'motivo não determinado',
            ));
        }

        $account = Account::factory()->create();
        $client = Client::factory()->individual()->create([
            'account_id' => $account->getKey(),
            'name' => 'Cliente RC2 Seguro',
        ]);

        $bytes = (string) self::$legacyPfx;
        $file = UploadedFile::fake()->createWithContent('legacy-rc2.pfx', $bytes);

        try {
            (new ClientCertificateVault)->replace($client, $file, self::PASSWORD);
            $this->fail('Um PFX com RC2 não deveria ser aceito pelo cofre.');
        } catch (ValidationException $exception) {
            $message = $exception->errors()['certificate'][0] ?? '';

            // O cofre não sabe qual arquivo não abriu — a exceção do OpenSSL não
            // diz — e a mensagem de validação não pode devolver a exceção crua,
            // que traria a senha e os bytes. O que ela pode é dizer que é RC2 e
            // para quem é, que é o que o cliente precisa para refazer o export.
            $this->assertStringContainsString('Cliente RC2 Seguro', $message);
            $this->assertStringContainsString('criptografia legada RC2', $message);
            $this->assertStringNotContainsString(self::PASSWORD, $message);
            $this->assertStringNotContainsString($bytes, $message);
        }

        $this->assertDatabaseCount('client_certificates', 0);
        $this->assertSame([], Storage::disk('certificates')->allFiles());
    }

    /**
     * O PFX legado, ou o motivo pelo qual este ambiente não o tem.
     *
     * O critério de "serviu" não é só ter arquivo: é o `openssl_pkcs12_read` do
     * **produto** não conseguir abri-lo. A detecção que se quer testar lê a fila de
     * erro do OpenSSL depois de uma falha de leitura, então um PFX que este
     * OpenSSL abre não exercita nada — e pular com o motivo escrito é a resposta
     * honesta, onde um teste que passa sem passar por nada seria mentira.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private static function buildLegacyPfx(): array
    {
        $binary = self::opensslBinary();

        if ($binary === null) {
            return [null, 'o executável openssl não está no PATH'];
        }

        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fiscal-rc2-'.bin2hex(random_bytes(6));

        if (! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            return [null, sprintf('não foi possível criar o diretório temporário em %s', sys_get_temp_dir())];
        }

        $certificate = $directory.DIRECTORY_SEPARATOR.'cert.pem';
        $key = $directory.DIRECTORY_SEPARATOR.'key.pem';
        $pfx = $directory.DIRECTORY_SEPARATOR.'legacy-rc2.pfx';

        try {
            $selfSigned = self::runOpenssl($binary, [
                'req', '-x509', '-newkey', 'rsa:1024', '-nodes',
                '-keyout', $key, '-out', $certificate,
                '-days', '1', '-subj', '/CN=Legado RC2',
            ], $directory);

            if (! $selfSigned) {
                return [null, 'o par autossinado de descarte não pôde ser gerado'];
            }

            $exported = self::runOpenssl($binary, [
                'pkcs12', '-export', '-legacy',
                '-in', $certificate, '-inkey', $key,
                '-passout', 'pass:'.self::PASSWORD, '-out', $pfx,
            ], $directory);

            if (! $exported) {
                return [null, 'o openssl deste ambiente não tem o provedor legado e recusou `-export -legacy`'];
            }

            $bytes = file_get_contents($pfx);

            if ($bytes === false || $bytes === '') {
                return [null, 'o export produziu um arquivo vazio'];
            }

            if (@openssl_pkcs12_read($bytes, $parsed, self::PASSWORD)) {
                return [null, 'o openssl deste ambiente abre o container legado, então a leitura não falha e a detecção não tem o que ler'];
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
        $paths = explode(PATH_SEPARATOR, (string) getenv('PATH'));

        foreach ($paths as $path) {
            $candidate = rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'openssl';

            if ($path !== '' && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * O comando como array, nunca como string: `proc_open` recebe cada argumento
     * separadamente e não passa por shell, o que mantém o caminho do diretório
     * temporário fora de qualquer quoting.
     *
     * A saída vai para o dispositivo nulo em vez de um pipe: o que interessa aqui
     * é o código de saída, e um pipe que ninguém lê pode travar o processo no
     * momento em que o buffer enche.
     *
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

        $process = proc_open(array_merge([$binary], $arguments), $descriptors, $pipes, $workingDirectory);

        if (! is_resource($process)) {
            return false;
        }

        return proc_close($process) === 0;
    }
}
