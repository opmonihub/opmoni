<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ValidatedListOrdering;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    public function index(IndexUserRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $users = User::with('accountLinks.account')
            ->search($filters['q'] ?? null)
            ->withAdminType($filters['type'] ?? null);
        if (isset($filters['sort'])) {
            ValidatedListOrdering::apply($users, $filters['sort'], $filters['direction'] ?? null, [
                'id' => 'users.id',
                'name' => 'users.name',
                'email' => 'users.email',
            ], 'users.id');
        } else {
            $users->orderByDesc('id');
        }
        $users = $users->paginate(15)->withQueryString();

        return UserResource::collection($users);
    }
}
