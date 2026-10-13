<?php

namespace App\Http\Resources;

use App\Models\SerproCall;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A chamada como a reconciliação a lê: serviço, versão, caminho, cobrança,
 * os identificadores e a duração. Nada de `dados` nem de envelope — a lista
 * existe para que a tela não possa pedir o payload por engano, e `messages`
 * é o que o `SerproCallRecorder` já sanitizou ao gravar.
 *
 * @mixin SerproCall
 */
class SerproCallResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'client_id' => $this->client_id,
            'id_sistema' => $this->id_sistema,
            'id_servico' => $this->id_servico,
            'version' => $this->version,
            'path' => $this->path,
            'billable' => $this->billable,
            'status' => $this->status->value,
            'provider_code' => $this->provider_code,
            'response_id' => $this->response_id,
            'request_tag' => $this->request_tag,
            'messages' => $this->messages,
            'duration_ms' => $this->duration_ms,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
