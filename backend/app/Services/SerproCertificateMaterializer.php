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
     * PKCS#12 é gravado num arquivo efêmero e apagado em `finally`.
     *
     * Esta classe não vê a senha, e é de propósito: quem chama o `callback` monta
     * as próprias opções de `curl` e lê a senha do modelo quando precisa dela.
     * Ela lia aqui para sobrescrever a cópia no fim do método, e a sobrescrita era
     * teatro — uma variável local zerada não apaga o segredo de lugar nenhum, nem
     * do PFX cifrado, nem do disco efêmero, nem do processo. Fingir que apaga é
     * pior do que não dizer nada, porque deixa de dizer a verdade sobre onde o
     * segredo está, e o mesmo vale nas outras duas classes que leem este
     * material, `SerproCertificateIdentity` e `SerproConnectionManager`.
     *
     * O `unlink` do PFX é o oposto: ele apaga de verdade, e é por isso que ele
     * continua.
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
            // Ausência de certificado é um fato de configuração, e quem responde
            // por ele é quem chamou: aqui só há o nome da falha.
            throw new SerproException(
                'Certificado do contratante não configurado.',
                SerproFailure::DoNotRetry,
                0,
            );
        }

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
                // Falha local, e nada foi enviado: `DoNotRetry` diria
                // "recadastre a credencial" e `Indeterminate` diria "pode ter
                // sido aplicado e ninguém sabe", e as duas coisas são falsas
                // para uma pasta que não aceitou gravação.
                throw new SerproException(
                    'Não foi possível gravar o certificado no diretório temporário.',
                    SerproFailure::NotSent,
                    0,
                );
            }

            return $callback($path);
        } finally {
            if ($path !== null) {
                @unlink($path);
            }
        }
    }
}
