<?php

namespace App\Http\Resources;

use App\Enums\SerproAuthorizationTermState;
use App\Models\SerproAuthorizationTerm;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * O que a tela do termo pode ver: quatro campos, e nenhum deles é material.
 *
 * **A lista é fechada e a ordem é a do ciclo de vida.** A spec nomeia
 * exatamente estes quatro — `state`, `expires_on`, `signed_at` e
 * `document_present` —, e nenhum outro tem o que a tela faça: o documento
 * assinado é documento jurídico, o token é a credencial de chamada do
 * escritório, e o texto cifrado das duas colunas não tem tradução para
 * usuário. O `#[Hidden]` do model é a segunda rede, caso alguém chegue a
 * serializar a linha inteira, que é o que faria um `return $term` em um
 * controller.
 *
 * **`document_present` afirma que existe documento, e não outra coisa.** A
 * coluna é `NOT NULL`, então o valor **é `true` em toda linha que existe** e
 * `false` só no caminho de recurso nulo — o que o torna redundante com
 * `state != ausente`. O campo fica porque a spec o nomeia e porque ele dá à
 * tela uma afirmação direta em vez de ela ter de inferir a existência do
 * documento a partir do estado; o que ele **não** distingue é "há documento mas
 * está ilegível" de "há documento e está legível", porque essa leitura
 * exigiria tentar decifrar numa tela de leitura, e decifrar é do
 * `SerproTermManager`.
 *
 * **Uma conta sem termo tem `state` = `ausente` e não um `404`.** A ausência
 * é um estado do produto e não um recurso inexistente: a tela precisa dele
 * para pedir o e-CNPJ, e um `404` seria indistinguível de rota errada.
 *
 * @mixin SerproAuthorizationTerm
 */
class SerproAuthorizationTermResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $term = $this->resource;

        if ($term === null) {
            return [
                'state' => SerproAuthorizationTermState::Ausente->value,
                'expires_on' => null,
                'signed_at' => null,
                'document_present' => false,
            ];
        }

        return [
            'state' => $term->state->value,
            'expires_on' => $term->document_expires_on?->toDateString(),
            'signed_at' => $term->signed_at?->toISOString(),
            'document_present' => $term->document_encrypted !== null,
        ];
    }
}
