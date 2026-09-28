<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientCertificate;
use App\Tenant\CurrentTenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ClientCertificateVault
{
    /**
     * O padrão no parâmetro existe por causa de um teste: `new ClientCertificateVault`
     * sem argumento é a forma com que `ClientCertificateVaultLegacyPfxTest` — o
     * teste que prova que esta extração não mudou nada — monta o cofre. A unidade
     * de leitura não tem dependência nenhuma, então o padrão não esconde
     * nenhuma: ele só mantém a construção sem argumento possível.
     */
    public function __construct(private CertificatePkcs12 $pkcs12 = new CertificatePkcs12) {}

    public function replace(Client $client, UploadedFile $file, string $password): ClientCertificate
    {
        $contents = $file->get();
        $ciphertext = null;
        $path = null;
        $committed = false;

        try {
            try {
                $inspected = $this->pkcs12->inspect($contents, $password);
            } catch (LegacyPkcs12Ciphertext $exception) {
                throw $this->namingTheClient($exception, $client);
            }

            $path = sprintf('%d/%d/%s.enc', $client->account_id, $client->getKey(), (string) Str::uuid());
            $ciphertext = Crypt::encryptString(base64_encode($contents));
            Storage::disk('certificates')->put($path, $ciphertext);

            $attributes = [
                'subject' => $inspected['subject'],
                'serial_number' => $inspected['serial'],
                'valid_from' => $inspected['valid_from'],
                'valid_until' => $inspected['valid_until'],
                'original_filename' => $file->getClientOriginalName(),
                'storage_path' => $path,
                'sha256' => $inspected['sha256'],
                'password_encrypted' => Crypt::encryptString($password),
            ];

            $oldPath = null;

            $certificate = DB::transaction(function () use ($client, $attributes, &$oldPath): ClientCertificate {
                $locked = Client::whereKey($client->getKey())->lockForUpdate()->firstOrFail();

                $current = ClientCertificate::where('client_id', $locked->getKey())
                    ->whereNull('replaced_at')
                    ->whereNull('removed_at')
                    ->lockForUpdate()
                    ->latest('id')
                    ->first();

                if ($current !== null) {
                    $oldPath = $current->storage_path;
                    $current->forceFill([
                        'replaced_at' => now(),
                        'storage_path' => null,
                        'password_encrypted' => null,
                    ])->save();
                }

                resolve(CurrentTenant::class)->accountId ??= $locked->account_id;

                return ClientCertificate::create(array_merge($attributes, [
                    'client_id' => $locked->getKey(),
                ]));
            });

            $committed = true;

            if (is_string($oldPath) && $oldPath !== '') {
                Storage::disk('certificates')->delete($oldPath);
            }

            return $certificate;
        } catch (\Throwable $exception) {
            if (! $committed && is_string($path) && $path !== '') {
                try {
                    Storage::disk('certificates')->delete($path);
                } catch (\Throwable) {
                    // Best effort: cleanup must never mask the original failure.
                }
            }

            throw $exception;
        } finally {
            $password = '';
            $contents = '';
            $ciphertext = null;
            unset($password, $contents, $ciphertext);
        }
    }

    public function remove(Client $client): void
    {
        $path = DB::transaction(function () use ($client): ?string {
            $locked = Client::whereKey($client->getKey())->lockForUpdate()->firstOrFail();

            $current = ClientCertificate::where('client_id', $locked->getKey())
                ->whereNull('replaced_at')
                ->whereNull('removed_at')
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if ($current === null) {
                return null;
            }

            $path = $current->storage_path;
            $current->forceFill([
                'removed_at' => now(),
                'storage_path' => null,
                'password_encrypted' => null,
            ])->save();

            return $path;
        });

        if (is_string($path) && $path !== '') {
            Storage::disk('certificates')->delete($path);
        }
    }

    /**
     * O erro de RC2 da leitura compartilhada fala do certificado, e quem sabe de
     * quem é o certificado é o cofre — a leitura não conhece cliente nem
     * escritório, e é por isso que ela devolve uma `LegacyPkcs12Ciphertext` em
     * vez de uma `ValidationException` qualquer.
     *
     * A troca é **só do sujeito**: o prefixo "O certificado" vira "O certificado
     * do cliente X" e o resto da frase é o mesmo caractere a caractere que era
     * antes da extração. Cortar em `strlen()` é seguro porque o prefixo são treze
     * bytes ASCII e `substr` conta bytes — um corte no meio de um caractere de
     * um byte só produziria lixo, não texto.
     *
     * RC2 é a única mensagem que nomeia alguém porque é a única em que o
     * operador precisa saber de qual cliente é o arquivo para refazer o export
     * certo.
     */
    private function namingTheClient(LegacyPkcs12Ciphertext $exception, Client $client): ValidationException
    {
        $sentence = $exception->errors()['certificate'][0] ?? '';
        $prefix = strlen('O certificado');

        return ValidationException::withMessages([
            'certificate' => sprintf('O certificado do cliente %s', $client->name).substr($sentence, $prefix),
        ]);
    }
}
