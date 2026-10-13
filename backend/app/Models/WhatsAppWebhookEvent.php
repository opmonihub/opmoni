<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O recibo do wzap já processado: dedupe por `event_id` por canal.
 *
 * Guarda-se só a identidade do evento e o disparo que ele atualizou. O
 * corpo do webhook pode carregar texto de mensagem e por isso nunca é
 * persistido inteiro — a tabela responde "este evento já foi visto", e só.
 */
#[Fillable([
    'whatsapp_channel_id',
    'event_id',
    'whatsapp_dispatch_id',
])]
class WhatsAppWebhookEvent extends Model
{
    /** @use HasFactory<WhatsAppWebhookEvent> */
    use BelongsToAccount, HasFactory;

    /**
     * O plural padrão do Eloquent quebraria em
     * `whats_app_webhook_events`; a tabela é a do contrato,
     * `whatsapp_webhook_events`.
     */
    protected $table = 'whatsapp_webhook_events';

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(WhatsAppChannel::class, 'whatsapp_channel_id');
    }

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(WhatsAppDispatch::class, 'whatsapp_dispatch_id');
    }
}
