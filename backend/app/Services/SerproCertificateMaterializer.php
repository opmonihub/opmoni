<?php

namespace App\Services;

use App\Enums\SerproFailure;
use App\Models\SerproConnection;
use Closure;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class SerproCertificateMaterializer
{
    /**
     * O Guzzle aceita apenas caminho de arquivo para `cert`/`ssl_key`, então o
     * PKCS#12 é gravado num arquivo efêmero e apagado em `finally`. A senha
     * vive no escopo do método e some junto com ele.
     *
     * @template TReturn
     *
     * @param  Closure(string): TReturn  $callback
     * @return TReturn
     */
    public function withCertificate(SerproConnection $connection, Closure $callback): mixed
    {
        $bytes = $connection->certificateBytes();

        if ($bytes === null) {
            throw new SerproException(
                'Certificado do contratante não configurado.',
                SerproFailure::DoNotRetry,
                0,
            );
        }

        $password = $connection->certificatePassword() ?? '';

        $diskRoot = rtrim(Storage::disk('local')->path(''), '/');
        $tempDir = rtrim((string) config('integra-contador.temp_dir'), '/');
        $relativeDir = ltrim(str_replace($diskRoot, '', $tempDir), '/');
        $relative = $relativeDir.'/'.Str::uuid().'.pfx';
        $path = null;

        // A escrita fica dentro do `try` para que o `finally` também cubra a
        // falha da própria gravação: `put()` pode devolver `false` já com o
        // arquivo no disco, e o disco `local` é `report => false`, então esse
        // PFX ficaria para trás em silêncio.
        try {
            $path = Storage::disk('local')->path($relative);
            $written = Storage::disk('local')->put($relative, $bytes) !== false;

            @chmod($path, 0600);

            if (! $written) {
                throw new SerproException(
                    'Não foi possível gravar o certificado no diretório temporário.',
                    SerproFailure::DoNotRetry,
                    0,
                );
            }

            return $callback($path);
        } finally {
            if ($path !== null) {
                @unlink($path);
            }
            $password = str_repeat("\0", strlen($password));
            unset($password);
        }
    }
}
