<?php

namespace App\Services;

use App\Enums\SerproFailure;
use App\Models\Account;
use App\Models\AccountCertificate;
use App\Models\Client;
use App\Models\SerproConnection;

/**
 * Um passe PGDAS-D (`CONSDECLARACAO13`) em homologação: pré-requisitos locais,
 * consulta real e projeção de monitoramento — sem gravar linha nem XML bruto.
 */
class SerproPgdasHomologationProbe
{
    public function __construct(
        private readonly SerproEligibility $eligibility,
        private readonly SerproMonitoringMapper $mapper,
        private readonly SerproObligationCatalog $catalog,
        private readonly SerproClient $client,
        private readonly SerproTermManager $terms,
    ) {}

    /**
     * @return array{
     *     outcome: 'pass'|'skip'|'fail',
     *     reason: ?string,
     *     account_id: ?int,
     *     client_id: ?int,
     *     steps: array<string, array{status: string, detail: string}>
     * }
     */
    public function run(?int $accountId = null, ?string $clientTaxId = null, ?int $calendarYear = null): array
    {
        $steps = [];
        $calendarYear ??= (int) now()->year;
        $canaryCnpj = (string) config('serpro_probes.homologation_canary_cnpj');
        $clientTaxId ??= $canaryCnpj;

        $account = $this->resolveAccount($accountId);
        if ($account === null) {
            return $this->finish('skip', 'conta_nao_encontrada', null, null, [
                'conta' => ['status' => 'skip', 'detail' => 'Account não encontrada pelo CNPJ configurado ou --account=.'],
            ]);
        }

        $client = Client::query()
            ->where('account_id', $account->getKey())
            ->where('tax_id', $clientTaxId)
            ->first();

        if ($client === null) {
            return $this->finish('skip', 'cliente_canario_ausente', $account->getKey(), null, [
                'cliente' => ['status' => 'skip', 'detail' => "Cliente {$clientTaxId} não existe nesta Account."],
            ]);
        }

        $connection = SerproConnection::current();
        if ($connection === null || ! $connection->isConfigured()) {
            $steps['auth'] = ['status' => 'skip', 'detail' => 'Conexão Integra Contador não configurada.'];

            return $this->finish('skip', 'sem_credencial', $account->getKey(), $client->getKey(), $steps);
        }

        try {
            $connection->assertIdentity();
        } catch (SerproException $exception) {
            $steps['auth'] = ['status' => 'skip', 'detail' => 'Certificado ou identidade inválidos: '.$exception->failure->value];

            return $this->finish('skip', 'credencial_invalida', $account->getKey(), $client->getKey(), $steps);
        }

        $token = $this->terms->validToken($account->getKey());
        if ($token === null) {
            $steps['auth'] = ['status' => 'skip', 'detail' => 'Termo de autorização ausente ou vencido.'];

            return $this->finish('skip', 'sem_termo', $account->getKey(), $client->getKey(), $steps);
        }

        $certificate = AccountCertificate::currentFor($account->getKey());
        if ($certificate === null) {
            $steps['auth'] = ['status' => 'skip', 'detail' => 'e-CNPJ do escritório ausente.'];

            return $this->finish('skip', 'sem_certificado', $account->getKey(), $client->getKey(), $steps);
        }

        $steps['auth'] = ['status' => 'pass', 'detail' => 'Credencial, termo e certificado presentes.'];

        $elegibilidade = $this->eligibility->for($account->getKey(), $client->getKey(), '00146');
        if (! $elegibilidade['eligible']) {
            $motivo = (string) ($elegibilidade['reason'] ?? 'inelegivel');
            $steps['elegibilidade'] = [
                'status' => 'skip',
                'detail' => 'Procuração 00146: '.$motivo.$this->dicaProcuracao($motivo),
            ];

            return $this->finish('skip', $motivo, $account->getKey(), $client->getKey(), $steps);
        }

        $steps['elegibilidade'] = ['status' => 'pass', 'detail' => 'Procuração 00146 vigente.'];

        $obrigacao = $this->catalog->get('declaracoes/pgdas');
        if ($obrigacao === null || $obrigacao['service'] === null) {
            $steps['consulta'] = ['status' => 'fail', 'detail' => 'Obrigação declaracoes/pgdas ausente no catálogo.'];

            return $this->finish('fail', 'catalogo', $account->getKey(), $client->getKey(), $steps);
        }

        [$idSistema, $idServico] = $this->splitService((string) $obrigacao['service']);
        $payload = array_merge($this->mapper->payload($idServico), ['anoCalendario' => (string) $calendarYear]);

        try {
            $result = $this->client->call(
                $idSistema,
                $idServico,
                $payload,
                (string) $certificate->document,
                (string) $client->tax_id,
                $token,
            );
        } catch (SerproException $exception) {
            if ($this->shouldSkipProviderFailure($exception)) {
                $steps['consulta'] = [
                    'status' => 'skip',
                    'detail' => $this->safeProviderDetail($exception),
                ];

                return $this->finish('skip', 'provedor', $account->getKey(), $client->getKey(), $steps);
            }

            $steps['consulta'] = [
                'status' => 'fail',
                'detail' => $this->safeProviderDetail($exception),
            ];

            return $this->finish('fail', 'consulta', $account->getKey(), $client->getKey(), $steps);
        }

        $steps['consulta'] = ['status' => 'pass', 'detail' => 'HTTP 200 com envelope de sucesso.'];

        $projecao = $this->mapper->project($idServico, $result);
        $periodos = is_array($projecao['periods'] ?? null) ? count($projecao['periods']) : 0;
        $causa = $projecao['cause'] ?? null;
        $detail = $causa === 'sem_declaracao'
            ? 'Projeção com cause=sem_declaracao (sem períodos no ano consultado).'
            : "Projeção com {$periodos} período(s).";

        $steps['projecao'] = ['status' => 'pass', 'detail' => $detail];

        return $this->finish('pass', null, $account->getKey(), $client->getKey(), $steps, $projecao);
    }

