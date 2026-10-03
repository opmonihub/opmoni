<?php

namespace App\Services;

use App\Enums\SerproFailure;
use App\Models\Account;
use App\Models\AccountCertificate;
use App\Models\Client;
use App\Models\SerproConnection;
use App\Tenant\CurrentTenant;

/**
 * Loop de homologação PGDAS: envelope CONSDECLARACAO13 → projeção de monitoramento.
 *
 * Não imprime `dados` brutos, tokens nem segredos — só metadados de outcome.
 */
final class SerproPgdasHomologationProbe
{
    public const OUTCOME_PASS = 'pass';

    public const OUTCOME_SKIP = 'skip';

    public const OUTCOME_FAIL = 'fail';

    public function __construct(
        private readonly SerproProbeGate $gate,
        private readonly SerproTermManager $terms,
        private readonly SerproEligibility $eligibility,
        private readonly SerproClient $client,
        private readonly SerproCallRecorder $recorder,
        private readonly SerproMonitoringMapper $mapper,
        private readonly SerproObligationCatalog $catalog,
    ) {}

    /**
     * @return array{
     *     outcome: string,
     *     steps: list<array{step: string, status: string, detail?: string}>,
     *     projection?: array<string, mixed>
     * }
     */
    public function run(?int $accountId = null, ?string $clientCnpj = null, ?int $calendarYear = null, bool $forceGate = false): array
    {
        $steps = [];
        $canary = (string) config('serpro_probes.homologation_canary_cnpj');
        $clientCnpj = $clientCnpj ?? $canary;
        $calendarYear ??= (int) now()->year;

        $refusal = $this->gate->blockReason($forceGate);
        if ($refusal !== null) {
            $steps[] = ['step' => 'gate', 'status' => 'fail', 'detail' => $this->gate->humanMessage($refusal)];

            return ['outcome' => self::OUTCOME_FAIL, 'steps' => $steps];
        }
        $steps[] = ['step' => 'gate', 'status' => 'ok'];

        $connection = SerproConnection::current();
        if ($connection === null || ! $connection->isConfigured()) {
            $steps[] = ['step' => 'credencial', 'status' => 'skip', 'detail' => 'Credencial de plataforma incompleta ou ausente.'];

            return ['outcome' => self::OUTCOME_SKIP, 'steps' => $steps];
        }
        $steps[] = ['step' => 'credencial', 'status' => 'ok'];

        $resolvedAccountId = $this->resolveAccountId($accountId);
        if ($resolvedAccountId === null) {
            $steps[] = ['step' => 'conta', 'status' => 'skip', 'detail' => 'Account não encontrada para o CNPJ do escritório configurado.'];

            return ['outcome' => self::OUTCOME_SKIP, 'steps' => $steps];
        }
        $steps[] = ['step' => 'conta', 'status' => 'ok', 'detail' => (string) $resolvedAccountId];

        $tenant = resolve(CurrentTenant::class);
        $previousTenant = $tenant->accountId;
        $tenant->accountId = $resolvedAccountId;

        try {
            $token = $this->terms->validToken($resolvedAccountId);
            if ($token === null) {
                $steps[] = ['step' => 'termo', 'status' => 'skip', 'detail' => 'Termo de autorização ausente ou inválido para a Account.'];

                return ['outcome' => self::OUTCOME_SKIP, 'steps' => $steps];
            }
            $steps[] = ['step' => 'termo', 'status' => 'ok'];

            $client = Client::query()
                ->withoutGlobalScopes()
                ->where('account_id', $resolvedAccountId)
                ->where('tax_id', $clientCnpj)
                ->first();

            if ($client === null) {
                $steps[] = ['step' => 'cliente', 'status' => 'skip', 'detail' => 'Cliente canário não encontrado na Account.'];

                return ['outcome' => self::OUTCOME_SKIP, 'steps' => $steps];
            }
            $steps[] = ['step' => 'cliente', 'status' => 'ok', 'detail' => (string) $client->getKey()];

            $obrigacao = $this->catalog->get('declaracoes/pgdas');
            if ($obrigacao === null || $obrigacao['service'] === null) {
                $steps[] = ['step' => 'elegibilidade', 'status' => 'fail', 'detail' => 'Obrigação declaracoes/pgdas ausente no catálogo.'];

                return ['outcome' => self::OUTCOME_FAIL, 'steps' => $steps];
            }

            // Elegibilidade percorre as alternativas do mapa como o sync faz —
            // cada uma é um conjunto de famílias (`+` conjunção, `,` alternativa)
            // que precisa estar inteiro concedido.
            $alternativas = $this->catalog->procuracaoAlternatives($obrigacao['procuracao'] ?? null);
            $familias = implode(', ', array_map(
                fn (array $alt): string => implode('+', $alt),
                $alternativas,
            ));

            $inelegivel = null;
            foreach ($alternativas as $alternativa) {
                $concedida = collect($alternativa)->every(
                    fn (string $family): bool => $this->eligibility
                        ->for($resolvedAccountId, $client->getKey(), $family)['eligible'],
                );

                if ($concedida) {
                    $inelegivel = null;
                    break;
                }

                $primeira = $alternativa[0] ?? null;
                if ($inelegivel === null && $primeira !== null) {
                    $inelegivel = $this->eligibility->for(
                        $resolvedAccountId,
                        $client->getKey(),
                        $primeira,
                    )['reason'];
                }
            }

            if ($inelegivel !== null) {
                $motivo = (string) $inelegivel;
                $steps[] = [
                    'step' => 'elegibilidade',
                    'status' => 'skip',
                    'detail' => "Procuração {$familias}: {$motivo}".$this->dicaProcuracao($motivo, $familias),
                ];

                return ['outcome' => self::OUTCOME_SKIP, 'steps' => $steps];
            }
            $steps[] = ['step' => 'elegibilidade', 'status' => 'ok'];

            $certificate = AccountCertificate::currentFor($resolvedAccountId);
            if ($certificate === null || $certificate->document === null) {
                $steps[] = ['step' => 'auth', 'status' => 'skip', 'detail' => 'e-CNPJ do escritório ausente.'];

                return ['outcome' => self::OUTCOME_SKIP, 'steps' => $steps];
            }

            [$idSistema, $idServico] = $this->splitService((string) $obrigacao['service']);
            $payload = array_merge(
                $this->mapper->payload($idServico),
                ['anoCalendario' => (string) $calendarYear],
            );

            try {
                $result = $this->recorder->record(
                    null,
                    $resolvedAccountId,
                    $client->getKey(),
                    $idSistema,
                    $idServico,
                    fn (): SerproResult => $this->client->call(
                        $idSistema,
                        $idServico,
                        $payload,
                        (string) $certificate->document,
                        (string) $client->tax_id,
                        $token,
                    ),
                );
            } catch (SerproException $exception) {
                if ($this->shouldSkipProviderFailure($exception)) {
                    $steps[] = ['step' => 'consulta', 'status' => 'skip', 'detail' => $this->safeProviderDetail($exception)];

                    return ['outcome' => self::OUTCOME_SKIP, 'steps' => $steps];
                }

                $steps[] = [
                    'step' => 'consulta',
                    'status' => 'fail',
                    'detail' => $this->safeProviderDetail($exception),
                ];

                return ['outcome' => self::OUTCOME_FAIL, 'steps' => $steps];
            }

            $steps[] = ['step' => 'auth', 'status' => 'ok'];
            $steps[] = ['step' => 'consulta', 'status' => 'ok', 'detail' => 'HTTP '.$result->status()];

            $projection = $this->mapper->project($idServico, $result);
            $periods = is_array($projection['periods'] ?? null) ? $projection['periods'] : [];
            $comDas = collect($periods)->whereNotNull('slip_number')->count();
            $cause = $projection['cause'] ?? null;
            $detail = $cause === 'sem_declaracao'
                ? 'sem_declaracao (sem períodos no ano consultado)'
                : count($periods)." período(s), {$comDas} com DAS emitido";

            $steps[] = ['step' => 'projecao', 'status' => 'ok', 'detail' => $detail];

            return [
                'outcome' => self::OUTCOME_PASS,
                'steps' => $steps,
                'projection' => $this->safeProjectionSummary($projection),
            ];
        } finally {
            $tenant->accountId = $previousTenant;
        }
    }

