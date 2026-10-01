<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A linha do agregado fiscal por cliente: uma linha por cliente com os totais
 * que o cartão mostra.
 *
 * Allowlist explícita, como na linha de documento: o que a tela não lista não
 * é escrito. Não há campo de credencial aqui — o estado do A1 sai como a
 * palavra da escala (`missing`/`expired`/`password_missing`/`expiring`/`valid`),
 * que é a mesma que a cobertura usa, e `storage_path` continua fora de todo
 * Resource.
 *
 * @mixin array
 */
class FiscalClientSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $linha = (array) $this->resource;

        return [
            'client' => [
                'id' => $linha['client']['id'],
                'name' => $linha['client']['name'],
                'tax_id' => $linha['client']['tax_id'],
            ],
            'total' => $linha['total'],
            'saidas' => [
                'qtd' => $linha['saidas']['qtd'],
                // Decimal como texto, como o `valor_total` da linha de
                // documento: o valor é o que o fisco mandou, e um float não
                // representa `0.01` exatamente.
                'valor' => $linha['saidas']['valor'],
            ],
            'entradas' => [
                'qtd' => $linha['entradas']['qtd'],
                'valor' => $linha['entradas']['valor'],
            ],
            'por_modelo' => $linha['por_modelo'],
            'ultima_emissao_at' => $linha['ultima_emissao_at'],
            'certificado_status' => $linha['certificado_status'],
        ];
    }
}
