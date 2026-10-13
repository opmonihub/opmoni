<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'cascade', 'generate_day', 'due_day', 'is_active', 'regimes'])]
class ProcessTemplate extends Model
{
    /** @use HasFactory<ProcessTemplate> */
    use BelongsToAccount, HasFactory;

    protected function casts(): array
    {
        return [
            'cascade' => 'boolean',
            'is_active' => 'boolean',
            'regimes' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ProcessTemplateTask::class, 'template_id')->orderBy('order');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'template_tag', 'template_id', 'tag_id')->withPivot('account_id');
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(TemplateClientException::class, 'template_id');
    }

    public function processes(): HasMany
    {
        return $this->hasMany(Process::class, 'template_id');
    }
}