    public function resolveAccountId(?int $accountId): ?int
    {
        if ($accountId !== null) {
            return Account::query()->whereKey($accountId)->exists() ? $accountId : null;
        }

        $cnpj = (string) config('serpro_probes.homologation_account_cnpj');

        $certificate = AccountCertificate::query()
            ->withoutGlobalScopes()
            ->where('document', $cnpj)
            ->whereNull('replaced_at')
            ->whereNull('removed_at')
            ->latest('id')
            ->first();

        return $certificate?->account_id;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitService(string $service): array
    {
        $partes = explode('/', $service, 2);

        return [$partes[0], $partes[1] ?? ''];
    }

    private function dicaProcuracao(string $motivo, string $familias): string
    {
        if (! in_array($motivo, ['sem_procuracao', 'procuracao_invalida'], true)) {
            return '';
        }

        return " — confira procuração e-CAC {$familias} no e-CAC ou rode sync do cliente.";
    }

    private function shouldSkipProviderFailure(SerproException $exception): bool
    {
        if ($exception->failure === SerproFailure::Throttled) {
            return true;
        }

        return ($exception->providerCode ?? '') === '900807'
            || ($exception->providerCode ?? '') === 'AcessoNegado-ICGERENCIADOR-022';
    }

    private function safeProviderDetail(SerproException $exception): string
    {
        $code = $exception->providerCode ?? '';
        $prefix = $code !== '' ? "[{$code}] " : '';

        return $prefix.$exception->failure->label();
    }

    /**
     * Resumo seguro da projeção — sem payload bruto.
     *
     * @param  array<string, mixed>  $projection
     * @return array<string, mixed>
     */
    private function safeProjectionSummary(array $projection): array
    {
        $periods = [];
        foreach ($projection['periods'] ?? [] as $period) {
            if (! is_array($period)) {
                continue;
            }

            $periods[] = [
                'period' => $period['period'] ?? null,
                'declared_at' => $period['declared_at'] ?? null,
                'rectified' => $period['rectified'] ?? null,
                'slip_number' => isset($period['slip_number']) ? '«presente»' : null,
                'slip_issued_at' => $period['slip_issued_at'] ?? null,
                'slip_paid' => $period['slip_paid'] ?? null,
            ];
        }

        return [
            'cause' => $projection['cause'] ?? null,
            'period_count' => count($periods),
            'periods' => $periods,
            'fields' => array_keys($projection['fields'] ?? []),
        ];
    }
}
