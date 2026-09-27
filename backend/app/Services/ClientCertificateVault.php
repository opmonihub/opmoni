<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientCertificate;
use App\Tenant\CurrentTenant;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ClientCertificateVault
{
    public function replace(Client $client, UploadedFile $file, string $password): ClientCertificate
    {
        $contents = $file->get();
        $parsed = [];
        $ciphertext = null;
        $path = null;
        $committed = false;

        try {
            if (! openssl_pkcs12_read($contents, $parsed, $password) || ! isset($parsed['cert'])) {
                throw ValidationException::withMessages(['password' => 'Não foi possível abrir o certificado com a senha informada.']);
            }

            $metadata = openssl_x509_parse($parsed['cert']);

            if (! is_array($metadata) || ! isset($metadata['validFrom_time_t'], $metadata['validTo_time_t'])) {
                throw ValidationException::withMessages(['certificate' => 'O certificado não contém metadados válidos.']);
            }

            $path = sprintf('%d/%d/%s.enc', $client->account_id, $client->getKey(), (string) Str::uuid());
            $ciphertext = Crypt::encryptString(base64_encode($contents));
            Storage::disk('certificates')->put($path, $ciphertext);

            $attributes = [
                'subject' => $this->subject($metadata),
                'serial_number' => $this->serial($metadata),
                'valid_from' => Carbon::createFromTimestamp((int) $metadata['validFrom_time_t']),
                'valid_until' => Carbon::createFromTimestamp((int) $metadata['validTo_time_t']),
                'original_filename' => $file->getClientOriginalName(),
                'storage_path' => $path,
                'sha256' => hash('sha256', $contents),
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
            $parsed = [];
            $ciphertext = null;
            unset($password, $contents, $parsed, $ciphertext);
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
     * @param  array<string, mixed>  $metadata
     */
    private function subject(array $metadata): string
    {
        if (is_string($metadata['name'] ?? null) && $metadata['name'] !== '') {
            return $metadata['name'];
        }

        $subject = $metadata['subject'] ?? null;

        if (is_array($subject)) {
            $parts = [];

            foreach ($subject as $key => $value) {
                $parts[] = is_array($value) ? "{$key}=".implode(',', $value) : "{$key}={$value}";
            }

            if ($parts !== []) {
                return '/'.implode('/', $parts);
            }
        }

        return 'desconhecido';
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function serial(array $metadata): string
    {
        foreach (['serialNumber', 'serialNumberHex'] as $key) {
            if (is_string($metadata[$key] ?? null) && $metadata[$key] !== '') {
                return $metadata[$key];
            }
        }

        return 'desconhecido';
    }
}
