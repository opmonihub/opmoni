<?php

namespace App\Http\Resources;

use App\Models\FiscalDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * O detalhe de um documento: a linha da lista mais o que só o detalhe tem.
 *
 * A linha vem do `FiscalDocumentResource` em vez de ser reescrita aqui — a
 * allowlist da tabela continua sendo a base, e o que a tela de detalhe mostra a
 * mais não pode divergir do que a lista mostra.
 *
 * O que entra a mais é o que a lista deixou de fora *por decisão*: `nsu` e
 * `schema` (a posição interna da distribuição e o tipo do XML), `event_id` e
 * `evento_ocorrido_em_at` (que são a linha do tempo), `sha256` e `digval` (o
 * digest de integridade, que é o que se compara aqui) e `xml_bytes`.
 *
 * O que continua fora, e é o mais importante: `storage_path`. O caminho do XML
 * no disco privado é o segredo desta seção, e nem uma resposta do tamanho do
 * detalhe o traz — o arquivo se baixa por outra porta, autenticada e com nome
 * de arquivo, e a prévia é texto com teto de bytes.
 *
 * @mixin FiscalDocument
 */
class FiscalDocumentDetailResource extends FiscalDocumentResource
{
    /**
     * A linha do tempo da chave de acesso, preenchida pelo controller com a
     * mesma consulta que preenche `event_count` — o número e a lista não podem
     * discordar entre si nem com a tabela.
     *
     * @var Collection<int, FiscalDocument>|null
     */
    public ?Collection $events = null;

    /**
     * O texto do XML para exibição, ou `null` quando não há prévia: o arquivo
     * não está no disco, ou o byte gravado é de uma codificação que a projeção
     * de exibição recusa em vez de adivinhar.
     */
    public ?string $xml_preview = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'source' => $this->source?->value,
            'nsu' => $this->nsu,
            'event_id' => $this->event_id,
            'schema' => $this->schema,
            'sha256' => $this->sha256,
            'digval' => $this->digval,
            'xml_bytes' => $this->xml_bytes,
            'captured_at' => $this->captured_at?->toISOString(),
            'evento_ocorrido_em_at' => $this->evento_ocorrido_em_at?->toISOString(),
            'events' => $this->events?->map(fn (FiscalDocument $evento): array => [
                'id' => $evento->getKey(),
                // O código do evento do fisco é o que dá sentido à linha; o id
                // do banco só liga a linha ao seu XML.
                'event_id' => $evento->event_id,
                'evento_ocorrido_em_at' => $evento->evento_ocorrido_em_at?->toISOString(),
                // A diferença entre o instante do evento e o da captura é o que
                // diz se a carteira estava rodando naquele dia.
                'captured_at' => $evento->captured_at?->toISOString(),
                'mascarado' => $evento->mascarado,
            ])->all() ?? [],
            'xml_preview' => $this->xml_preview,
        ]);
    }
}
