<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O dia do mês em que um documento da conta sincroniza sozinho. Documento
 * sem linha aqui é documento sem busca automática — a ausência é a decisão,
 * e não um dia herdado de config.
 *
 * `account_id` fica fora do `#[Fillable]`: a conta é do `CurrentTenant` na
 * request e parâmetro no console, e o manager grava por `forceFill` com o
 * valor explícito.
 */
#[Fillable([
    'obligation',
    'day',
])]
class SerproObligationSchedule extends Model
{
    /** @use HasFactory<SerproObligationScheduleFactory> */
    use BelongsToAccount, HasFactory;

    protected function casts(): array
    {
        return [
            'day' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
