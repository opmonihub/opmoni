<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Client;
use App\Models\ClientCertificate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @extends Factory<ClientCertificate>
 */
class ClientCertificateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'client_id' => Client::factory(),
            'subject' => fake()->name().' :'.fake()->numerify('##############'),
            'serial_number' => fake()->unique()->bothify('??##########'),
            'valid_from' => now()->subMonths(6),
            'valid_until' => now()->addMonths(6),
            'original_filename' => fake()->slug().'.pfx',
            'storage_path' => null,
            'sha256' => hash('sha256', fake()->unique()->uuid()),
            'password_encrypted' => null,
            'replaced_at' => null,
            'removed_at' => null,
        ];
    }

    /**
     * Certificado com senha e com o PKCS#12 correspondente gravado no disco
     * `certificates`, no mesmo formato do `ClientCertificateVault`, para que
     * quem consome os bytes decodifique exatamente como o cofre.
     */
    public function withPassword(string $password = 'senha'): static
    {
        return $this->state(['password_encrypted' => Crypt::encryptString($password)])
            ->afterMaking(function (ClientCertificate $certificate) use ($password): void {
                $contents = self::pkcs12($password);
                $path = sprintf(
                    '%d/%d/%s.enc',
                    (int) $certificate->account_id,
                    (int) $certificate->client_id,
                    (string) Str::uuid()
                );

                Storage::disk('certificates')->put($path, Crypt::encryptString(base64_encode($contents)));

                $certificate->storage_path = $path;
                $certificate->sha256 = hash('sha256', $contents);
            });
    }

    public function withoutPassword(): static
    {
        return $this->state(['password_encrypted' => null, 'storage_path' => null]);
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ClientCertificate $certificate): void {
            if ($certificate->client instanceof Client) {
                $certificate->account_id = $certificate->client->account_id;
            }
        })->afterCreating(function (ClientCertificate $certificate): void {
            if ($certificate->client instanceof Client && $certificate->account_id !== $certificate->client->account_id) {
                $certificate->account_id = $certificate->client->account_id;
                $certificate->saveQuietly();
            }
        });
    }

    /**
     * PKCS#12 descartável, gerado com a senha pedida. A senha jamais aparece na
     * exceção: ela é o segredo que o teste está exercitando.
     */
    private static function pkcs12(string $password): string
    {
        $config = file_exists('/etc/ssl/openssl.cnf') ? ['config' => '/etc/ssl/openssl.cnf'] : [];

        $key = openssl_pkey_new(array_merge(
            ['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA],
            $config
        ));
        $csr = $key === false
            ? false
            : openssl_csr_new(['CN' => 'Teste'], $key, array_merge(['digest_alg' => 'sha256'], $config));
        $certificate = $csr === false
            ? false
            : openssl_csr_sign($csr, null, $key, 365, array_merge(['digest_alg' => 'sha256'], $config));

        $contents = '';
        $exported = $certificate !== false && openssl_pkcs12_export($certificate, $contents, $key, $password);

        if (! $exported || $contents === '') {
            throw new \RuntimeException('Não foi possível gerar o certificado de teste.');
        }

        return $contents;
    }
}
