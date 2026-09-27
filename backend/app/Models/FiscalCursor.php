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

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
