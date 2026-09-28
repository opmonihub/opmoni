<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use App\Enums\FiscalSource;
use Database\Factories\FiscalCursorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['client_id', 'source', 'last_nsu', 'last_run_at', 'last_success_at', 'last_error', 'blocked_until', 'last_seen_at'])]
class FiscalCursor extends Model
{
    /** @use HasFactory<FiscalCursorFactory> */
    use BelongsToAccount, HasFactory;

    protected function casts(): array
    {
        return [
            'source' => FiscalSource::class,
            'last_nsu' => 'integer',
            'last_run_at' => 'datetime',
            'last_success_at' => 'datetime',
            'blocked_until' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * O fisco mandou parar este cliente por uma hora.
     *
     * A parada é de dono, não de tempo: ela vale para a captura incremental e
     * para a reconciliação, porque as duas são consulta ao mesmo serviço, e
     * retomar antes de completar a hora zera a contagem do fisco e a reinicia.
     */
    public function isBlocked(): bool
    {
        return $this->blocked_until?->isFuture() === true;
    }

    /**
     * O fisco não gera posições retroativas para o período que ficou de fora,
     * então uma captura parada além da janela de continuidade não recupera
     * nada: ela apenas produz "nenhum documento localizado" para sempre. A
     * data é a da última vez que o serviço respondeu, e é por isso que ela é
     * escrita depois da chamada.
     */
    public function historyIsInterrupted(): bool
    {
        if ($this->last_seen_at === null) {
            return false;
        }

        return $this->last_seen_at->lt(now()->subDays((int) config('fiscal.continuity_days', 60)));
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
