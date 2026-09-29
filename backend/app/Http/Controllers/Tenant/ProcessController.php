<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\UpdateProcessRequest;
use App\Http\Resources\ProcessResource;
use App\Models\Process;
use App\Services\SupportAudit;
use App\Services\TaskProgress;
use App\Tenant\CurrentTenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ProcessController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Process::class);

        $filters = $request->validate([
            'template_id' => ['sometimes', 'integer'],
            'reference_month' => ['sometimes', 'date_format:Y-m'],
            'client_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', 'string'],
        ]);

        $processes = Process::query()
            ->with(['template', 'client'])
            ->when(isset($filters['template_id']), fn (Builder $query) => $query->where('template_id', $filters['template_id']))
            ->when(isset($filters['reference_month']), function (Builder $query) use ($filters): Builder {
                $month = Carbon::createFromFormat('!Y-m', $filters['reference_month'])->startOfMonth();

                return $query->whereDate('reference_month', $month->toDateString());
            })
            ->when(isset($filters['client_id']), fn (Builder $query) => $query->where('client_id', $filters['client_id']))
            ->when(isset($filters['status']), fn (Builder $query) => $query->where('status', $filters['status']))
            ->orderByDesc('reference_month')
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return ProcessResource::collection($processes);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Process::class);

        $accountId = resolve(CurrentTenant::class)->accountId;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'client_id' => ['sometimes', 'integer', Rule::exists('clients', 'id')->where(fn ($query) => $query->where('account_id', $accountId))],
            'template_id' => ['sometimes', 'integer', Rule::exists('process_templates', 'id')->where(fn ($query) => $query->where('account_id', $accountId))],
            'reference_month' => ['sometimes', 'date_format:Y-m'],
        ]);

        if (isset($data['reference_month'])) {
            $data['reference_month'] = Carbon::createFromFormat('!Y-m', $data['reference_month'])->startOfMonth()->toDateString();
        }

        $process = Process::query()->create($data);

        SupportAudit::logWrite($request, 'processes', 'create', $process->getKey(), ['name' => $process->name]);

        return (new ProcessResource($process->load(['template', 'client'])))->response()->setStatusCode(201);
    }

    public function show(Process $process): ProcessResource
    {
        Gate::authorize('view', $process);

        $process->load([
            'template',
            'client',
            'tasks' => fn ($query) => $query->ordered(),
        ]);

        $process->setAttribute('progress', $this->progressOf($process));

        return new ProcessResource($process);
    }

    public function update(UpdateProcessRequest $request, Process $process): ProcessResource
    {
        $process->update($request->validated());

        SupportAudit::logWrite($request, 'processes', 'update', $process->getKey(), ['name' => $process->name]);

        return new ProcessResource($process->load(['template', 'client']));
    }

    public function destroy(Request $request, Process $process): Response
    {
        Gate::authorize('delete', $process);

        $processId = $process->getKey();
        $processName = $process->name;
        $process->delete();

        SupportAudit::logWrite($request, 'processes', 'delete', $processId, ['name' => $processName]);

        return response()->noContent();
    }

    /**
     * @return array{total: int, done: int, dismissed: int, open: int, ratio: float}
     */
    private function progressOf(Process $process): array
    {
        return resolve(TaskProgress::class)->for($process);
    }
}
