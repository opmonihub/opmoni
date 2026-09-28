<?php

namespace App\Http\Resources;

use App\Models\AccountCertificate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A tela precisa saber *qual* e-CNPJ o escritório entregou e *quando* ele
 * deixa de valer, e não *como* ele está guardado: o texto cifrado, a senha que
 * o abre e qualquer caminho de arquivo não têm chave aqui. Por isso a lista é
 * explícita — e o `#[Hidden]` do modelo é a segunda rede, caso alguém chegue a
 * serializar a linha inteira.
 *
 * O `sha256` também não sai: é um metadado não secreto, mas não é nada que a
 * tela faça, e chave a mais numa lista fechada é um lugar a mais para vazar.
 *
 * @mixin AccountCertificate
 */
class AccountCertificateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'document' => $this->document,
            'subject' => $this->subject,
            'serial_number' => $this->serial_number,
            'valid_from' => $this->valid_from->toISOString(),
            'valid_until' => $this->valid_until->toISOString(),
            'original_filename' => $this->original_filename,
            'uploaded_at' => $this->created_at?->toISOString(),
        ];
    }
}
