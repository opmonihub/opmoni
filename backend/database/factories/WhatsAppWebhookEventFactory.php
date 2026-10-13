<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\WhatsAppChannel;
use App\Models\WhatsAppWebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsAppWebhookEvent>
 */
class WhatsAppWebhookEventFactory extends Factory
{
    protected $model = WhatsAppWebhookEvent::class;

    /**
     * O par `account_id`/`whatsapp_channel_id` nasce coerente: o evento é
     * do canal daquela conta.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'whatsapp_channel_id' => fn (array $attributes): mixed => WhatsAppChannel::factory()
                ->create(['account_id' => $attributes['account_id']])
                ->getKey(),
            'event_id' => 'evt_'.fake()->unique()->lexify('????????????'),
            'whatsapp_dispatch_id' => null,
        ];
    }
}
