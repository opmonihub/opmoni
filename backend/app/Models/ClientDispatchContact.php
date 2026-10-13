<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um Contato de envio do cliente: rótulo opcional + exatamente um canal
 * (`whatsapp` ou `email`) + o valor do canal (telefone em dígitos ou
 * endereço de e-mail em minúsculas).
 *
 * Não é o "Contato" comercial da conta — a spec reserva esse nome para
 * outra entidade. A unicidade lógica é por (cliente, canal, valor): dois
 * sócios podem ter rótulos diferentes no mesmo número, mas o mesmo
 * número duas vezes no mesmo canal é o mesmo destino.
 *
 * Contato `email` é aceito pela API mas não é elegível para Disparo
 * nesta versão — quem aplica esse corte é o serviço de Disparo, não o
 * CRUD.
 */
#[Fillable([
    'client_id',
    'label',
    'channel',
    'value',
])]
class ClientDispatchContact extends Model
{
    /** @use HasFactory<ClientDispatchContact> */
    use BelongsToAccount, HasFactory;

    public const CHANNEL_WHATSAPP = 'whatsapp';

    public const CHANNEL_EMAIL = 'email';

    public const CHANNELS = [
        self::CHANNEL_WHATSAPP,
        self::CHANNEL_EMAIL,
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * A régua do telefone de envio: BR-plausível, 10–13 dígitos — a mesma
     * que o `WhatsAppDispatchService::garantirTelefone` aplica. A
     * normalização fina (nono dígito, DDI) é do gateway, não do cadastro.
     */
    public static function telefonePlausivel(string $value): bool
    {
        $digits = preg_replace('/\D+/', '', $value);

        return strlen($digits) >= 10 && strlen($digits) <= 13;
    }

    /**
     * O valor persistido: telefone em dígitos, e-mail em minúsculas. A
     * única forma de o valor circular normalizado para todo mundo é a
     * normalização morar aqui.
     */
    public static function normalizarValor(string $channel, string $value): string
    {
        return $channel === self::CHANNEL_WHATSAPP
            ? (string) preg_replace('/\D+/', '', $value)
            : mb_strtolower(trim($value));
    }
}
