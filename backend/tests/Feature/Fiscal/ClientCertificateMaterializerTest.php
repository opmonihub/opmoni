<?php

namespace Tests\Feature\Fiscal;

use App\Models\ClientCertificate;
use App\Services\Fiscal\Support\ClientCertificateMaterializer;
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
        $this->assertSame([], Storage::disk('local')->allFiles());
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
        $this->assertSame([], Storage::disk('local')->allFiles());
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
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_named_failure_when_certificate_is_not_stored(): void
    {
        $certificate = ClientCertificate::factory()->withoutPassword()->create();

        $this->assertNull($certificate->storage_path);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Certificado do cliente não está disponível.');

        (new ClientCertificateMaterializer)->withCertificate($certificate, fn (string $path) => null);
    }

    public function test_named_failure_when_certificate_file_vanished_from_the_vault(): void
    {
        $certificate = ClientCertificate::factory()->withPassword()->create();

        // O registro aponta para uma chave que não existe mais no disco: o
        // `get` estrito do disco launcharia, e o guarda transforma em falha nomeada.
        Storage::disk('certificates')->delete((string) $certificate->storage_path);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Certificado do cliente não está disponível.');

        (new ClientCertificateMaterializer)->withCertificate($certificate, fn (string $path) => null);
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
            (new ClientCertificateMaterializer)->withCertificate($certificate, fn (string $path) => null);

            $this->fail('Um certificado sem senha armazenada deveria falhar.');
        } catch (RuntimeException $exception) {
            $this->assertSame('A senha do certificado do cliente não está armazenada.', $exception->getMessage());
            $this->assertStringNotContainsString('segredo-unico-9f2b', $exception->getMessage());
        }

        // A falha é anterior à escrita: nenhum material temporário é criado.
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_named_failure_when_temporary_file_cannot_be_written(): void
    {
        $certificate = ClientCertificate::factory()->withPassword()->create();

        $cofre = Storage::disk('certificates');
        $local = Mockery::mock(Filesystem::class);
        $local->shouldReceive('put')->andReturn(false);

        Storage::shouldReceive('disk')->andReturnUsing(
            fn (?string $name = null) => $name === 'local' ? $local : $cofre
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Não foi possível gravar o certificado no diretório temporário.');

        (new ClientCertificateMaterializer)->withCertificate($certificate, fn (string $path) => null);
    }
}
