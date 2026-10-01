<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreProcessTemplateRequest;
use App\Http\Requests\Tenant\UpdateProcessTemplateRequest;
use App\Http\Resources\ProcessTemplateResource;
use App\Models\ProcessTemplate;
use App\Services\ProcessGenerationService;
use App\Services\SupportAudit;
use App\Tenant\CurrentTenant;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class ProcessTemplateController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', ProcessTemplate::class);

        return ProcessTemplateResource::collection(
            ProcessTemplate::query()->with(['tags', 'exceptions', 'steps.department'])->orderBy('name')->get()
        );
    }

    public function store(StoreProcessTemplateRequest $request): JsonResponse
    {
        $data = $request->validated();

        $template = ProcessTemplate::query()->create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'cascade' => $data['cascade'] ?? false,
            'generate_day' => $data['generate_day'] ?? 1,
            'due_day' => $data['due_day'] ?? 20,
            'is_active' => $data['is_active'] ?? true,
            'regimes' => $data['regimes'] ?? null,
        ]);

        $this->syncRelations($template, $data);

        SupportAudit::logWrite($request, 'process_templates', 'create', $template->getKey(), ['name' => $template->name]);

        return (new ProcessTemplateResource($template->load(['tags', 'exceptions', 'steps.department'])))->response()->setStatusCode(201);
    }

    public function show(ProcessTemplate $processTemplate): ProcessTemplateResource
    {
        Gate::authorize('view', $processTemplate);

        return new ProcessTemplateResource($processTemplate->load(['tags', 'exceptions', 'steps.department']));
    }

    public function update(UpdateProcessTemplateRequest $request, ProcessTemplate $processTemplate): ProcessTemplateResource
    {
        $data = $request->validated();

        $templateData = array_intersect_key($data, array_flip([
            'name', 'description', 'cascade', 'generate_day', 'due_day', 'is_active', 'regimes',
        ]));

        if ($templateData !== []) {
            $processTemplate->update($templateData);
        }

        $this->syncRelations($processTemplate, $data);

        SupportAudit::logWrite($request, 'process_templates', 'update', $processTemplate->getKey(), ['name' => $processTemplate->name]);

        return new ProcessTemplateResource($processTemplate->load(['tags', 'exceptions', 'steps.department']));
    }

    public function destroy(Request $request, ProcessTemplate $processTemplate): Response
    {
        Gate::authorize('delete', $processTemplate);

        $id = $processTemplate->getKey();
        $name = $processTemplate->name;
        $processTemplate->delete();

        SupportAudit::logWrite($request, 'process_templates', 'delete', $id, ['name' => $name]);

        return response()->noContent();
    }

    public function preview(ProcessTemplate $processTemplate, ProcessGenerationService $service): JsonResponse
    {
        Gate::authorize('view', $processTemplate);

        $rows = $service->preview($processTemplate);

        return response()->json([
            'data' => $rows->map(fn (array $row): array => [
                'client' => ['id' => $row['client']->getKey(), 'name' => $row['client']->name],
                'reason' => $row['reason'],
            ])->values(),
        ]);
    }

    public function generate(Request $request, ProcessTemplate $processTemplate, ProcessGenerationService $service): JsonResponse
    {
        Gate::authorize('update', $processTemplate);

        $data = $request->validate(['reference_month' => ['required', 'date_format:Y-m']]);

        $month = Carbon::createFromFormat('!Y-m', $data['reference_month'])->startOfMonth()->startOfDay();
        $processes = $service->generate($processTemplate, $month);

        SupportAudit::logWrite(
            $request,
            'process_templates',
            'generate',
            $processTemplate->getKey(),
            ['reference_month' => $data['reference_month'], 'count' => $processes->count()]
        );

        return response()->json([
            'data' => $processes->map(fn ($process): array => [
                'id' => $process->getKey(),
                'name' => $process->name,
                'client_id' => $process->client_id,
            ])->values(),
        ], 201);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncRelations(ProcessTemplate $template, array $data): void
    {
        if (array_key_exists('tag_ids', $data)) {
            $tenantId = resolve(CurrentTenant::class)->accountId;
            $attach = [];

            foreach ((array) $data['tag_ids'] as $tagId) {
                $attach[(int) $tagId] = ['account_id' => $tenantId];
            }

            $template->tags()->sync($attach);
        }

        if (array_key_exists('exceptions', $data)) {
            $template->exceptions()->delete();

            foreach ((array) $data['exceptions'] as $exception) {
                $template->exceptions()->create([
                    'client_id' => $exception['client_id'],
                    'kind' => $exception['kind'],
                ]);
            }
        }

        if (array_key_exists('steps', $data)) {
            $kept = [];

            foreach ((array) $data['steps'] as $step) {
                $row = $step;
                unset($row['id']);
                unset($row['account_id']);

                if (isset($step['id'])) {
                    $existing = $template->steps()->whereKey((int) $step['id'])->first();

                    if ($existing !== null) {
                        $existing->update($row);
                        $kept[] = $existing->getKey();

                        continue;
                    }
                }

                $kept[] = $template->steps()->create($row)->getKey();
            }

            $template->steps()->whereNotIn('id', $kept === [] ? [0] : $kept)->delete();
        }
    }
}
