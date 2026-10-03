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
    ) {}

    /**
     * @return array{
     *     outcome: string,
     *     steps: list<array{step: string, status: string, detail?: string}>,
     *     projection?: array<string, mixed>
     * }
     */
    public function run(?int $accountId, ?string $clientCnpj = null, bool $forceGate = false): array
    {
        $steps = [];
        $canary = (string) config('serpro_probes.homologation_canary_cnpj');
        $clientCnpj = $clientCnpj ?? $canary;

        $refusal = $this->gate->refusalReason($forceGate);
        if ($refusal !== null) {
            $steps[] = ['step' => 'gate', 'status' => 'fail', 'detail' => $refusal];

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

            $elegibilidade = $this->eligibility->for($resolvedAccountId, $client->getKey(), '00146');
            if ($elegibilidade['eligible'] !== true) {
                $motivo = (string) ($elegibilidade['reason'] ?? 'inelegivel');
                $steps[] = ['step' => 'elegibilidade', 'status' => 'skip', 'detail' => $motivo];

                return ['outcome' => self::OUTCOME_SKIP, 'steps' => $steps];
            }
            $steps[] = ['step' => 'elegibilidade', 'status' => 'ok'];

            $certificate = AccountCertificate::currentFor($resolvedAccountId);
            if ($certificate === null || $certificate->document === null) {
                $steps[] = ['step' => 'auth', 'status' => 'skip', 'detail' => 'e-CNPJ do escritório ausente.'];

                return ['outcome' => self::OUTCOME_SKIP, 'steps' => $steps];
            }

            try {
                $result = $this->recorder->record(
                    null,
                    $resolvedAccountId,
                    $client->getKey(),
                    'PGDASD',
                    'CONSDECLARACAO13',
                    fn (): SerproResult => $this->client->call(
                        'PGDASD',
                        'CONSDECLARACAO13',
                        $this->mapper->payload('CONSDECLARACAO13'),
                        (string) $certificate->document,
                        (string) $client->tax_id,
                        $token,
                    ),
                );
            } catch (SerproException $exception) {
                if ($exception->failure === SerproFailure::Throttled) {
                    $steps[] = ['step' => 'consulta', 'status' => 'skip', 'detail' => 'Cota ou limite do provedor (throttle).'];

                    return ['outcome' => self::OUTCOME_SKIP, 'steps' => $steps];
                }

                if ($exception->providerCode === 'AcessoNegado-ICGERENCIADOR-022') {
                    $steps[] = ['step' => 'consulta', 'status' => 'skip', 'detail' => 'Procuração ausente ou insuficiente para PGDAS.'];

                    return ['outcome' => self::OUTCOME_SKIP, 'steps' => $steps];
                }

                $steps[] = [
                    'step' => 'consulta',
                    'status' => 'fail',
                    'detail' => $exception->failure->value.($exception->providerCode !== null ? ':'.$exception->providerCode : ''),
                ];

                return ['outcome' => self::OUTCOME_FAIL, 'steps' => $steps];
            }

            $steps[] = ['step' => 'auth', 'status' => 'ok'];
            $steps[] = ['step' => 'consulta', 'status' => 'ok', 'detail' => 'HTTP '.$result->status()];

            $projection = $this->mapper->project('CONSDECLARACAO13', $result);
            $steps[] = ['step' => 'projecao', 'status' => 'ok'];

            $periodCount = count($projection['periods'] ?? []);
            $cause = $projection['cause'] ?? null;
            if ($periodCount === 0 && $cause === 'sem_declaracao') {
                $steps[] = ['step' => 'resultado', 'status' => 'ok', 'detail' => 'sem_declaracao'];
            } elseif ($periodCount > 0) {
                $steps[] = ['step' => 'resultado', 'status' => 'ok', 'detail' => $periodCount.' periodo(s)'];
            } else {
                $steps[] = ['step' => 'resultado', 'status' => 'ok', 'detail' => 'sem periodos'];
            }

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
