<?php

namespace App\Models\Erp;

use App\Models\Erp\Concerns\BelongsToErpCompany;
use App\Models\User;
use Database\Factories\Erp\ErpContactNoteFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ErpContactNote extends Model
{
    /** @use HasFactory<ErpContactNoteFactory> */
    use BelongsToErpCompany, HasFactory;

    protected $fillable = [
        'erp_company_id', 'erp_contact_id', 'created_by', 'heading', 'description', 'is_private', 'state',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_private' => 'boolean'];
    }

    protected static function newFactory(): ErpContactNoteFactory
    {
        return ErpContactNoteFactory::new();
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(ErpContact::class, 'erp_contact_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ErpContactAttachment::class, 'erp_contact_note_id');
    }

    /** @param Builder<ErpContactNote> $query */
    public function scopeVisibleTo(Builder $query, int $actorId): Builder
    {
        return $query->where(function (Builder $visible) use ($actorId): void {
            $visible->where('erp_contact_notes.is_private', false)
                ->orWhere('erp_contact_notes.created_by', $actorId);
        });
    }
}
