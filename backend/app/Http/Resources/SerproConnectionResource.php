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
        // Os metadados do certificado contratante vêm de duas fontes possíveis:
        // as colunas da própria credencial, quando ela guarda o PFX, e a linha
        // corrente de `account_certificates` da conta apontada, quando ela
        // reusa o e-CNPJ do escritório. A tela lê uma coisa só — quem assina e
        // até quando — e a fonte é um detalhe que ela não precisa.
        $reused = $this->contractingCertificate();

        return [
            'configured' => $this->isConfigured(),
            'consumer_key_hint' => $this->keyHint(),
            'contracting_document' => $this->contratante_numero,
            'certificate_subject' => $reused?->subject ?? $this->certificate_subject,
            'certificate_serial' => $reused?->serial_number ?? $this->certificate_serial_number,
            'certificate_not_before' => ($reused?->valid_from ?? $this->certificate_valid_from)?->toISOString(),
            'certificate_not_after' => ($reused?->valid_until ?? $this->certificate_valid_until)?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /**
    /**
     * A chave é pública no protocolo (ela viaja no `Authorization: Basic` ao
     * lado do segredo), mas mesmo assim é a pista que permite reconhecer qual
     * chave está gravada sem devolvê-la: a tela precisa da pista para que quem
     * rotaciona por perda reconheça o que está trocando.
     *
     * A pista **tem** de ser curta em relação à chave, e o limite de 16 é
     * arbitrário o bastante para dizer isso: a chave real do SERPRO é longa, e
     * nesse caso a pista é quatro caracteres de dozens. O que a guarda evita é o
     * outro extremo — uma chave de teste, ou uma chave de um ambiente de
     * homologação com poucos caracteres, onde `substr($key, -4)` seria quase a
     * chave inteira. Abaixo de 16, a pista é só a máscara: o operador não
     * reconhece qual chave é, e a tela ainda não diz que há uma.
     */
    private function keyHint(): ?string
    {
        $key = (string) $this->consumer_key;

        if ($key === '') {
            return null;
        }

        // Uma chave mais curta que a máscara não tem "final" a revelar: sem
        // esta guarda, `substr` devolveria a chave inteira.
        $tail = strlen($key) >= 16 ? substr($key, -4) : '';

        return str_repeat('•', 4).$tail;
    }
}
