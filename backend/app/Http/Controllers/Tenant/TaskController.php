<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\UpdateTaskRequest;
use App\Http\Resources\TaskResource;
use App\Models\Department;
use App\Models\Process;
use App\Models\Task;
use App\Services\SupportAudit;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class TaskController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Task::class);

        $filters = $request->validate([
            'process_id' => ['sometimes', 'integer'],
            'client_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', 'string'],
            'assignee_member_id' => ['sometimes', 'integer'],
            'department_id' => ['sometimes', 'nullable', 'integer'],
            'priority' => ['sometimes', 'string'],
            'due_from' => ['sometimes', 'date'],
            'due_to' => ['sometimes', 'date'],
        ]);

        $this->ensureDepartmentExists(isset($filters['department_id']) ? (int) $filters['department_id'] : null);

        $tasks = Task::query()
            ->with(['process.client', 'process.template', 'department'])
            ->ofProcess(isset($filters['process_id']) ? (int) $filters['process_id'] : null)
            ->ofClient(isset($filters['client_id']) ? (int) $filters['client_id'] : null)
            ->withStatus($filters['status'] ?? null)
            ->ofAssignee(isset($filters['assignee_member_id']) ? (int) $filters['assignee_member_id'] : null)
            ->ofDepartment(isset($filters['department_id']) ? (int) $filters['department_id'] : null)
            ->ofPriority($filters['priority'] ?? null)
            ->withDueRange($filters['due_from'] ?? null, $filters['due_to'] ?? null)
            ->ordered()
            ->paginate(25)
            ->withQueryString();

        return TaskResource::collection($tasks);
    }

    public function show(Task $task): TaskResource
    {
        Gate::authorize('view', $task);

        return new TaskResource($task->load(['process.client', 'process.template', 'department']));
    }

    public function update(UpdateTaskRequest $request, Task $task): TaskResource
    {
        $data = $request->validated();

        $from = $task->status instanceof TaskStatus ? $task->status->value : (string) $task->status;
        $to = $data['status'] ?? $from;
        $statusChanging = array_key_exists('status', $data) && $data['status'] !== $from;

        if ($statusChanging && $to === TaskStatus::Dismissed->value && empty($data['dismissal_reason'])) {
            abort(response()->json([
                'message' => 'Motivo obrigatório ao dispensar.',
                'errors' => ['dismissal_reason' => ['Motivo obrigatório.']],
            ], 422));
        }

        if ($statusChanging && $to !== TaskStatus::Todo->value && $task->process->template?->cascade) {
            $blocked = $task->process->tasks()
                ->where('order', '<', $task->order)
                ->whereNotIn('status', [TaskStatus::Done->value, TaskStatus::Dismissed->value])
                ->exists();

            if ($blocked) {
                abort(response()->json([
                    'message' => 'Etapa anterior pendente bloqueia o avanço (cascata).',
                ], 422));
            }
        }

        $task->status = $to;

        if ($statusChanging) {
            if (in_array($to, [TaskStatus::Done->value, TaskStatus::Dismissed->value], true)) {
                $task->completed_at ??= now();
                $task->dismissal_reason = $to === TaskStatus::Dismissed->value ? $data['dismissal_reason'] : null;
            } else {
                $task->completed_at = null;
                $task->dismissal_reason = null;
            }
        }

        if (array_key_exists('assignee_member_id', $data)) {
            $task->assignee_member_id = $data['assignee_member_id'];
        }

        if (array_key_exists('due_on', $data)) {
            $task->due_on = $data['due_on'];
        }

        $task->save();

        SupportAudit::logWrite($request, 'tasks', 'update', $task->getKey(), ['status' => $to]);

        return new TaskResource($task->load(['process.client', 'process.template', 'department']));
    }

    public function calendar(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Task::class);

        $filters = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'process_id' => ['sometimes', 'integer'],
            'client_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', 'string'],
            'assignee_member_id' => ['sometimes', 'integer'],
            'department_id' => ['sometimes', 'nullable', 'integer'],
            'priority' => ['sometimes', 'string'],
        ]);

        $this->ensureDepartmentExists(isset($filters['department_id']) ? (int) $filters['department_id'] : null);

        // filteredQuery já aplica whereDate no intervalo; whereBetween com
        // bound de data pura cortaria o último dia se due_on tiver horário.
        return TaskResource::collection($this->filteredQuery($filters)
            ->limit(2000)
            ->get());
    }

    public function grouped(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Task::class);

        $filters = $request->validate([
            'reference_month' => ['required', 'date_format:Y-m'],
        ]);

        $month = Carbon::createFromFormat('!Y-m', $filters['reference_month'])->startOfMonth();

        $processes = Process::query()
            ->with(['client', 'template', 'tasks' => fn ($query) => $query->with('department')->ordered()])
            ->whereDate('reference_month', $month->toDateString())
            ->orderBy('name')
            ->get();

        $clients = [];

        foreach ($processes as $process) {
            $clientId = $process->client?->getKey() ?? 0;
            $clientName = $process->client?->name ?? 'Sem cliente';

            if (! isset($clients[$clientId])) {
                $clients[$clientId] = [
                    'client' => ['id' => $clientId, 'name' => $clientName],
                    'totals' => ['processes' => 0, 'tasks' => 0],
                    'processes' => [],
                ];
            }

            $progress = $this->progressTotals($process->tasks);

            $processEntry = [
                'process' => [
                    'id' => $process->getKey(),
                    'name' => $process->name,
                    'status' => $this->processStatus($process),
                    'due_on' => $process->due_on?->toDateString(),
                    'reference_month' => $process->reference_month?->format('Y-m'),
                    'cascade' => (bool) ($process->template?->cascade ?? false),
                    'template' => $process->template === null ? null : [
                        'id' => $process->template->getKey(),
                        'name' => $process->template->name,
                        'cascade' => (bool) $process->template->cascade,
                    ],
                ],
                'totals' => ['tasks' => $progress['total'], 'done' => $progress['done'], 'dismissed' => $progress['dismissed'], 'open' => $progress['open']],
                'progress' => $progress,
                'ratio' => $progress['ratio'],
                'tasks' => TaskResource::collection($process->tasks)->resolve(),
            ];

            $clients[$clientId]['processes'][] = $processEntry;
            $clients[$clientId]['totals']['processes']++;
            $clients[$clientId]['totals']['tasks'] += $progress['total'];
        }

        return response()->json(['data' => array_values($clients)]);
    }

    public function unscopedForMonth(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Task::class);

        $filters = $request->validate([
            'reference_month' => ['required', 'date_format:Y-m'],
            'include_undated' => ['sometimes', 'boolean'],
        ]);

        $month = Carbon::createFromFormat('!Y-m', $filters['reference_month'])->startOfMonth();
        $includeUndated = (bool) ($filters['include_undated'] ?? false);

        return TaskResource::collection(Task::query()
            ->select('tasks.*')
            ->selectRaw('EXISTS (SELECT 1 FROM tasks AS prior WHERE prior.account_id = tasks.account_id AND prior.process_id = tasks.process_id AND prior."order" < tasks."order" AND prior.status NOT IN (?, ?)) AS cascade_locked', [TaskStatus::Done->value, TaskStatus::Dismissed->value])
            ->whereHas('process', fn (Builder $processes): Builder => $processes->whereNull('reference_month'))
            ->where(function (Builder $tasks) use ($month, $includeUndated): void {
                $tasks->withDueRange($month->toDateString(), $month->copy()->endOfMonth()->toDateString());
                if ($includeUndated) {
                    $tasks->orWhereNull('due_on');
                }
            })
            ->with(['process.client', 'process.template', 'department'])
            ->ordered()
            ->get());
    }

    /**
     * Totais alinhados a ProcessController::progressOf: done conta apenas
     * concluidas, dismissed conta dispensadas separadamente, open e o
     * restante, e ratio = done/total nos dois payloads.
     *
     * @return array{total: int, done: int, dismissed: int, open: int, ratio: float}
     */
    private function progressTotals(iterable $tasks): array
    {
        $total = 0;
        $done = 0;
        $dismissed = 0;

        foreach ($tasks as $task) {
            $total++;
            $status = $this->taskStatus($task);

            if ($status === TaskStatus::Done->value) {
                $done++;
            } elseif ($status === TaskStatus::Dismissed->value) {
                $dismissed++;
            }
        }

        return [
            'total' => $total,
            'done' => $done,
            'dismissed' => $dismissed,
            'open' => $total - $done - $dismissed,
            'ratio' => $total > 0 ? round($done / $total, 2) : 0.0,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function filteredQuery(array $filters): Builder
    {
        return Task::query()
            ->with(['process.client', 'process.template', 'department'])
            ->whereNotNull('due_on')
            ->whereDate('due_on', '>=', $filters['from'])
            ->whereDate('due_on', '<=', $filters['to'])
            ->ofProcess(isset($filters['process_id']) ? (int) $filters['process_id'] : null)
            ->ofClient(isset($filters['client_id']) ? (int) $filters['client_id'] : null)
            ->withStatus($filters['status'] ?? null)
            ->ofAssignee(isset($filters['assignee_member_id']) ? (int) $filters['assignee_member_id'] : null)
            ->ofDepartment(isset($filters['department_id']) ? (int) $filters['department_id'] : null)
            ->ofPriority($filters['priority'] ?? null)
            ->ordered();
    }

    private function taskStatus(Task $task): string
    {
        return $task->status instanceof TaskStatus ? $task->status->value : (string) $task->status;
    }

    private function processStatus(Process $process): string
    {
        return $process->status instanceof \BackedEnum ? $process->status->value : (string) $process->status;
    }

    private function ensureDepartmentExists(?int $departmentId): void
    {
        if ($departmentId === null) {
            return;
        }

        if (! Department::query()->whereKey($departmentId)->exists()) {
            abort(response()->json([
                'message' => 'O departamento informado não está cadastrado nesta conta.',
                'errors' => ['department_id' => ['O departamento informado não está cadastrado nesta conta.']],
            ], 422));
        }
    }
}
