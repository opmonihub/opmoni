<?php

namespace App\Services\Fiscal\Read;

use App\Enums\FiscalKind;
use App\Enums\FiscalStage;
use App\Models\Client;
use App\Models\FiscalDocument;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * O agregado fiscal por cliente: uma linha por cliente com totais, direção,
 * volume por modelo, última emissão e estado do A1.
 *
 * A conta vem por parâmetro e nunca do singleton, pelo mesmo motivo de
 * `FiscalDocuments` e `FiscalCoverage`: o escopo global do modelo só filtra
 * com conta corrente, e uma leitura que muda de significado conforme quem
 * chamou não é leitura.
 *
 * A direção sai do cruzamento de CNPJ no SQL: `emitente_cnpj` igual aos
 * dígitos do `tax_id` do cliente é saída, o resto é entrada — inclusive
 * `NULL`, que é a nota de entrada cujo emitente a distribuição não trouxe.
 * Como a mesma consulta agrega vários clientes, o `CASE` compara contra a
 * coluna `clients.tax_id` normalizada no banco, e não contra um valor fixo.
 *
 * Cada chave do cliente entra uma vez: o documento completo prevalece sobre
 * o resumo, e o resumo permanece enquanto o completo não chegou. Eventos
 * são entregas da mesma chave e não entram no total.
 */
class FiscalClients
{
    /**
     * @param  array<string, mixed>  $filters  Só `model`, `issued_from` e
     *                                         `issued_to`, validados pelo
     *                                         `IndexFiscalClientsRequest`.
     * @return list<array{client: array{id: int, name: string, tax_id: ?string}, total: int, saidas: array{qtd: int, valor: string}, entradas: array{qtd: int, valor: string}, por_modelo: array<string, int>, ultima_emissao_at: ?string, certificado_status: string}>
     */
    public function summary(int $accountId, array $filters): array
    {
        $clientes = Client::query()
            ->where('account_id', $accountId)
            ->whereIn('id', $this->clientesComDocumento($accountId, $filters))
            ->with(['currentCertificate'])
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        if ($clientes->isEmpty()) {
            return [];
        }

        $agregados = $this->agregados($accountId, $clientes->all(), $filters);
        $porModelo = $this->porModelo($accountId, $clientes->all(), $filters);
        $cobertura = resolve(FiscalCoverage::class);

        $linhas = [];

        foreach ($clientes as $cliente) {
            $agregado = $agregados[$cliente->getKey()] ?? null;

            if ($agregado === null) {
                continue;
            }

            $linhas[] = [
                'client' => [
                    'id' => $cliente->getKey(),
                    'name' => $cliente->name,
                    'tax_id' => $cliente->tax_id,
                ],
                'total' => (int) $agregado->total,
                'saidas' => [
                    'qtd' => (int) $agregado->saidas_qtd,
                    'valor' => number_format((float) $agregado->saidas_valor, 2, '.', ''),
                ],
                'entradas' => [
                    'qtd' => (int) $agregado->entradas_qtd,
                    'valor' => number_format((float) $agregado->entradas_valor, 2, '.', ''),
                ],
                'por_modelo' => $porModelo[$cliente->getKey()] ?? [],
                // ISO-8601 com fuso, como `emissao_at` da linha de documento:
                // o agregado sai do `MAX()` cru do banco, e sem a conversão a
                // resposta carregaria o formato do dialeto.
                'ultima_emissao_at' => $agregado->ultima_emissao_at === null
                    ? null
                    : CarbonImmutable::parse($agregado->ultima_emissao_at)->toISOString(),
                'certificado_status' => $cobertura->certificateStatus($cliente),
            ];
        }

        return $linhas;
    }

