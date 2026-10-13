<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'client_id', 'template_id', 'reference_month', 'status', 'due_on'])]
class Process extends Model
{
    /** @use HasFactory<Process> */
    use BelongsToAccount, HasFactory;

    protected function casts(): array
    {
        return [
            'reference_month' => 'date',
            'due_on' => 'date',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ProcessTemplate::class, 'template_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
