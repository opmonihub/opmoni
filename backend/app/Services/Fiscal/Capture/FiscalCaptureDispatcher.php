<?php

namespace App\Services\Fiscal\Capture;

use App\Enums\FiscalSource;
use App\Jobs\CaptureFiscalDocumentsJob;
use App\Models\Client;
use App\Models\FiscalCursor;
use App\Services\Fiscal\Read\FiscalCoverage;
use Carbon\CarbonInterface;

/**
 * A porta de entrada única do despacho de captura: quem decide que um job
 * nasce é este serviço — o disparo sob demanda da tela e o disparo que o
 * upload do A1 faz — para que as duas recusas, fonte e bloqueio, digam a
 * mesma frase nos dois caminhos.
 *
 * São duas recusas, e elas não são a mesma coisa: **fonte** é o que a
 * instalação não serve (sem conector, ou CT-e com a chave desligada) e vale
 * para todo cliente; **bloqueio** é o fisco mandando parar aquele par
 * cliente × fonte por uma hora. A fonte é conferida antes porque é a mais
 * fundamental das duas: não há espera quando o pedido não pode existir.
 *
 * O retorno é o `meta.capture` que o upload publica: `queued` com as fontes
 * enfileiradas, `blocked` com o horário do cursor, ou `not_capturable` com a
 * palavra do motivo — a que `FiscalCoverage::certificateStatus` nomeia.
 */
final class FiscalCaptureDispatcher
{
    public function __construct(
        private readonly FiscalConnectorRegistry $connectors,
    ) {}

    /**
     * A fonte que esta instalação não captura agora, e a frase que o operador
     * lê. `null` quando a fonte passa.
     */
    public function recusaDeFonte(FiscalSource $source): ?string
    {
        if (! $this->connectors->has($source)) {
            return "A fonte {$source->label()} não tem conector nesta versão.";
        }

        if ($source === FiscalSource::CteDistribuicao && ! config('fiscal.cte_enabled', false)) {
            return 'A captura de CT-e está desligada nesta instalação (fiscal.cte_enabled). Nada foi enfileirado.';
        }

        if ($source === FiscalSource::NfseAdn && ! config('fiscal.nfse_enabled', false)) {
            return 'A captura de NFS-e está desligada nesta instalação (fiscal.nfse_enabled). Nada foi enfileirado.';
        }

        return null;
    }

    /**
     * O cursor parado daquele par cliente e fonte, ou `null`. A parada é por
     * fonte e por tempo: `blocked_until` no passado é histórico, e a conta é
     * sempre explícita porque a fila não tem tenant.
     */
    public function bloqueioDe(int $accountId, Client $client, FiscalSource $source): ?CarbonInterface
    {
        $cursor = FiscalCursor::query()
            ->where('account_id', $accountId)
            ->where('client_id', (int) $client->getKey())
            ->where('source', $source->value)
            ->first();

        return $cursor?->isBlocked() === true ? $cursor->blocked_until : null;
    }

    /**
     * Despacha as fontes capturáveis do cliente e conta o que aconteceu.
     *
     * A decisão é tomada depois de quem chama ter gravado o certificado — a
     * captura enfileirada é a que o certificado novo autoriza, não a do que
     * estava antes. Fonte sem conector e cliente bloqueado ficam de fora da
     * lista sem derrubar o despacho das demais.
     *
     * @return array{status: string, sources: list<string>, blocked_until: ?string, reason: ?string}
     */
    public function capturar(Client $client): array
    {
        $accountId = (int) $client->account_id;

        // O certificado é a condição de existir consulta: sem ele nenhuma
        // fonte captura, e a palavra do motivo é a mesma que a cobertura usa.
        $certificado = \resolve(FiscalCoverage::class)
            ->certificateStatus($client);

        if (in_array($certificado, ['missing', 'expired', 'password_missing'], true)) {
            return [
                'status' => 'not_capturable',
                'sources' => [],
                'blocked_until' => null,
                'reason' => $certificado === 'missing' ? 'certificate_absent' : "certificate_{$certificado}",
            ];
        }

        $enfileiradas = [];
        $bloqueio = null;

        foreach ($this->fontesServidas() as $source) {
            if ($this->recusaDeFonte($source) !== null) {
                continue;
            }

            $parado = $this->bloqueioDe($accountId, $client, $source);

            if ($parado !== null) {
                $bloqueio = $bloqueio === null || $parado->lt($bloqueio) ? $parado : $bloqueio;

                continue;
            }

            CaptureFiscalDocumentsJob::dispatch((int) $client->getKey(), $source, $accountId);
            $enfileiradas[] = $source->value;
        }

        if ($enfileiradas === []) {
            return [
                'status' => $bloqueio !== null ? 'blocked' : 'not_capturable',
                'sources' => [],
                'blocked_until' => $bloqueio?->toISOString(),
                'reason' => $bloqueio !== null ? 'capture_blocked' : 'no_source',
            ];
        }

        return [
            'status' => 'queued',
            'sources' => $enfileiradas,
            'blocked_until' => null,
            'reason' => null,
        ];
    }

    /**
     * As fontes que o catálogo de conectores serve, na ordem do enum.
     *
     * @return list<FiscalSource>
     */
    private function fontesServidas(): array
    {
        return array_values(array_filter(
            FiscalSource::cases(),
            fn (FiscalSource $source): bool => $this->connectors->has($source),
        ));
    }
}
