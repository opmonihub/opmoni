<?php

namespace App\Services;

use App\Enums\SerproFailure;

/**
 * A sequência documentada do SITFIS: protocolo gratuito e emissão cobrada.
 *
 * Encaixa no pipeline **dentro do closure** que o `SerproCallRecorder` já
 * envolve para a obrigação `RELATORIOSITFIS92`: uma entrega do job continua
 * valendo uma projeção, mas o closure dispara duas rotas (`Apoiar` e `Emitir`)
 * e faz polling no `202`/`204`/`304` antes de devolver o `SerproResult` final.
 * O passo `SOLICITARPROTOCOLO91` é auditado à parte; tentativas intermediárias
 * de emissão não viram linha em `serpro_calls`, para o checkpoint `called()`
 * não marcar a obrigação como concluída antes do PDF.
 */
final class SerproSitfisSequence
{
    /** Tentativas de emissão dentro do timeout do job (75s). */
    private const MAX_TENTATIVAS_EMITIR = 12;

    /** Teto de espera entre tentativas — o provedor manda ms, não segundos. */
    private const ESPERA_MAX_MS = 8_000;

    public function __construct(
        private SerproClient $client,
        private SerproCallRecorder $recorder,
    ) {}

    public function fetch(
        ?int $runId,
        int $accountId,
        int $clientId,
        string $autor,
        string $contribuinte,
        string $procuradorToken,
    ): SerproResult {
        $protocolo = $this->solicitarProtocolo($runId, $accountId, $clientId, $autor, $contribuinte, $procuradorToken);

        return $this->emitirRelatorio(
            $runId,
            $accountId,
            $clientId,
            $autor,
            $contribuinte,
            $procuradorToken,
            $protocolo,
        );
    }

    private function solicitarProtocolo(
        ?int $runId,
        int $accountId,
        int $clientId,
        string $autor,
        string $contribuinte,
        string $procuradorToken,
    ): string {
        $result = $this->recorder->record(
            $runId,
            $accountId,
            $clientId,
            'SITFIS',
            'SOLICITARPROTOCOLO91',
            fn (): SerproResult => $this->client->call(
                'SITFIS',
                'SOLICITARPROTOCOLO91',
                [],
                $autor,
                $contribuinte,
                $procuradorToken,
            ),
        );

        $dados = is_array($result->dados()) ? $result->dados() : [];
        $protocolo = trim((string) ($dados['protocoloRelatorio'] ?? ''));

        if ($protocolo === '') {
            throw new SerproException(
                'O Integra Contador não devolveu protocolo do relatório fiscal.',
                SerproFailure::DoNotRetry,
                $result->status(),
                $result->mensagens()[0]['codigo'] ?? null,
                $result->responseId(),
                $result->requestTag(),
            );
        }

        $espera = (int) ($dados['tempoEspera'] ?? 0);
        $this->aguardarMs($espera > 0 ? $espera : 1_000);

        return $protocolo;
    }

    private function emitirRelatorio(
        ?int $runId,
        int $accountId,
        int $clientId,
        string $autor,
        string $contribuinte,
        string $procuradorToken,
        string $protocolo,
    ): SerproResult {
        $ultimaExcecao = null;

        for ($tentativa = 0; $tentativa < self::MAX_TENTATIVAS_EMITIR; $tentativa++) {
            try {
                $result = $this->client->call(
                    'SITFIS',
                    'RELATORIOSITFIS92',
                    ['protocoloRelatorio' => $protocolo],
                    $autor,
                    $contribuinte,
                    $procuradorToken,
                    $tentativa + 2,
                );
            } catch (SerproException $exception) {
                if (in_array($exception->status, [204, 304], true)) {
                    $this->aguardarMs(self::ESPERA_MAX_MS);
                    $ultimaExcecao = $exception;

                    continue;
                }

                $this->recorder->record(
                    $runId,
                    $accountId,
                    $clientId,
                    'SITFIS',
                    'RELATORIOSITFIS92',
                    fn (): SerproResult => throw $exception,
                );

                throw $exception;
            }

            if ($result->status() === 202) {
                $dados = is_array($result->dados()) ? $result->dados() : [];
                $this->aguardarMs((int) ($dados['tempoEspera'] ?? self::ESPERA_MAX_MS));

                continue;
            }

            $dados = is_array($result->dados()) ? $result->dados() : [];
            $pdf = (string) ($dados['pdf'] ?? '');

            if ($pdf === '') {
                throw new SerproException(
                    'O Integra Contador respondeu sem PDF do relatório fiscal.',
                    SerproFailure::DoNotRetry,
                    $result->status(),
                    $result->mensagens()[0]['codigo'] ?? null,
                    $result->responseId(),
                    $result->requestTag(),
                );
            }

            return $this->recorder->record(
                $runId,
                $accountId,
                $clientId,
                'SITFIS',
                'RELATORIOSITFIS92',
                fn (): SerproResult => $result,
            );
        }

        if ($ultimaExcecao !== null) {
            throw $ultimaExcecao;
        }

        throw new SerproException(
            'O relatório fiscal segue em processamento após o limite de tentativas.',
            SerproFailure::Upstream,
            503,
        );
    }

    private function aguardarMs(int $milissegundos): void
    {
        $milissegundos = max(0, min($milissegundos, self::ESPERA_MAX_MS));

        if ($milissegundos === 0) {
            return;
        }

        usleep($milissegundos * 1000);
    }
}
