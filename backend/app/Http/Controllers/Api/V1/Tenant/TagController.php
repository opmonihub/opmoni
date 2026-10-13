<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreTagRequest;
use App\Http\Requests\Tenant\UpdateTagRequest;
use App\Http\Resources\TagResource;
use App\Models\Tag;
use App\Services\SupportAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class TagController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Tag::class);

        return TagResource::collection(Tag::query()->orderBy('name')->paginate(25));
    }

    public function store(StoreTagRequest $request): JsonResponse
    {
        $tag = Tag::query()->create($request->validated());
        SupportAudit::logWrite($request, 'tags', 'create', $tag->getKey(), ['name' => $tag->name]);

        return (new TagResource($tag))->response()->setStatusCode(201);
    }

    public function update(UpdateTagRequest $request, Tag $tag): TagResource
    {
        $tag->update($request->validated());
        SupportAudit::logWrite($request, 'tags', 'update', $tag->getKey(), ['name' => $tag->name]);

        return new TagResource($tag);
    }

    public function destroy(Request $request, Tag $tag): Response
    {
        Gate::authorize('delete', $tag);
        $id = $tag->getKey();
        $name = $tag->name;
        $tag->delete();
        SupportAudit::logWrite($request, 'tags', 'delete', $id, ['name' => $name]);

        return response()->noContent();
    }
}
