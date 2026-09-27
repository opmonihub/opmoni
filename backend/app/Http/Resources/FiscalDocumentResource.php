<?php

namespace App\Http\Resources;

use App\Models\FiscalDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FiscalDocument */
class FiscalDocumentResource extends JsonResource
{
    /**
     * O caminho interno do arquivo nunca é exposto.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'client_id' => $this->client_id,
            'source' => $this->source?->value,
            'model' => $this->model?->value,
            'kind' => $this->kind?->value,
            'chave_acesso' => $this->chave_acesso,
            'event_id' => $this->event_id,
            'nsu' => $this->nsu,
            'emitente_cnpj' => $this->emitente_cnpj,
            'destinatario_cnpj' => $this->destinatario_cnpj,
            'valor_total' => $this->valor_total,
            'emissao_at' => $this->emissao_at?->toISOString(),
            'evento_ocorrido_em_at' => $this->evento_ocorrido_em_at?->toISOString(),
            'xml_bytes' => $this->xml_bytes,
            'mascarado' => $this->mascarado,
            'captured_at' => $this->captured_at?->toISOString(),
        ];
    }
}
