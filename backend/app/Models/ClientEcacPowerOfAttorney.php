<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use App\Enums\SerproPowerOfAttorneyState;
use Database\Factories\ClientEcacPowerOfAttorneyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/*
 * `serpro_code` é `Fillable` porque é exatamente o que o Membro digita: o
 * código da procuração emitida no e-CAC. `integration_state` **não** é —
 * quem escreve o estado é o `SerproPowerOracle` lendo o provedor, ou o
 * `saving` abaixo marcando `pending` quando o código muda. Um estado
 * `established` vindo de um corpo de requisição seria a forma mais curta de
 * declarar autorizada uma outorga que o SERPRO nunca confirmou.
 */
#[Fillable(['client_id', 'starts_at', 'expires_at', 'notes', 'serpro_code'])]
class ClientEcacPowerOfAttorney extends Model
{
    /** @use HasFactory<ClientEcacPowerOfAttorneyFactory> */
    use BelongsToAccount, HasFactory;

    protected $table = 'client_ecac_powers_of_attorney';

    protected static function booted(): void
    {
        static::saving(function (ClientEcacPowerOfAttorney $powerOfAttorney): void {
            if (! $powerOfAttorney->isDirty('serpro_code')) {
                return;
            }

            // O código mudou — ou sumiu — e o estado observado não vale mais:
            // a confirmação era do código anterior, e pendurar `established`
            // num código novo habilitaria o cliente com a prova de outro.
            $powerOfAttorney->integration_state = $powerOfAttorney->serpro_code === null
                ? null
                : SerproPowerOfAttorneyState::Pending;
        });
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'date',
            'expires_at' => 'date',
            'integration_state' => SerproPowerOfAttorneyState::class,
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
