<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreDepartmentRequest;
use App\Http\Requests\Tenant\UpdateDepartmentRequest;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use App\Services\SupportAudit;
use App\Tenant\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class DepartmentController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Department::class);

        return DepartmentResource::collection(
            Department::query()->withCount('members')->orderBy('name')->get()
        );
    }

    public function store(StoreDepartmentRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $department = Department::query()->create([
            'name' => $validated['name'],
            'color' => $validated['color'],
        ]);

        $this->syncMembers($department, $validated['member_ids'] ?? []);

        SupportAudit::logWrite($request, 'departments', 'create', $department->getKey(), ['name' => $department->name]);

        return (new DepartmentResource($department->loadCount('members')))->response()->setStatusCode(201);
    }

    public function update(UpdateDepartmentRequest $request, Department $department): DepartmentResource
    {
        $validated = $request->validated();

        $department->update(array_intersect_key($validated, ['name' => true, 'color' => true]));

        if (array_key_exists('member_ids', $validated)) {
            $this->syncMembers($department, $validated['member_ids'] ?? []);
        }

        SupportAudit::logWrite($request, 'departments', 'update', $department->getKey(), ['name' => $department->name]);

        return new DepartmentResource($department->loadCount('members'));
    }

    /**
     * Excluir nunca bloqueia por uso: o `nullOnDelete` das FKs solta etapas e
     * tasks, que passam a aparecer como "Sem departamento".
     */
    public function destroy(Request $request, Department $department): Response
    {
        Gate::authorize('delete', $department);

        $id = $department->getKey();
        $name = $department->name;
        $department->delete();
        SupportAudit::logWrite($request, 'departments', 'delete', $id, ['name' => $name]);

        return response()->noContent();
    }

    /**
     * @param  array<int>  $memberIds
     */
    private function syncMembers(Department $department, array $memberIds): void
    {
        $tenantId = resolve(CurrentTenant::class)->accountId;

        $department->members()->sync(
            collect($memberIds)->unique()->mapWithKeys(fn ($id) => [$id => ['account_id' => $tenantId]])->all()
        );
    }
}
