<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use App\Enums\SerproManualSearchMode;
use App\Enums\SerproManualSearchState;
use Database\Factories\SerproManualSearchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O pedido de uma busca manual sob demanda: **um cliente para uma
 * obrigação**, e é esta linha que a cota mensal conta — não a execução, que
 * aqui nem existe.
 *
 * `account_id`, `client_id` e `requested_by` ficam fora do `#[Fillable]`
 * pelo motivo de sempre: o vínculo é a identidade da linha e a autoria é do
 * request autenticado, e nenhum corpo de POST os declara. O starter grava
 * por `forceFill` com a conta explícita — no worker o `CurrentTenant`
 * carrega o valor do job anterior.
 */
#[Fillable([
    'obligation',
    'state',
    'mode',
    'recalculate_date',
    'reason',
])]
class SerproManualSearch extends Model
{
    /** @use HasFactory<SerproManualSearchFactory> */
    use BelongsToAccount, HasFactory;

    protected function casts(): array
    {
        return [
            'state' => SerproManualSearchState::class,
            'mode' => SerproManualSearchMode::class,
            'recalculate_date' => 'date',
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

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
