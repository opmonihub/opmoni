<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\AccountCertificate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Crypt;

/**
 * @extends Factory<AccountCertificate>
 */
class AccountCertificateFactory extends Factory
{
    /**
     * A linha corrente de um Account: os dois marcadores vazios e o conteúdo
     * cifrado no lugar, no mesmo formato que `AccountCertificateVault` grava.
     *
     * O `certificate_encrypted` default é cifrado mesmo, e com a base64 sob a
     * cifra como manda a convenção do repositório — o que faz o `fill()` desta
     * linha devolver bytes quando alguém os pede. **Os bytes não são um PKCS#12
     * de verdade**: são um texto de descarte, porque um PFX de teste é caro de
     * gerar e nada aqui assina nada. O teste que precisa de um e-CNPJ de verdade
     * sobe um pelo endpoint, e é esse que exercita a criptografia do arquivo
     * inteiro.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'document' => '12345678000195',
            'subject' => '/CN=Escritorio Contabil de Descarte:12345678000195/C=BR/O=ICP-Brasil',
            'serial_number' => '12345678000195',
            'valid_from' => now()->subMonths(6),
            'valid_until' => now()->addMonths(6),
            'original_filename' => 'escritorio.p12',
            'sha256' => hash('sha256', fake()->unique()->uuid()),
            'certificate_encrypted' => Crypt::encryptString(base64_encode('pfx-de-descarte')),
            'password_encrypted' => Crypt::encryptString('senha-de-descarte'),
            'replaced_at' => null,
            'removed_at' => null,
        ];
    }

    /**
     * Uma linha fora de vigência, como fica depois de uma troca ou de uma
     * remoção: os metadados continuam, e as duas colunas cifradas não.
     */
    public function removed(): static
    {
        return $this->state([
            'certificate_encrypted' => null,
            'password_encrypted' => null,
            'removed_at' => now(),
        ]);
    }

    public function replaced(): static
    {
        return $this->state([
            'certificate_encrypted' => null,
            'password_encrypted' => null,
            'replaced_at' => now(),
        ]);
    }
}
