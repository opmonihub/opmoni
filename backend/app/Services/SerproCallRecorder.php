<?php

namespace App\Services;

use App\Enums\SerproFailure;
use App\Models\SerproCall;
use Closure;

/**
 * O único caminho que transforma uma chamada em linha de auditoria.
 *
 * Toda chamada — sucesso ou falha que tenha saído do cliente — ganha uma
 * linha em `serpro_calls`, porque é essa tabela que responde "esta chamada
 * já foi feita nesta execução?" para a idempotência do job e "quanto o
 * provedor cobrou?" para a reconciliação. O recorder cronometra, persiste e
 * devolve o resultado; a exceção é registrada do mesmo jeito e segue subindo
 * para quem sabe classificá-la.
 *
 * O que **não** é gravado: `dados` (o payload é documento do cliente e já
 * tem onde morar), o texto livre de `mensagens` é truncado em 200 caracteres
 * porque sobe para a tela, e nenhum segredo chega aqui — o recorder só vê o
 * resultado e a exceção.
 */
final class SerproCallRecorder
{
    /**
     * @param  Closure(): SerproResult  $call
     *
     * @throws SerproException a exceção da chamada, depois de registrada.
     */
    public function record(
        int $runId,
        int $accountId,
        ?int $clientId,
        string $idSistema,
        string $idServico,
        Closure $call,
    ): SerproResult {
        $started = microtime(true);

        try {
            $result = $call();

            $this->persist($runId, $accountId, $clientId, $idSistema, $idServico, [
                'status' => SerproFailure::Success,
                'provider_code' => $result->mensagens()[0]['codigo'] ?? null,
                'response_id' => $result->responseId(),
                'request_tag' => $result->requestTag(),
                'messages' => $this->sanitize($result->mensagens()),
            ], $started);

            return $result;
        } catch (SerproException $exception) {
            $this->persist($runId, $accountId, $clientId, $idSistema, $idServico, [
                'status' => $exception->failure,
                'provider_code' => $exception->providerCode,
                'response_id' => $exception->responseId,
                'request_tag' => $exception->requestTag,
                'messages' => null,
            ], $started);

            throw $exception;
        }
    }

    /**
     * @param  array{status: SerproFailure, provider_code: ?string, response_id: ?string, request_tag: ?string, messages: ?array<int, array<string, mixed>>}  $outcome
     */
    private function persist(
        int $runId,
        int $accountId,
        ?int $clientId,
        string $idSistema,
        string $idServico,
        array $outcome,
        float $started,
    ): void {
        $service = config("integra-contador.services.{$idServico}", []);

        $call = new SerproCall;
        $call->forceFill([
            'account_id' => $accountId,
            'run_id' => $runId,
            'client_id' => $clientId,
            'id_sistema' => $idSistema,
            'id_servico' => $idServico,
            'version' => $service['versaoSistema'] ?? null,
            'path' => $service['path'] ?? null,
            'billable' => (bool) ($service['billable'] ?? true),
            'status' => $outcome['status'],
            'provider_code' => $outcome['provider_code'],
            'response_id' => $outcome['response_id'],
            'request_tag' => $outcome['request_tag'],
            'messages' => $outcome['messages'],
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);
        $call->save();
    }

    /**
     * O `texto` de sucesso do provedor é o que a tela de chamadas pode
     * mostrar — limitado a 200 caracteres pelo mesmo motivo do
     * `last_error` fiscal: um texto arbitrário não decide o layout.
     *
     * @param  list<array{codigo: string, texto: string}>  $mensagens
     * @return list<array{codigo: ?string, texto: ?string}>
     */
    private function sanitize(array $mensagens): array
    {
        return collect($mensagens)
            ->take(5)
            ->map(fn (array $mensagem): array => [
                'codigo' => isset($mensagem['codigo']) ? mb_substr((string) $mensagem['codigo'], 0, 80) : null,
                'texto' => isset($mensagem['texto']) ? mb_substr((string) $mensagem['texto'], 0, 200) : null,
            ])
            ->all();
    }
}