    private function resolveAccount(?int $accountId): ?Account
    {
        if ($accountId !== null) {
            return Account::query()->find($accountId);
        }

        $cnpj = (string) config('serpro_probes.homologation_account_cnpj');

        $certificate = AccountCertificate::query()
            ->where('document', $cnpj)
            ->whereNull('replaced_at')
            ->whereNull('removed_at')
            ->orderByDesc('id')
            ->first();

        if ($certificate === null) {
            return null;
        }

        return Account::query()->find($certificate->account_id);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitService(string $service): array
    {
        $partes = explode('/', $service, 2);

        return [$partes[0], $partes[1] ?? ''];
    }

    private function dicaProcuracao(string $motivo): string
    {
        if (! in_array($motivo, ['sem_procuracao', 'procuracao_invalida'], true)) {
            return '';
        }

        return ' — confira procuração e-CAC 00146 no e-CAC ou rode sync do cliente.';
    }

    private function shouldSkipProviderFailure(SerproException $exception): bool
    {
        if ($exception->failure === SerproFailure::Throttled) {
            return true;
        }

        return ($exception->providerCode ?? '') === '900807';
    }

    private function safeProviderDetail(SerproException $exception): string
    {
        $code = $exception->providerCode ?? '';
        $prefix = $code !== '' ? "[{$code}] " : '';

        return $prefix.$exception->failure->label();
    }

    /**
     * @param  array<string, array{status: string, detail: string}>  $steps
     * @param  array<string, mixed>|null  $projection
     * @return array{
     *     outcome: 'pass'|'skip'|'fail',
     *     reason: ?string,
     *     account_id: ?int,
     *     client_id: ?int,
     *     steps: array<string, array{status: string, detail: string}>,
     *     projection?: array<string, mixed>
     * }
     */
    private function finish(
        string $outcome,
        ?string $reason,
        ?int $accountId,
        ?int $clientId,
        array $steps,
        ?array $projection = null,
    ): array {
        $report = [
            'outcome' => $outcome,
            'reason' => $reason,
            'account_id' => $accountId,
            'client_id' => $clientId,
            'steps' => $steps,
        ];

        if ($projection !== null) {
            $report['projection'] = $this->sanitizeProjection($projection);
        }

        return $report;
    }

    /**
     * @param  array<string, mixed>  $projection
     * @return array<string, mixed>
     */
    private function sanitizeProjection(array $projection): array
    {
        unset($projection['raw']);

        return $projection;
    }
}
