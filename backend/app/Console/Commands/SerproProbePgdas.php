<?php

namespace App\Console\Commands;

use App\Services\SerproPgdasHomologationProbe;
use Illuminate\Console\Command;

/**
 * Canário PGDAS em homologação: uma consulta CONSDECLARACAO13 e projeção legível.
 *
 * Não use `-vvv` com dump de resposta — o runbook proíbe vazar `dados` do provedor.
 */
final class SerproProbePgdas extends Command
{
    /** Exit dedicado para skip (cota, procuração, pré-requisito). */
    public const EXIT_SKIP = 2;

    protected $signature = 'serpro:probe-pgdas
        {--account= : ID da Account do escritório}
        {--client= : CNPJ do cliente (default: canário de config)}
        {--year= : Ano-calendário da consulta (default: ano corrente)}
        {--force : Ignora SERPRO_PROBE_ENABLED desligado — nunca libera produção}
        {--json : Saída JSON}';

    protected $description = 'Probe de homologação PGDAS (CONSDECLARACAO13) para o cliente canário';

    public function handle(SerproPgdasHomologationProbe $probe): int
    {
        $accountOption = $this->option('account');
        $accountId = is_numeric($accountOption) ? (int) $accountOption : null;
        $clientCnpj = $this->option('client');
        $clientCnpj = is_string($clientCnpj) && $clientCnpj !== '' ? preg_replace('/\D/', '', $clientCnpj) : null;
        $year = $this->option('year');
        $calendarYear = is_numeric($year) ? (int) $year : null;

        $report = $probe->run($accountId, $clientCnpj, $calendarYear, (bool) $this->option('force'));

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $this->exitFromOutcome($report['outcome']);
        }

        foreach ($report['steps'] as $step) {
            $line = strtoupper($step['status']).' '.$step['step'];
            if (isset($step['detail'])) {
                $line .= ': '.$step['detail'];
            }
            $this->line($line);
        }

        $this->info('Outcome: '.$report['outcome']);

        if (isset($report['projection'])) {
            $this->line('Projeção (resumo): '.json_encode($report['projection'], JSON_UNESCAPED_UNICODE));
        }

        return $this->exitFromOutcome($report['outcome']);
    }

    private function exitFromOutcome(string $outcome): int
    {
        return match ($outcome) {
            SerproPgdasHomologationProbe::OUTCOME_PASS => self::SUCCESS,
            SerproPgdasHomologationProbe::OUTCOME_SKIP => self::EXIT_SKIP,
            default => self::FAILURE,
        };
    }
}
