<?php

namespace Tests\Feature\Fiscal;

use App\Models\ClientCertificate;
use App\Services\Fiscal\Support\ClientCertificateMaterializer;
use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ClientCertificateMaterializerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // O materializador lê do cofre (`certificates`) e grava no efêmero
        // (`local`): sem os dois fakes o teste leria o disco real e passaria
        // pelo motivo errado.
        Storage::fake('certificates');
        Storage::fake('local');
    }

    public function test_callback_receives_readable_file_and_file_is_removed(): void
    {
        $certificate = ClientCertificate::factory()->withPassword()->create();
        $seen = null;

        $result = (new ClientCertificateMaterializer)->withCertificate(
            $certificate,
            function (string $path) use (&$seen, $certificate): string {
                $seen = $path;
                $this->assertFileExists($path);
                $this->assertSame(0600, fileperms($path) & 0777);

                // Prova de que os bytes gravados são o PKCS#12 do cofre e não
                // o texto cifrado: só abre com a senha do certificado.
                $parsed = [];
                $this->assertTrue(
                    openssl_pkcs12_read((string) file_get_contents($path), $parsed, (string) $certificate->certificatePassword())
                );
                $this->assertNotEmpty($parsed['cert'] ?? null);

                return 'resultado';
            },
        );

        $this->assertSame('resultado', $result);
        $this->assertIsString($seen);
        $this->assertFileDoesNotExist($seen);
    }

    public function test_file_is_removed_when_callback_throws(): void
    {
        $certificate = ClientCertificate::factory()->withPassword()->create();
        $seen = null;

        try {
            (new ClientCertificateMaterializer)->withCertificate(
                $certificate,
                function (string $path) use (&$seen): never {
                    $seen = $path;
                    $this->assertFileExists($path);

                    throw new RuntimeException('falhou dentro do callback');
                },
            );

            $this->fail('A exceção do callback deveria propagar.');
        } catch (RuntimeException $exception) {
            $this->assertSame('falhou dentro do callback', $exception->getMessage());
        }

        $this->assertIsString($seen);
        $this->assertFileDoesNotExist($seen);
    }

    public function test_two_calls_use_distinct_files(): void
    {
        $certificate = ClientCertificate::factory()->withPassword()->create();
        $paths = [];

        foreach (range(1, 2) as $ignored) {
            (new ClientCertificateMaterializer)->withCertificate(
                $certificate,
                function (string $path) use (&$paths): void {
                    // O arquivo da chamada anterior já foi destruído: prova de
                    // que as chamadas não compartilham material.
                    foreach ($paths as $previous) {
                        $this->assertFileDoesNotExist($previous);
                    }

                    $this->assertFileExists($path);
                    $paths[] = $path;
                },
            );
        }

        $this->assertCount(2, $paths);
        $this->assertNotSame($paths[0], $paths[1]);

        foreach ($paths as $path) {
            $this->assertFileDoesNotExist($path);
        }
    }

    public function test_named_failure_when_certificate_is_not_stored(): void
    {
        $certificate = ClientCertificate::factory()->withoutPassword()->create();

        $this->assertNull($certificate->storage_path);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Certificado do cliente não está disponível.');

        (new ClientCertificateMaterializer)->withCertificate($certificate, $this->callbackThatMustNotRun());
    }

    public function test_named_failure_when_certificate_file_vanished_from_the_vault(): void
    {
        $certificate = ClientCertificate::factory()->withPassword()->create();

        // O registro aponta para uma chave que não existe mais no disco: o
        // `get` estrito do disco launcharia, e o guarda transforma em falha nomeada.
        Storage::disk('certificates')->delete((string) $certificate->storage_path);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Certificado do cliente não está disponível.');

        (new ClientCertificateMaterializer)->withCertificate($certificate, $this->callbackThatMustNotRun());
    }

    public function test_named_failure_when_stored_certificate_cannot_be_decrypted(): void
    {
        $certificate = ClientCertificate::factory()->withPassword()->create();

        // Material ilegível: lixo no lugar do texto cifrado. A senha segue
        // intacta, então a falha é atribuível aos bytes, não à senha.
        Storage::disk('certificates')->put((string) $certificate->storage_path, 'conteudo-que-nao-e-payload-cifrado');
        $this->assertNotNull($certificate->certificatePassword());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Certificado do cliente não está disponível.');

        (new ClientCertificateMaterializer)->withCertificate($certificate, $this->callbackThatMustNotRun());
    }

    public function test_named_failure_when_password_is_not_stored(): void
    {
        $certificate = ClientCertificate::factory()->withPassword('segredo-unico-9f2b')->withoutPassword()->create();

        // Estado real de um certificado anterior a esta versão: o arquivo está
        // no cofre, mas a senha não existe — exige novo upload, não string vazia.
        $this->assertNotNull($certificate->storage_path);
        $this->assertTrue(Storage::disk('certificates')->exists($certificate->storage_path));
        $this->assertNull($certificate->certificatePassword());

        try {
            (new ClientCertificateMaterializer)->withCertificate($certificate, $this->callbackThatMustNotRun());

            $this->fail('Um certificado sem senha armazenada deveria falhar.');
        } catch (RuntimeException $exception) {
            $this->assertSame('A senha do certificado do cliente não está armazenada.', $exception->getMessage());
            $this->assertStringNotContainsString('segredo-unico-9f2b', $exception->getMessage());
        }
    }

    public function test_named_failure_when_stored_password_cannot_be_decrypted(): void
    {
        $certificate = ClientCertificate::factory()->withPassword('senha')->create();

        // Senha presente e indecifrável (APP_KEY rotacionado, coluna truncada):
        // mesmo desfecho de "sem senha", nunca um DecryptException cru.
        $certificate->forceFill(['password_encrypted' => 'coluna-que-nao-e-payload-cifrado'])->saveQuietly();

        $this->assertNotNull($certificate->storage_path);
        $this->assertNull($certificate->certificatePassword());

        try {
            (new ClientCertificateMaterializer)->withCertificate($certificate, $this->callbackThatMustNotRun());

            $this->fail('Uma senha indecifrável deveria ser tratada como ausência de senha.');
        } catch (RuntimeException $exception) {
            $this->assertSame('A senha do certificado do cliente não está armazenada.', $exception->getMessage());
        }
    }

    public function test_leftover_file_is_removed_when_the_write_reports_failure(): void
    {
        $certificate = ClientCertificate::factory()->withPassword()->create();
        $leaked = null;

        $cofre = Storage::disk('certificates');
        $real = Storage::disk('local');

        // Modela `UnableToSetVisibility`: `file_put_contents` conclui e o
        // arquivo fica em disco, o `chmod` falha e `put()` devolve `false`.
        // É o vazamento que o `finally` fora do `put()` não cobria.
        $local = Mockery::mock(Filesystem::class);
        $local->shouldReceive('path')->andReturnUsing(fn (string $path) => $real->path($path));
        $local->shouldReceive('put')->andReturnUsing(function (string $path, string $contents) use ($real, &$leaked): bool {
            $real->put($path, $contents);
            $leaked = $real->path($path);
            $this->assertFileExists($leaked);

            return false;
        });

        Storage::shouldReceive('disk')->andReturnUsing(fn (?string $name = null) => $name === 'local' ? $local : $cofre);

        try {
            (new ClientCertificateMaterializer)->withCertificate($certificate, $this->callbackThatMustNotRun());

            $this->fail('Uma gravação que falhou deveria ser erro nomeado.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Não foi possível gravar o certificado no diretório temporário.', $exception->getMessage());
        }

        // O caminho exato que vazou, não o disco inteiro.
        $this->assertIsString($leaked);
        $this->assertStringContainsString('fiscal-tmp/', $leaked);
        $this->assertFileDoesNotExist($leaked);
    }

    /**
     * Nenhuma falha nomeada pode chegar a materializar um arquivo: um callback
     * chamado aqui significaria que o material temporário foi criado.
     */
    private function callbackThatMustNotRun(): Closure
    {
        return function (string $path): never {
            $this->fail("O callback não deveria rodar sem material utilizável ({$path}).");
        };
    }
}
