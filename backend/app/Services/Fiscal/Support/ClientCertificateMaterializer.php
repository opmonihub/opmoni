<?php

namespace App\Services\Fiscal\Support;

use App\Models\ClientCertificate;
use App\Services\Fiscal\Exceptions\FiscalRequestNotSent;
use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class ClientCertificateMaterializer
{
    /**
     * O cURL aceita apenas caminho de arquivo para `cert`/`ssl_key`, então o
     * PKCS#12 é gravado num arquivo efêmero e apagado em `finally`. A senha
     * vive no escopo do método e some junto com ele.
     *
     * As três recusas são `FiscalRequestNotSent` e não `RuntimeException` porque
     * nenhuma delas chega a abrir uma conexão: a requisição não saiu, e quem
     * reconcilia precisa saber disso para não gastar a tentativa de uma
     * consulta que o fisco nunca viu. A de disco cheio é a mais cara de errar —
     * ela é de um servidor, não de um cliente, e atinge a carteira inteira ao
     * mesmo tempo.
     *
     * @template TReturn
     *
     * @param  Closure(string): TReturn  $callback
     * @return TReturn
     */
    public function withCertificate(ClientCertificate $certificate, Closure $callback): mixed
    {
        $bytes = $this->bytes($certificate);

        if ($bytes === null) {
            throw new FiscalRequestNotSent('Certificado do cliente não está disponível.');
        }

        $password = $certificate->certificatePassword();

        // `null` não é senha vazia: é certificado anterior a esta versão, que
        // precisa de novo upload. Condição nomeada, nunca um placeholder.
        if ($password === null) {
            throw new FiscalRequestNotSent('A senha do certificado do cliente não está armazenada.');
        }

        $disk = Storage::disk('local');
        $relative = 'fiscal-tmp/'.Str::uuid().'.pfx';
        $path = null;

        // A escrita fica *dentro* do `try`: `put()` pode devolver `false`
        // depois de o arquivo já estar no disco (`UnableToSetVisibility` é o
        // caso comum — `file_put_contents` OK, `chmod` falhando), e esse PFX
        // não pode sobreviver à chamada. O disco `local` é `throw => false` e
        // `report => false`, então a falha é silenciosa e ninguém mais limpa.
        try {
            $path = $disk->path($relative);
            $written = $disk->put($relative, $bytes) !== false;

            // Antes de olhar o resultado: se sobrou arquivo, ele não pode
            // ficar legível por mais tempo, mesmo que a escrita tenha falhado.
            @chmod($path, 0600);

            if (! $written) {
                throw new FiscalRequestNotSent('Não foi possível gravar o certificado no diretório temporário.');
            }

            return $callback($path);
        } finally {
            if ($path !== null) {
                @unlink($path);
            }

            $password = str_repeat("\0", strlen($password));
            $bytes = str_repeat("\0", strlen($bytes));
            unset($password, $bytes);
        }
    }

    /**
     * Os bytes do PKCS#12 e a senha em claro, dentro do escopo do callback.
     *
     * É o mesmo cofre e as mesmas três recusas de `withCertificate()` — as
     * quatro frases de `FiscalRequestNotSent` são as mesmas, porque a
     * requisição não sai pelos mesmos motivos —, mas sem escrever nada no
     * disco: quem precisa dos bytes é a assinatura XMLDSig do evento, que não é
     * uma opção `cert` do cURL.
     *
     * Bytes e senha são zerados no `finally` antes de voltar ao chamador: o
     * material vive o tempo do callback, e nem a stack nem a memória que ele
     * ocupava ficam com cópia legível.
     *
     * @template TReturn
     *
     * @param  Closure(string $bytes, string $password): TReturn  $callback
     * @return TReturn
     */
    public function withCertificateBytes(ClientCertificate $certificate, Closure $callback): mixed
    {
        $bytes = $this->bytes($certificate);

        if ($bytes === null) {
            throw new FiscalRequestNotSent('Certificado do cliente não está disponível.');
        }

        $password = $certificate->certificatePassword();

        if ($password === null) {
            throw new FiscalRequestNotSent('A senha do certificado do cliente não está armazenada.');
        }

        try {
            return $callback($bytes, $password);
        } finally {
            $password = str_repeat("\0", strlen($password));
            $bytes = str_repeat("\0", strlen($bytes));
            unset($password, $bytes);
        }
    }

    /**
     * O cofre guarda `Crypt::encryptString(base64_encode($conteudo))` na chave
     * `storage_path` do disco `certificates`.
     */
    private function bytes(ClientCertificate $certificate): ?string
    {
        $relative = $certificate->storage_path;

        if ($relative === null || $relative === '') {
            return null;
        }

        $disk = Storage::disk('certificates');

        if (! $disk->exists($relative)) {
            return null;
        }

        $stored = $disk->get($relative);

        if ($stored === null) {
            return null;
        }

        // O cofre grava `encrypt(base64($conteudo))`: a base64 fica sob a cifra,
        // então a ordem é decifrar e só então decodificar. Invertido, o
        // `base64_decode` estrito devolve `false` e o certificado some.
        try {
            $decrypted = Crypt::decryptString($stored);
        } catch (DecryptException) {
            // Material ilegível é a mesma condição de "não há certificado
            // utilizável": falha nomeada, nunca `DecryptException` cru.
            return null;
        }

        return base64_decode($decrypted, true) ?: null;
    }
}
