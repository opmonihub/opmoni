<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    public function index(IndexUserRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $users = User::with('accountLinks.account')
            ->search($filters['q'] ?? null)
            ->withAdminType($filters['type'] ?? null)
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return UserResource::collection($users);
    }
}
