<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SerproConnectivity;
use Illuminate\Http\JsonResponse;

class SerproConnectivityController extends Controller
{
    /**
     * O corpo é o veredito do serviço e nada mais: quem pergunta recebe nome do
     * que falhou, instante da verificação e, quando há, o motivo — nunca o texto
     * do provedor nem qualquer parte da credencial.
     */
    public function __invoke(SerproConnectivity $connectivity): JsonResponse
    {
        return response()->json(['data' => $connectivity->check()]);
    }
}
