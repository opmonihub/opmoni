<?php

namespace App\Services;

use App\Enums\SerproFailure;
use Closure;
use Illuminate\Support\Sleep;

/**
 * Protocolo gratuito e emissão cobrada compartilham o mesmo prazo por fetch.
 * Só o PDF conclui a chamada de emissão no checkpoint do recorder.
 */
final class SerproSitfisSequence
{
    private const MAX_TENTATIVAS_EMITIR = 12;

    private const MAX_TENTATIVAS_PROTOCOLO = 3;

    private const ESPERA_MAX_MS = 32_000;

    /** Margem de 5s em relação ao timeout de 75s do job. */
    private const ORCAMENTO_MS = 70_000;

    /** Reserva para processamento local além dos timeouts HTTP. */
    private const MARGEM_CHAMADA_MS = 1_000;

    /** Auth + serviço, repetidos uma vez se o serviço responder 401. */
    private const MAX_REQUESTS_POR_CHAMADA = 4;

    /** @param  ?Closure(): int  $clock Tempo monotônico em milissegundos. */
    public function __construct(
        private SerproClient $client,
        private SerproCallRecorder $recorder,
        private ?Closure $clock = null,
    ) {}

    public function fetch(
        ?int $runId,
        int $accountId,
        int $clientId,
        string $autor,
        string $contribuinte,
        string $procuradorToken,
    ): SerproResult {
        $prazo = $this->agoraMs() + self::ORCAMENTO_MS;
        $protocoloResultado = $this->solicitarProtocolo($runId, $accountId, $clientId, $autor, $contribuinte, $procuradorToken, $prazo);
        $dados = is_array($protocoloResultado->dados()) ? $protocoloResultado->dados() : [];
        $protocolo = trim((string) ($dados['protocoloRelatorio'] ?? ''));

        return $this->emitirRelatorio($runId, $accountId, $clientId, $autor, $contribuinte, $procuradorToken, $protocolo, $prazo);
    }

    private function solicitarProtocolo(
        ?int $runId,
        int $accountId,
        int $clientId,
        string $autor,
        string $contribuinte,
        string $procuradorToken,
        int $prazo,
    ): SerproResult {
        $pendente = null;

        for ($tentativa = 0; $tentativa < self::MAX_TENTATIVAS_PROTOCOLO; $tentativa++) {
            $timeout = $this->timeoutParaChamada($prazo, $pendente);
            $espera = self::ESPERA_MAX_MS;

            try {
                $result = $this->recorder->record(
                    $runId,
                    $accountId,
                    $clientId,
                    'SITFIS',
                    'SOLICITARPROTOCOLO91',
                    fn (): SerproResult => $this->chamarComTimeout($timeout, fn (): SerproResult => $this->client->call(
                        'SITFIS',
                        'SOLICITARPROTOCOLO91',
                        [],
                        $autor,
                        $contribuinte,
                        $procuradorToken,
                    )),
                );
            } catch (SerproException $exception) {
                if ($exception->status !== 503 || ! $this->respostaPendente($exception)) {
                    throw $exception;
                }

                $pendente = $this->pendente($exception);
                $result = null;
            }

            if ($result !== null) {
                $dados = is_array($result->dados()) ? $result->dados() : [];
                $protocolo = trim((string) ($dados['protocoloRelatorio'] ?? ''));
                $espera = (int) ($dados['tempoEspera'] ?? 0);
                $pendente = $this->pendente($result);

                if ($result->status() === 503 && ! $this->respostaPendente($result)) {
                    throw $pendente;
                }

                if ($protocolo !== '') {
                    $this->aguardarMs($espera > 0 ? $espera : 1_000, $prazo, $pendente);

                    return $result;
                }

                if ($espera <= 0 && $result->status() !== 503) {
                    throw new SerproException(
                        'O Integra Contador não devolveu protocolo do relatório fiscal.',
                        SerproFailure::DoNotRetry,
                        $result->status(),
                        $result->mensagens()[0]['codigo'] ?? null,
                        $result->responseId(),
                        $result->requestTag(),
                    );
                }

                $espera = $espera > 0 ? min($espera, self::ESPERA_MAX_MS) : self::ESPERA_MAX_MS;
            }

            if ($tentativa === self::MAX_TENTATIVAS_PROTOCOLO - 1) {
                throw $pendente;
            }

            // Fora do catch: esgotar o prazo não inicia outra espera de 503.
            $this->aguardarMs($espera, $prazo, $pendente);
        }

        throw $this->pendente();
    }

