<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\ReadSerproMessageRequest;
use App\Services\SerproException;
use App\Services\SerproMailboxReader;
use App\Services\SupportAudit;
use App\Tenant\CurrentTenant;
use Illuminate\Http\JsonResponse;

/**
 * A leitura de uma mensagem da caixa postal, que é ciência da intimação
 * (D19). É `POST` porque registra um ato: a confirmação vai no corpo e é
 * conferida pelo Form Request antes de qualquer chamada.
 *
 * A falha do provedor responde com o rótulo da falha, nunca com o texto do
 * provedor, pelo mesmo motivo do `SerproClient`.
 */
class SerproMonitoringMessageController extends Controller
{
    public function store(
        string $obligation,
        int $client,
        int $message,
        ReadSerproMessageRequest $request,
        SerproMailboxReader $reader,
    ): JsonResponse {
        try {
            $data = $reader->read(
                (int) resolve(CurrentTenant::class)->accountId,
                $obligation,
                $client,
                $message,
            );
        } catch (SerproException $exception) {
            return response()->json(['message' => $exception->failure->label()], 502);
        }

        // Só depois do sucesso: é aí que a ciência correu no provedor.
        SupportAudit::logWrite($request, 'serpro_messages', 'ciencia', $message, [
            'obligation' => $obligation,
            'client_id' => $client,
        ]);

        return response()->json(['data' => $data]);
    }
}
