<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpsertSerproConnectionRequest;
use App\Http\Resources\SerproConnectionResource;
use App\Models\SerproConnection;
use App\Services\SerproConnectionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SerproConnectionController extends Controller
{
    public function show(Request $request): SerproConnectionResource
    {
        $connection = SerproConnection::current();

        return $connection === null
            ? SerproConnectionResource::unconfigured()
            : new SerproConnectionResource($connection);
    }

    /**
     * O mesmo `PUT` cria na primeira vez e rotaciona depois, e a resposta é `200`
     * nas duas: quem grava não está criando um recurso novo, está configurando
     * uma credencial cuja identidade é única.
     *
     * O `200` é fixado aqui e não na resource porque a resource é a mesma da
     * leitura — e sem esta linha a primeira gravação, que o Laravel reconhece
     * como criação, responderia `201 Created` para um recurso que a tela sempre
     * tratou como a mesma credencial.
     */
    public function update(
        UpsertSerproConnectionRequest $request,
        SerproConnectionManager $manager,
    ): JsonResponse {
        $fields = $request->validated();

        return (new SerproConnectionResource($manager->save(
            (string) ($fields['consumer_key'] ?? ''),
            $fields['consumer_secret'] ?? null,
            $fields['certificate'] ?? null,
            $fields['password'] ?? null,
        )))->response()->setStatusCode(Response::HTTP_OK);
    }
}
