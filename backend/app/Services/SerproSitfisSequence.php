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

    /**
     * Tentativas de solicitação de protocolo — o SERPRO devolve 503 com
     * `tempoEspera` quando o relatório está sendo gerado. O tempo que ele
     * pede pode ser maior que o timeout do job (75s), então a sequência
     * aguarda o que couber e joga `Upstream` quando estoura — a próxima
     * retentativa do worker (`tries`, `backoff`) continua de onde parou.
     */
    private const MAX_TENTATIVAS_PROTOCOLO = 2;

    /**
     * Teto de espera entre tentativas — o provedor manda ms, não segundos.
     * O SITFIS já pediu `tempoEspera` de 30s para a solicitação de protocolo;
     * quando passar do teto o job aborta em `Upstream` e a fila retenta.
     */
    private const ESPERA_MAX_MS = 32_000;

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
        $ultimaExcecao = null;

        // `SOLICITARPROTOCOLO91` pode devolver 503 com `tempoEspera` quando o
        // provedor ainda está gerando o relatório — isso não é falha, é a
        // fila do SERPRO. Respeitamos o tempo e tentamos de novo.
        for ($tentativa = 0; $tentativa < self::MAX_TENTATIVAS_PROTOCOLO; $tentativa++) {
            try {
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

                // Quando o provedor responde 503 + `Sucesso-Sitfis-SC01` ou
                // `Aviso-Sitfis-AV03`, o relatório está pronto/em fila — o
                // envelope vem com `tempoEspera` em ms e o protocolo quando
                // já alocado. Respeitamos a espera e tentamos de novo.
                if ($protocolo === '') {
                    $espera = (int) ($dados['tempoEspera'] ?? 0);

                    if ($espera > 0 || $result->status() === 503) {
                        $this->aguardarMs($espera > 0 ? $espera : self::ESPERA_MAX_MS);

                        continue;
                    }

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
            } catch (SerproException $exception) {
                // 503 aqui é "estou gerando, volta daqui a tempoEspera ms" —
                // respeitamos a espera e tentamos de novo.
                if ($exception->status === 503) {
                    $ultimaExcecao = $exception;
                    $this->aguardarMs(self::ESPERA_MAX_MS);

                    continue;
                }

                // Outros erros sobem imediatamente (401, 403, timeout...).
                throw $exception;
            }
        }

        if ($ultimaExcecao !== null) {
            throw $ultimaExcecao;
        }

        throw new SerproException(
            'O SITFIS não respondeu com protocolo dentro do limite de tentativas.',
            SerproFailure::Upstream,
            503,
        );
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
                // 202/204/304/503 aqui são todos "o relatório ainda não está
                // pronto" — o provedor pede pra esperar o `tempoEspera` e
                // bater de novo, não é falha.
                if (in_array($exception->status, [202, 204, 304, 503], true)) {
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