    /**
     * Os clientes da conta que têm documento sob os filtros — que são os que
     * entram no agregado. O cliente removido não entra: `Client::query()` já
     * exclui os apagados, e o agregado conta a carteira, não o histórico.
     *
     * @return list<int>
     */
    private function clientesComDocumento(int $accountId, array $filters): array
    {
        return $this->documentos($accountId, $filters)
            ->distinct()
            ->toBase()
            ->pluck('client_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * O agregado por cliente: total, direção com `CASE` de CNPJ e a última
     * emissão. Uma consulta só, sem N+1.
     *
     * @param  list<Client>  $clientes
     * @return array<int, mixed>
     */
    private function agregados(int $accountId, array $clientes, array $filters): array
    {
        $ids = array_map(fn (Client $cliente): int => (int) $cliente->getKey(), $clientes);

        // Só-dígitos dos dois lados: o `tax_id` do cliente pode vir com
        // máscara (`11.222.333/0001-81`), e o `emitente_cnpj` da distribuição
        // é só dígito. No SQLite e no Postgres a remoção é por função
        // diferente, e é por isso que a expressão vem por driver.
        $driver = FiscalDocument::query()->getConnection()->getDriverName();
        $digitosTaxId = match ($driver) {
            'pgsql' => "regexp_replace(clients.tax_id, '[^0-9]', '', 'g')",
            'mysql' => "regexp_replace(clients.tax_id, '[^0-9]', '')",
            default => "replace(replace(replace(replace(clients.tax_id, '.', ''), '-', ''), '/', ''), ' ', '')",
        };

        $documentos = FiscalDocument::query()->getModel()->getTable();

        $linhas = $this->documentos($accountId, $filters)
            ->whereIn("{$documentos}.client_id", $ids)
            ->join('clients', 'clients.id', '=', "{$documentos}.client_id")
            ->selectRaw("{$documentos}.client_id as client_id, count(*) as total")
            ->selectRaw("sum(case when {$documentos}.emitente_cnpj is not null and {$documentos}.emitente_cnpj = {$digitosTaxId} then 1 else 0 end) as saidas_qtd")
            ->selectRaw("sum(case when {$documentos}.emitente_cnpj is not null and {$documentos}.emitente_cnpj = {$digitosTaxId} then coalesce({$documentos}.valor_total, 0) else 0 end) as saidas_valor")
            ->selectRaw("sum(case when {$documentos}.emitente_cnpj is null or {$documentos}.emitente_cnpj != {$digitosTaxId} then 1 else 0 end) as entradas_qtd")
            ->selectRaw("sum(case when {$documentos}.emitente_cnpj is null or {$documentos}.emitente_cnpj != {$digitosTaxId} then coalesce({$documentos}.valor_total, 0) else 0 end) as entradas_valor")
            ->selectRaw("max({$documentos}.emissao_at) as ultima_emissao_at")
            ->groupBy("{$documentos}.client_id")
            ->toBase()
            ->get();

        $agregados = [];

        foreach ($linhas as $linha) {
            $agregados[(int) $linha->client_id] = $linha;
        }

        return $agregados;
    }

    /**
     * O volume por modelo de cada cliente, na mesma consulta filtrada — sem
     * N+1. Só entra o modelo que tem documento: um modelo ausente do mapa é
     * "nada capturado desse modelo ainda", como em `FiscalCoverage`.
     *
     * @param  list<Client>  $clientes
     * @return array<int, array<string, int>>
     */
    private function porModelo(int $accountId, array $clientes, array $filters): array
    {
        $ids = array_map(fn (Client $cliente): int => (int) $cliente->getKey(), $clientes);

        $linhas = $this->documentos($accountId, $filters)
            ->whereIn('fiscal_documents.client_id', $ids)
            ->selectRaw('fiscal_documents.client_id, fiscal_documents.model, count(*) as total')
            ->groupBy('fiscal_documents.client_id', 'fiscal_documents.model')
            ->orderBy('fiscal_documents.client_id')
            ->orderBy('fiscal_documents.model')
            ->toBase()
            ->get();

        $porModelo = [];

        foreach ($linhas as $linha) {
            $porModelo[(int) $linha->client_id][(string) $linha->model] = (int) $linha->total;
        }

        return $porModelo;
    }

    /**
     * Os documentos da conta sob os filtros do agregado, sem cliente e sem
     * página: `model` e o período de emissão, com as mesmas chaves da tabela
     * de documentos. Eventos ficam de fora — o agregado conta documentos,
     * e o evento é entrega da mesma chave, não documento novo.
     *
     * A escolha do completo acontece antes dos filtros: um completo fora do
     * período não faz o resumo voltar a ser contado dentro dele.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<FiscalDocument>
     */
    private function documentos(int $accountId, array $filters): Builder
    {
        $tabela = (new FiscalDocument)->getTable();

        return FiscalDocument::query()
            ->where("{$tabela}.account_id", $accountId)
            ->where("{$tabela}.kind", FiscalKind::Document->value)
            ->where(function (Builder $query) use ($tabela): void {
                $query->where("{$tabela}.stage", FiscalStage::Document->value)
                    ->orWhere(function (Builder $summaries) use ($tabela): void {
                        $summaries->where("{$tabela}.stage", FiscalStage::Summary->value)
                            ->whereNotExists(function (QueryBuilder $completeDocuments) use ($tabela): void {
                                $completeDocuments->selectRaw('1')
                                    ->from("{$tabela} as full_documents")
                                    ->whereColumn('full_documents.account_id', "{$tabela}.account_id")
                                    ->whereColumn('full_documents.client_id', "{$tabela}.client_id")
                                    ->whereColumn('full_documents.chave_acesso', "{$tabela}.chave_acesso")
                                    ->where('full_documents.kind', FiscalKind::Document->value)
                                    ->where('full_documents.stage', FiscalStage::Document->value);
                            });
                    });
            })
            ->when(
                $filters['model'] ?? null,
                fn (Builder $query, array $models): Builder => $query->whereIn("{$tabela}.model", $models)
            )
            ->when(
                isset($filters['issued_from']),
                fn (Builder $query): Builder => $query->whereDate("{$tabela}.emissao_at", '>=', $filters['issued_from'])
            )
            ->when(
                isset($filters['issued_to']),
                fn (Builder $query): Builder => $query->whereDate("{$tabela}.emissao_at", '<=', $filters['issued_to'])
            );
    }
}