    private function emitirRelatorio(
        ?int $runId,
        int $accountId,
        int $clientId,
        string $autor,
        string $contribuinte,
        string $procuradorToken,
        string $protocolo,
        int $prazo,
    ): SerproResult {
        $pendente = null;

        for ($tentativa = 0; $tentativa < self::MAX_TENTATIVAS_EMITIR; $tentativa++) {
            $timeout = $this->timeoutParaChamada($prazo, $pendente);
            $espera = self::ESPERA_MAX_MS;

            try {
                $result = $this->chamarComTimeout($timeout, fn (): SerproResult => $this->client->call(
                    'SITFIS',
                    'RELATORIOSITFIS92',
                    ['protocoloRelatorio' => $protocolo],
                    $autor,
                    $contribuinte,
                    $procuradorToken,
                    $tentativa + 2,
                ));
            } catch (SerproException $exception) {
                if (! $this->respostaPendente($exception)) {
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

                $pendente = $this->pendente($exception);
                $result = null;
            }

            if ($result !== null) {
                $dados = is_array($result->dados()) ? $result->dados() : [];

                if (in_array($result->status(), [202, 204, 304, 503], true)) {
                    $espera = (int) ($dados['tempoEspera'] ?? self::ESPERA_MAX_MS);
                    $pendente = $this->pendente($result);

                    if (! $this->respostaPendente($result)) {
                        throw $pendente;
                    }
                } else {
                    if ((string) ($dados['pdf'] ?? '') === '') {
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
            }

            if ($tentativa === self::MAX_TENTATIVAS_EMITIR - 1) {
                throw $pendente;
            }

            $this->aguardarMs($espera, $prazo, $pendente);
        }

        throw $this->pendente();
    }

    private function aguardarMs(int $milissegundos, int $prazo, SerproException $pendente): void
    {
        $milissegundos = max(0, $milissegundos);
        $reserva = self::MAX_REQUESTS_POR_CHAMADA * 1000 + self::MARGEM_CHAMADA_MS;

        if ($milissegundos > self::ESPERA_MAX_MS || $this->agoraMs() + $milissegundos + $reserva > $prazo) {
            throw $pendente;
        }

        if ($milissegundos > 0) {
            Sleep::usleep($milissegundos * 1000);
        }
    }

    private function timeoutParaChamada(int $prazo, ?SerproException $pendente): int
    {
        $saldo = $prazo - $this->agoraMs() - self::MARGEM_CHAMADA_MS;
        $timeout = (int) floor($saldo / (self::MAX_REQUESTS_POR_CHAMADA * 1000));

        if ($timeout < 1) {
            throw $pendente ?? $this->pendente();
        }

        $configurado = (int) config('integra-contador.timeout', 25);

        return $configurado > 0 ? min($configurado, $timeout) : $timeout;
    }

    /**
     * O cliente e a autenticação leem a mesma configuração a cada request.
     * O escopo síncrono inclui a reautenticação em 401 e restaura o valor até
     * quando a chamada falha; não altera os timeouts das demais obrigações.
     *
     * @param  Closure(): SerproResult  $call
     */
    private function chamarComTimeout(int $timeout, Closure $call): SerproResult
    {
        $anterior = config('integra-contador.timeout');
        config(['integra-contador.timeout' => $timeout]);

        try {
            return $call();
        } finally {
            config(['integra-contador.timeout' => $anterior]);
        }
    }

    private function agoraMs(): int
    {
        return $this->clock !== null ? ($this->clock)() : (int) floor(hrtime(true) / 1_000_000);
    }

    /**
     * O 503 sintético de transporte não prova resposta do provedor. Só uma
     * resposta pendente identificada autoriza polling do mesmo protocolo.
     */
    private function respostaPendente(SerproResult|SerproException $origem): bool
    {
        $status = $origem instanceof SerproResult ? $origem->status() : $origem->status;
        $responseId = $origem instanceof SerproResult ? $origem->responseId() : $origem->responseId;

        if (! in_array($status, [202, 204, 304, 503], true) || trim($responseId ?? '') === '') {
            return false;
        }

        return $origem instanceof SerproResult
            || ($status === 503 && $origem->failure === SerproFailure::Upstream)
            || (in_array($status, [202, 204, 304], true) && $origem->failure === SerproFailure::DoNotRetry);
    }

    private function pendente(SerproResult|SerproException|null $origem = null): SerproException
    {
        return new SerproException(
            'O relatório fiscal segue em processamento; o limite local de espera exige nova tentativa.',
            SerproFailure::Upstream,
            $origem instanceof SerproResult ? $origem->status() : ($origem?->status ?? 503),
            $origem instanceof SerproResult ? ($origem->mensagens()[0]['codigo'] ?? null) : $origem?->providerCode,
            $origem instanceof SerproResult ? $origem->responseId() : $origem?->responseId,
            $origem instanceof SerproResult ? $origem->requestTag() : $origem?->requestTag,
        );
    }
}
