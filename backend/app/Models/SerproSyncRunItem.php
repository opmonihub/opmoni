<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use App\Enums\SerproSyncItemState;
use Database\Factories\SerproSyncRunItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um cliente dentro de uma execução.
 *
 * `account_id` é redundante com `run.account_id` de propósito: o job filho
 * lê e escreve por ele porque no worker o escopo global do tenant não filtra
 * nada, e depender do JOIN para o isolamento seria depender de quem chama
 * lembrar de fazer o JOIN.
 *
 * O `#[Fillable]` cobre o que o job escreve no andamento; `run_id` e
 * `client_id` ficam fora porque o par é a chave da idempotência e nenhuma
 * mass-assignment deve repontá-lo.
 */
#[Fillable([
    'state',
    'current_obligation',
    'reason',
    'provider_code',
    'response_id',
    'request_tag',
    'attempted_at',
])]
class SerproSyncRunItem extends Model
{
    /** @use HasFactory<SerproSyncRunItemFactory> */
    use BelongsToAccount, HasFactory;

    protected $table = 'serpro_sync_run_items';

    protected function casts(): array
    {
        return [
            'state' => SerproSyncItemState::class,
            'attempted_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(SerproSyncRun::class, 'run_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
