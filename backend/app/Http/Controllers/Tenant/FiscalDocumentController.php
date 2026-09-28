<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\FiscalSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\IndexFiscalDocumentRequest;
use App\Http\Resources\FiscalDocumentDetailResource;
use App\Http\Resources\FiscalDocumentResource;
use App\Jobs\CaptureFiscalDocumentsJob;
use App\Models\Client;
use App\Models\FiscalCursor;
use App\Models\FiscalDocument;
use App\Services\Fiscal\Read\FiscalCoverage;
use App\Services\Fiscal\Read\FiscalDocuments;
use App\Services\Fiscal\Support\FiscalXmlEncoding;
use App\Services\SupportAudit;
use App\Tenant\CurrentTenant;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FiscalDocumentController extends Controller
{
    /**
     * O teto da prévia, em bytes, e não em caracteres: `mb_substr` cortaria em
     * caractere e devolveria até o dobro do teto num documento com acento, e a
     * resposta cresceria junto com o arquivo. `mb_strcut` corta em byte sem
     * quebrar um caractere multibyte no meio.
     *
     * O teto existe para que um documento hostil não vire payload: a prévia é
     * texto que o front renderiza como texto, e 4 KiB é o suficiente para o
     * operador reconhecer a nota sem levar o XML inteiro para a tela.
     */
    private const PREVIA_BYTES = 4096;

    /**
     * Como a captura da conta está indo: cobertura da carteira, o que precisa de
     * alguém e o que o último lote deixou.
     *
     * A resposta sai do serviço de leitura já no formato do painel. Não há
     * Resource aqui de propósito nesta primeira entrega: o resumo não é uma
     * linha de tabela com allowlist de colunas, e os campos que ele declara
     * são uma lista fechada de agregados — número, palavra de motivo e instante
     * — onde o segredo não está em esquecer de tirar um campo, e sim em não
     * haver campo de credencial para tirar.
     */
    public function summary(FiscalCoverage $coverage): JsonResponse
    {
        Gate::authorize('viewAny', FiscalDocument::class);

        return response()->json([
            'data' => $coverage->summary((int) resolve(CurrentTenant::class)->accountId),
        ]);
    }

    /**
     * A tabela única de documentos capturados, filtrada e paginada.
     *
     * A autorização mora no `IndexFiscalDocumentRequest`, que é o que dá
     * entrada na ação — ler a captura é leitura de carteira, aberta a qualquer
     * membro da conta (`FiscalDocumentPolicy::viewAny`). A página e a lista de
     * modelos saem do mesmo serviço e da mesma consulta filtrada, e o Resource
     * é quem decide a forma da linha.
     */
    public function index(IndexFiscalDocumentRequest $request, FiscalDocuments $documents): AnonymousResourceCollection
    {
        $page = $documents->page(
            (int) resolve(CurrentTenant::class)->accountId,
            $request->validated(),
        );

        return FiscalDocumentResource::collection($page['rows'])
            ->additional(['available_models' => $page['available_models']]);
    }

    /**
     * O detalhe de um documento: a linha, a linha do tempo e a prévia.
     *
     * A conta vem por parâmetro nos dois serviços e não do singleton, pelo
     * mesmo motivo da lista: o escopo global só existe com conta corrente, e
     * uma leitura que muda de significado conforme quem chamou não é leitura.
     *
     * O arquivo é lido depois da autorização e só para a prévia: o disco
     * privado nunca é tocado para montar a resposta de quem não pode ler.
     */
    public function show(FiscalDocument $fiscalDocument, FiscalDocuments $documents, FiscalXmlEncoding $encoding): FiscalDocumentDetailResource
    {
        Gate::authorize('view', $fiscalDocument);

        $accountId = (int) resolve(CurrentTenant::class)->accountId;
        $eventos = $documents->eventsOf($accountId, $fiscalDocument);

        // O número vem da lista, não de uma contagem própria: a linha do tempo
        // e o `event_count` da tabela respondem a mesma pergunta, e um `-1`
        // aqui colocaria dois números na mesma tela para a mesma chave.
        $fiscalDocument->event_count = $eventos->count();

        $resource = new FiscalDocumentDetailResource($fiscalDocument->load('client'));
        $resource->events = $eventos;
        $resource->xml_preview = $this->previa($fiscalDocument, $encoding);

        return $resource;
    }

    /**
     * O XML gravado, como arquivo.
     *
     * O byte vai cru: quem confere o documento com o fisco não pode receber um
     * texto reescrito, e a conversão de exibição que a prévia usa não é a
     * conversão de um documento fiscal. O nome do arquivo é a chave de acesso,
     * e o caminho interno nunca sai daqui — é a resposta que o `Content-Type`
     * e o `Content-Disposition` definem, não o JSON.
     */
    public function download(FiscalDocument $fiscalDocument): StreamedResponse
    {
        Gate::authorize('view', $fiscalDocument);

        $disco = Storage::disk('fiscal');

        // A linha existe e o byte não são fatos diferentes: responder 500 seria
        // a exception do disco, e responder 200 vazio ensinaria ao operador que
        // o documento está íntegro quando o arquivo sumiu.
        if ($disco->missing((string) $fiscalDocument->storage_path)) {
            abort(404, 'Arquivo XML não encontrado para este documento.');
        }

        return $disco->download(
            (string) $fiscalDocument->storage_path,
            $fiscalDocument->chave_acesso.'.xml',
            ['Content-Type' => 'application/xml'],
        );
    }

    /**
     * Enfileira a captura de um cliente e devolve o aceite da fila.
     *
     * Não é a captura: é o despacho. A resposta é 202 com o cliente e a
     * espera, sem posição e sem estado — a posição só existe depois que o fisco
     * devolveu, e devolvê-la aqui seria afirmar que a consulta aconteceu. Em
     * produção a fila é o Redis, e o job é quem fala com o SEFAZ: esperar pela
     * resposta seguraria a requisição HTTP pelo timeout do conector e faria
     * esta tela dar timeout justamente no cliente que demora.
     *
     * O bloqueio é recusado antes do despacho, e pelo mesmo motivo do job: um
     * job na fila para um cliente que o fisco tem parado só gastaria a
     * posição da consulta e voltaria ao painel como "nada capturado".
     */
    public function capture(Request $request, Client $client): JsonResponse
    {
        Gate::authorize('capture', [FiscalDocument::class, $client]);

        $validated = $request->validate([
            'source' => ['sometimes', Rule::in(array_column(FiscalSource::cases(), 'value'))],
        ]);
        $source = FiscalSource::from($validated['source'] ?? FiscalSource::NfeDistribuicao->value);

        $bloqueio = $this->bloqueioDe($client, $source);

        if ($bloqueio !== null) {
            return response()->json([
                'message' => 'O fisco tem este cliente em espera. A captura pode ser tentada de novo depois do horário informado.',
                'blocked_until' => $bloqueio->toISOString(),
            ], 409);
        }

        CaptureFiscalDocumentsJob::dispatch((int) $client->getKey(), $source);

        // A escrita de suporte é registrada depois do despacho e com o mínimo:
        // o id do cliente e a fonte. O material do cofre do cliente não entra
        // numa auditoria.
        SupportAudit::logWrite($request, 'fiscal', 'capture', (int) $client->getKey(), ['source' => $source->value]);

        return response()->json([
            'data' => ['queued' => true, 'client_id' => $client->getKey()],
        ], 202);
    }

    /**
     * O cursor parado daquele par cliente e fonte, ou `null`.
     *
     * A parada é por fonte — o bloqueio do CT-e não impede a consulta de NF-e —
     * e por tempo: `blocked_until` no passado já passou, e um bloqueio vencido
     * com a marca de consumo indevido é histórico, não recusa. A condição é a
     * mesma de `FiscalCursor::isBlocked()`, que é a que o job aplicaria de
     * qualquer jeito: recusar o que o job recusaria é o que evita a captura
     * enfileirada que não faz nada.
     */
    private function bloqueioDe(Client $client, FiscalSource $source): ?CarbonInterface
    {
        $cursor = FiscalCursor::query()
            ->where('account_id', (int) resolve(CurrentTenant::class)->accountId)
            ->where('client_id', (int) $client->getKey())
            ->where('source', $source->value)
            ->first();

        return $cursor?->isBlocked() === true ? $cursor->blocked_until : null;
    }

    /**
     * A prévia do XML gravado, ou `null` quando não há o que mostrar.
     *
     * Dois motivos levam a `null` e nenhum dos dois derruba o detalhe: o arquivo
     * não está no disco, e o byte é de uma codificação que a projeção de
     * exibição recusa. Converter por heurística troca cada byte ímpar por `?` e
     * mostra um documento fiscal adulterado sem erro visível, então a recusa é
     * do decodificador e o download — que serve o byte cru — segue servindo.
     *
     * O log da recusa carrega o nome da classe e nada mais: nem o byte, nem a
     * frase da exceção, pelo mesmo motivo de nunca logar XML.
     */
    private function previa(FiscalDocument $documento, FiscalXmlEncoding $encoding): ?string
    {
        $caminho = (string) $documento->storage_path;

        if ($caminho === '' || Storage::disk('fiscal')->missing($caminho)) {
            return null;
        }

        try {
            $projetado = $encoding->forDisplay(Storage::disk('fiscal')->get($caminho));
        } catch (RuntimeException $recusa) {
            Log::warning('fiscal.leitura.previa_recusada', [
                'account_id' => (int) $documento->account_id,
                'client_id' => (int) $documento->client_id,
                'chave_acesso' => $documento->chave_acesso,
                'reason' => class_basename($recusa),
            ]);

            return null;
        }

        return mb_strcut($projetado, 0, self::PREVIA_BYTES, 'UTF-8');
    }
}
