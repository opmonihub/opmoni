<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['process_id', 'title', 'department_id', 'description', 'status', 'due_on', 'priority', 'assignee_member_id', 'completed_at', 'dismissal_reason', 'order'])]
class Task extends Model
{
    /** @use HasFactory<Task> */
    use BelongsToAccount, HasFactory;

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'priority' => TaskPriority::class,
            'due_on' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function process(): BelongsTo
    {
        return $this->belongsTo(Process::class);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByRaw('due_on IS NULL')->orderBy('due_on')->orderBy('order');
    }

    public function scopeWithStatus(Builder $query, string|array|null $status): Builder
    {
        $values = is_array($status) ? $status : ($status ? [$status] : []);

        return $values === [] ? $query : $query->whereIn('status', $values);
    }

    public function scopeWithDueRange(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn (Builder $dates): Builder => $dates->whereDate('due_on', '>=', $from))
            ->when($to, fn (Builder $dates): Builder => $dates->whereDate('due_on', '<=', $to));
    }

    public function scopeOfProcess(Builder $query, ?int $processId): Builder
    {
        return $processId === null ? $query : $query->where('process_id', $processId);
    }

    public function scopeOfClient(Builder $query, ?int $clientId): Builder
    {
        return $clientId === null
            ? $query
            : $query->whereHas('process', fn (Builder $processes): Builder => $processes->where('client_id', $clientId));
    }

    public function scopeOfAssignee(Builder $query, ?int $assigneeMemberId): Builder
    {
        return $assigneeMemberId === null ? $query : $query->where('assignee_member_id', $assigneeMemberId);
    }

    public function scopeOfDepartment(Builder $query, ?int $departmentId): Builder
    {
        return $departmentId === null ? $query : $query->where('department_id', $departmentId);
    }

    public function scopeOfPriority(Builder $query, string|array|null $priority): Builder
    {
        $values = is_array($priority) ? $priority : ($priority ? [$priority] : []);

        return $values === [] ? $query : $query->whereIn('priority', $values);
    }
}
