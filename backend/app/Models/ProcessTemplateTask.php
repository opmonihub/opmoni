<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['template_id', 'title', 'department_id', 'description', 'due_day', 'priority', 'order', 'default_assignee_member_id'])]
class ProcessTemplateTask extends Model
{
    /** @use HasFactory<ProcessTemplateTask> */
    use BelongsToAccount, HasFactory;

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ProcessTemplate::class, 'template_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
