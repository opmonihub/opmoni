<?php

namespace Tests\Feature;

use App\Enums\SerproFailure;
use App\Models\SerproConnection;
use App\Services\SerproCertificateMaterializer;
use App\Services\SerproException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SerproCertificateMaterializerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_passes_a_readable_file_and_removes_it_afterwards(): void
    {
        $materializer = new SerproCertificateMaterializer;
        $connection = SerproConnection::factory()->create([
            'certificate_encrypted' => encrypt('conteudo-do-pfx'),
            'certificate_password_encrypted' => encrypt('senha'),
        ]);

        $seen = null;

        $materializer->withCertificate($connection, function (string $path) use (&$seen): void {
            $seen = $path;
            $this->assertFileExists($path);
        });

        $this->assertIsString($seen);
        $this->assertFileDoesNotExist($seen);
    }

    public function test_it_removes_the_file_even_when_the_callback_throws(): void
    {
        $materializer = new SerproCertificateMaterializer;
        $connection = SerproConnection::factory()->create([
            'certificate_encrypted' => encrypt('conteudo-do-pfx'),
            'certificate_password_encrypted' => encrypt('senha'),
        ]);

        $seen = null;

        try {
            $materializer->withCertificate($connection, function (string $path) use (&$seen): void {
                $seen = $path;

                throw new RuntimeException('falhou dentro do callback');
            });
        } catch (RuntimeException) {
            // esperado
        }

        $this->assertIsString($seen);
        $this->assertFileDoesNotExist($seen);
    }

    public function test_it_refuses_to_run_without_a_certificate(): void
    {
        $materializer = new SerproCertificateMaterializer;
        $connection = SerproConnection::factory()->create([
            'certificate_encrypted' => null,
            'certificate_password_encrypted' => null,
        ]);

        $this->expectException(SerproException::class);

        $materializer->withCertificate($connection, fn (string $path) => null);
    }

    public function test_it_raises_serpro_exception_when_write_fails(): void
    {
        $connection = SerproConnection::factory()->create([
            'certificate_encrypted' => encrypt('conteudo-do-pfx'),
            'certificate_password_encrypted' => encrypt('senha'),
        ]);

        $mockDisk = Mockery::mock(Filesystem::class);
        $mockDisk->shouldReceive('path')->andReturnUsing(fn (string $path = '') => $path === ''
            ? storage_path('app/private')
            : storage_path('app/private/serpro-tmp/inexistente.pfx'));
        $mockDisk->shouldReceive('put')->andReturn(false);
        Storage::shouldReceive('disk')->andReturn($mockDisk);

        $this->expectException(SerproException::class);
        $this->expectExceptionMessage('Não foi possível gravar o certificado no diretório temporário.');

        (new SerproCertificateMaterializer)->withCertificate($connection, fn (string $path) => null);
    }

    public function test_it_removes_a_file_left_behind_by_a_failed_write(): void
    {
        $connection = SerproConnection::factory()->create([
            'certificate_encrypted' => encrypt('conteudo-do-pfx'),
            'certificate_password_encrypted' => encrypt('senha'),
        ]);

        $real = Storage::disk('local');
        $leaked = null;

        // `put()` pode devolver `false` já com o arquivo no disco (o
        // `chmod` falha depois do `file_put_contents`). Sem o `finally`
        // cobrindo a escrita, esse PFX sobrevivia à chamada.
        $mockDisk = Mockery::mock(Filesystem::class);
        $mockDisk->shouldReceive('path')->with('')->andReturn($real->path(''));
        $mockDisk->shouldReceive('path')->andReturnUsing(fn (string $path) => $real->path($path));
        $mockDisk->shouldReceive('put')->andReturnUsing(function (string $path, string $contents) use ($real, &$leaked): bool {
            $real->put($path, $contents);
            $leaked = $real->path($path);
            $this->assertFileExists($leaked);

            return false;
        });

        Storage::shouldReceive('disk')->andReturn($mockDisk);

        try {
            (new SerproCertificateMaterializer)->withCertificate(
                $connection,
                fn (string $path) => $this->fail('O callback não deveria rodar sem arquivo gravado.'),
            );

            $this->fail('Uma gravação que falhou deveria ser SerproException.');
        } catch (SerproException $exception) {
            // Indeterminado: ninguém descobriu nada sobre a credencial, e a
            // pasta pode estar gravável na próxima hora. `DoNotRetry` aqui
            // diria "recadastre a credencial", que é o conserto errado para um
            // disco cheio.
            $this->assertSame(SerproFailure::Indeterminate, $exception->failure);
        }

        $this->assertIsString($leaked);
        $this->assertStringContainsString('serpro-tmp/', $leaked);
        $this->assertFileDoesNotExist($leaked);
    }

    public function test_it_uses_the_configured_temp_directory(): void
    {
        $altDir = storage_path('app/private/serpro-tmp-alt');
        config(['integra-contador.temp_dir' => $altDir]);

        $connection = SerproConnection::factory()->create([
            'certificate_encrypted' => encrypt('conteudo-do-pfx'),
            'certificate_password_encrypted' => encrypt('senha'),
        ]);

        $seen = null;

        (new SerproCertificateMaterializer)->withCertificate($connection, function (string $path) use (&$seen): void {
            $seen = $path;
            $this->assertFileExists($path);
        });

        $this->assertIsString($seen);
        $this->assertStringContainsString('serpro-tmp-alt', $seen);
        $this->assertFileDoesNotExist($seen);

        @rmdir($altDir);
    }
}
