<?php

namespace App\Http\Resources;

use App\Models\FiscalDocument;
use App\Services\Fiscal\Read\FiscalCoverage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A linha da tabela de documentos capturados.
 *
 * Allowlist explícita: o que a tela não lista não é escrito, e não é
 * "escrito e omitido depois". O que fica de fora por escolha são `nsu` (a
 * posição interna da distribuição), `source`, `event_id` e `evento_ocorrido_em_at`
 * (a linha do tempo é do detalhe), `sha256` e `digval` (digest de integridade,
 * que só o detalhe compara) e, acima de tudo, `storage_path`: o caminho do XML
 * no disco privado é o segredo desta tabela, e uma linha de histórico não é o
 * lugar para ele aparecer.
 *
 * `event_count` é preenchido pelo serviço de leitura antes de a linha chegar
 * aqui — ver `FiscalDocument::event_count`.
 *
 * @mixin FiscalDocument
 */
class FiscalDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'client' => [
                'id' => $this->client?->getKey(),
                'name' => $this->client?->name,
                'tax_id' => $this->client?->tax_id,
            ],
            'model' => $this->model?->value,
            'kind' => $this->kind?->value,
            'stage' => $this->stage?->value,
            'chave_acesso' => $this->chave_acesso,
            'emitente_cnpj' => $this->emitente_cnpj,
            'destinatario_cnpj' => $this->destinatario_cnpj,
            // Decimal do modelo, e não número: o valor é o que o fisco mandou,
            // e um float não representa `0.01` exatamente.
            'valor_total' => $this->valor_total,
            'emissao_at' => $this->emissao_at?->toISOString(),
            // O número e a série do XML (`ide/nNF`, `ide/serie`): nulos no
            // documento antigo, que mostra traço — nunca um número inventado.
            'numero' => $this->numero,
            'serie' => $this->serie,
            // Derivada e sem coluna: `cancelada` quando a linha do tempo tem um
            // evento `110111`, `autorizada` quando chegou o documento completo,
            // `resumo` quando só o resumo chegou. Nula na linha de evento.
            'situacao' => $this->situacao,
            // `YYYY-MM` da emissão, nulo fora da série — o mesmo contrato de
            // `over_time` da cobertura: sem mês, não há mês a atribuir.
            'competencia' => $this->emissao_at?->format('Y-m'),
            'event_count' => $this->event_count,
            'mascarado' => $this->mascarado,
            // O estado do A1 do cliente, na mesma palavra que a cobertura usa
            // — é o que explica a linha ter parado de receber documento.
            'client_certificate_status' => $this->client === null
                ? null
                : resolve(FiscalCoverage::class)->certificateStatus($this->client),
            // Terceiro estado preservado: `null` é "a outra etapa ainda não
            // chegou", e não um digest que não confere.
            'digval_confere' => $this->digval_confere,
        ];
    }
}
