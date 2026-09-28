<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use App\Enums\SerproSyncRunState;
use Database\Factories\SerproSyncRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma execução de sincronização, do pedido ao resultado.
 *
 * O `#[Fillable]` cobre o que uma linha nova precisa — estado e autoria — e
 * fica de fora `account_id` e os seis contadores: a conta vem de
 * `forceFill`/trait nos caminhos que sabem qual é, e os contadores pertencem
 * ao `SerproRunFinalizer`, que é o único que reconta. Nenhuma request deve
 * poder declarar "50 sincronizados".
 */
#[Fillable([
    'requested_by',
    'state',
    'reason',
    'started_at',
    'finished_at',
])]
class SerproSyncRun extends Model
{
    /** @use HasFactory<SerproSyncRunFactory> */
    use BelongsToAccount, HasFactory;

    protected function casts(): array
    {
        return [
            'state' => SerproSyncRunState::class,
            'total' => 'integer',
            'synchronized' => 'integer',
            'skipped' => 'integer',
            'failed' => 'integer',
            'indeterminate' => 'integer',
            'not_processed' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SerproSyncRunItem::class, 'run_id');
    }

    public function calls(): HasMany
    {
        return $this->hasMany(SerproCall::class, 'run_id');
    }
}
