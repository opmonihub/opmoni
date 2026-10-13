<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Client;
use App\Models\ClientDispatchContact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientDispatchContact>
 */
class ClientDispatchContactFactory extends Factory
{
    protected $model = ClientDispatchContact::class;

    /**
     * O par `account_id`/`client_id` nasce coerente quando só a conta é
     * dita — o contato é de um cliente daquela conta.
     *
     * O `client_id` expande a factory da conta no `create` para ler o id
     * real: um `$attributes['account_id'] ?? …` veria o próprio closure
     * da factory (truthy) e geraria um cliente de conta nova.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'client_id' => function (array $attributes): mixed {
                $account = $attributes['account_id'] instanceof Account
                    ? $attributes['account_id']
                    : Account::query()->find($attributes['account_id'])
                        ?? Account::factory()->create();

                return Client::factory()->company()
                    ->create(['account_id' => $account->getKey()])
                    ->getKey();
            },
            'label' => null,
            'channel' => ClientDispatchContact::CHANNEL_WHATSAPP,
            'value' => fake()->numerify('11#########'),
        ];
    }

    /**
     * Contato de envio por WhatsApp, com telefone BR-plausível.
     */
    public function whatsapp(): static
    {
        return $this->state(fn (): array => [
            'channel' => ClientDispatchContact::CHANNEL_WHATSAPP,
            'value' => fake()->numerify('11#########'),
        ]);
    }

    /**
     * Contato de envio por e-mail — aceito pelo CRUD, inelegível para o
     * Disparo nesta versão.
     */
    public function email(): static
    {
        return $this->state(fn (): array => [
            'channel' => ClientDispatchContact::CHANNEL_EMAIL,
            'value' => fake()->unique()->safeEmail(),
        ]);
    }
}
