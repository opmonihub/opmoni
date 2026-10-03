<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use App\Enums\FiscalManifestationEventType;
use App\Enums\FiscalManifestationOutcome;
use Database\Factories\FiscalManifestationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'client_id',
    'chave_acesso',
    'event_type',
    'event_seq',
    'requested_by',
    'outcome',
    'requested_at',
    'sent_at',
    'resulted_at',
])]
class FiscalManifestation extends Model
{
    /** @use HasFactory<FiscalManifestationFactory> */
    use BelongsToAccount, HasFactory;

    protected function casts(): array
    {
        return [
            'event_type' => FiscalManifestationEventType::class,
            'event_seq' => 'integer',
            'outcome' => FiscalManifestationOutcome::class,
            'requested_at' => 'datetime',
            'sent_at' => 'datetime',
            'resulted_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }
}
