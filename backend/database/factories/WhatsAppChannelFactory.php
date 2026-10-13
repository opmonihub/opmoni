<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\WhatsAppChannel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsAppChannel>
 */
class WhatsAppChannelFactory extends Factory
{
    protected $model = WhatsAppChannel::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'wzap_instance_id' => 'inst_'.fake()->unique()->lexify('????????????'),
            'status' => WhatsAppChannel::STATUS_DISCONNECTED,
        ];
    }

    /**
     * Canal pareado e pronto para Disparo, com uma apikey cifrada de teste.
     *
     * A gravação passa por `storeInstanceApiKey()` — a única porta de
     * escrita do segredo — então a cifra só existe quando a linha é
     * persistida: `make()` deixa a coluna vazia, como um canal recém-
     * pareado antes do gateway devolver a apikey.
     */
    public function connected(string $plainKey = 'wzap-test-key'): static
    {
        return $this->state(fn (): array => [
            'status' => WhatsAppChannel::STATUS_CONNECTED,
            'connected_at' => now(),
        ])->afterCreating(function (WhatsAppChannel $channel) use ($plainKey): void {
            $channel->storeInstanceApiKey($plainKey);
        });
    }

    /**
     * Canal aguardando a leitura do QR.
     */
    public function pairing(): static
    {
        return $this->state(fn (): array => [
            'status' => WhatsAppChannel::STATUS_PAIRING,
            'pairing_started_at' => now(),
        ]);
    }
}
