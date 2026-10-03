<?php

namespace App\Console\Commands;

use App\Services\SerproPgdasHomologationProbe;
use App\Services\SerproProbeGate;
use Illuminate\Console\Command;

/**
 * Canário PGDAS-D em homologação: uma consulta `CONSDECLARACAO13`, projeção
 * legível e nenhuma escrita em monitoramento. Não loga token, senha, XML ou
 * consumer secret — só status por etapa e resumo da projeção.
 */
final class SerproProbePgdas extends Command
{
    public const SKIP_EXIT = 2;

    protected $signature = 'serpro:probe-pgdas
        {--account= : ID da Account (senão resolve pelo CNPJ do escritório na config)}
        {--client= : CNPJ do cliente (default: canário AUTO CENTER)}
        {--year= : Ano-calendário da consulta (default: ano corrente)}
        {--force : Ignora SERPRO_PROBE_ENABLED}
        {--json : Saída JSON}';

    protected $description = 'Probe de homologação PGDAS-D (CONSDECLARACAO13) para o cliente canário';

    public function handle(SerproProbeGate $gate, SerproPgdasHomologationProbe $probe): int
    {
        $block = $gate->blockReason((bool) $this->option('force'));

        if ($block !== null) {
            $this->reportGateFailure($gate->humanMessage($block));

            return self::FAILURE;
        }

        $account = $this->option('account');
        $accountId = is_numeric($account) ? (int) $account : null;
        $clientCnpj = $this->option('client');
        $clientCnpj = is_string($clientCnpj) && $clientCnpj !== '' ? $clientCnpj : null;
        $year = $this->option('year');
        $calendarYear = is_numeric($year) ? (int) $year : null;

        $report = $probe->run($accountId, $clientCnpj, $calendarYear);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $this->exitFromOutcome((string) $report['outcome']);
        }

        $this->info('Probe PGDAS homologação — outcome: '.$report['outcome']);

        foreach ($report['steps'] as $nome => $step) {
            $this->line(sprintf('  [%s] %s: %s', $step['status'], $nome, $step['detail']));
        }

        if (isset($report['projection']['cause'])) {
            $this->line('  cause: '.$report['projection']['cause']);
        }

        if (isset($report['projection']['periods']) && is_array($report['projection']['periods'])) {
            $this->line('  períodos projetados: '.count($report['projection']['periods']));
        }

        if ($report['reason'] !== null && $report['outcome'] !== 'pass') {
            $this->warn('Motivo: '.$report['reason']);
        }

        return $this->exitFromOutcome((string) $report['outcome']);
    }

    private function reportGateFailure(string $message): void
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode(['outcome' => 'fail', 'reason' => $message], JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->error($message);
    }

    private function exitFromOutcome(string $outcome): int
    {
        return match ($outcome) {
            'pass' => self::SUCCESS,
            'skip' => self::SKIP_EXIT,
            default => self::FAILURE,
        };
    }
}
