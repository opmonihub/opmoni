<?php

namespace App\Http\Resources;

use App\Models\SerproConnection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A tela de conexão precisa saber *quem* a plataforma está contratada, e não
 * *como*: o segredo, a senha do certificado, os bytes do PFX e o caminho de
 * qualquer arquivo não têm chave aqui. Por isso a lista é explícita — e o
 * `$hidden` do modelo é a segunda rede, caso alguém chegue a serializar a
 * linha inteira.
 *
 * A resposta não fixa código de status: a resource é devolvida tanto pela
 * leitura, que é `200` por ser leitura, quanto pela gravação, que fixa `200`
 * no controller porque a primeira gravação é criação de uma credencial única e
 * não de um recurso novo.
 *
 * @mixin SerproConnection
 */
class SerproConnectionResource extends JsonResource
{
    /**
     * A credencial ainda não foi gravada: a mesma tela, sem identidade.
     */
    public static function unconfigured(): self
    {
        return new self(new SerproConnection);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'configured' => $this->isConfigured(),
            'consumer_key_hint' => $this->keyHint(),
            'contracting_document' => $this->contratante_numero,
            'certificate_subject' => $this->certificate_subject,
            'certificate_serial' => $this->certificate_serial_number,
            'certificate_not_before' => $this->certificate_valid_from?->toISOString(),
            'certificate_not_after' => $this->certificate_valid_until?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * A chave é pública no protocolo (ela viaja no `Authorization: Basic` ao
     * lado do segredo), mas mesmo assim é a pista que permite reconhecer qual
     * chave está gravada sem devolvê-la: a tela precisa da pista para que quem
     * rotaciona por perda reconheça o que está trocando.
     */
    private function keyHint(): ?string
    {
        $key = (string) $this->consumer_key;

        if ($key === '') {
            return null;
        }

        // Uma chave mais curta que a máscara não tem "final" a revelar: sem
        // esta guarda, `substr` devolveria a chave inteira.
        $tail = strlen($key) > 8 ? substr($key, -4) : '';

        return str_repeat('•', 4).$tail;
    }
}
