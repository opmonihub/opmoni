<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpsertSerproConnectionRequest;
use App\Http\Resources\SerproConnectionResource;
use App\Models\SerproConnection;
use App\Services\SerproConnectionManager;
use Illuminate\Http\Request;

class SerproConnectionController extends Controller
{
    public function show(Request $request): SerproConnectionResource
    {
        $connection = SerproConnection::current();

        return $connection === null
            ? SerproConnectionResource::unconfigured()
            : new SerproConnectionResource($connection);
    }

    public function update(
        UpsertSerproConnectionRequest $request,
        SerproConnectionManager $manager,
    ): SerproConnectionResource {
        $fields = $request->validated();

        return new SerproConnectionResource($manager->save(
            (string) ($fields['consumer_key'] ?? ''),
            $fields['consumer_secret'] ?? null,
            $fields['certificate'] ?? null,
            $fields['password'] ?? null,
        ));
    }
}
