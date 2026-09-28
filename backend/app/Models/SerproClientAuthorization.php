<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use App\Enums\SerproPowerOfAttorneyState;
use Database\Factories\SerproClientAuthorizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A autorização de **um cliente para uma família de serviço**, tal como o
 * provedor a respondeu.
 *
 * Nenhuma escrita deste sistema cria uma linha `established`: quem confirma
 * a outorga é o `SerproPowerOracle` lendo `OBTERPROCURACAO41`, e a linha que
 * a tela mostra é o que ele viu. O `#[Fillable]` cobre as colunas de dados
 * porque o oracle grava pelo model — `account_id` e `client_id` ficam de
 * fora de propósito, pelo mesmo motivo das colunas cifradas dos outros
 * modelos SERPRO: nenhuma request deve poder apontar uma autorização para
 * um cliente que não é o dela.
 */
#[Fillable([
    'family',
    'code',
    'state',
    'expires_on',
    'verified_at',
])]
class SerproClientAuthorization extends Model
{
    /** @use HasFactory<SerproClientAuthorizationFactory> */
    use BelongsToAccount, HasFactory;

    protected $table = 'serpro_client_authorizations';

    protected function casts(): array
    {
        return [
            'state' => SerproPowerOfAttorneyState::class,
            'expires_on' => 'date',
            'verified_at' => 'datetime',
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
