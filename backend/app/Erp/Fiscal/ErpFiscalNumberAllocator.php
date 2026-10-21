<?php

namespace App\Erp\Fiscal;

use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\Erp\Fiscal\ErpFiscalNumberSequence;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ErpFiscalNumberAllocator
{
    public function allocate(int $companyId, string $model, string $environment, int $series, int $initialNextNumber): int
    {
        $this->assertScope($companyId, $model, $environment, $series, $initialNextNumber);

        return DB::transaction(function () use ($companyId, $model, $environment, $series, $initialNextNumber): int {
            $scope = ['erp_company_id' => $companyId, 'model' => $model, 'environment' => $environment, 'series' => $series];
            $maximum = (int) ErpFiscalDocument::query()
                ->where('erp_company_id', $companyId)->where('model', $model)->where('environment', $environment)
                ->where('series', $series)->max('number');
            if ($maximum >= 999_999_999) {
                throw new InvalidArgumentException('A sequência fiscal atingiu o limite nacional de numeração.');
            }
            $minimum = $maximum + 1;
            $now = now();
            DB::table('erp_fiscal_number_sequences')->insertOrIgnore([...$scope, 'next_number' => max($initialNextNumber, $minimum), 'created_at' => $now, 'updated_at' => $now]);
            $sequence = ErpFiscalNumberSequence::query()->where($scope)->lockForUpdate()->firstOrFail();
            $next = max((int) $sequence->next_number, $minimum, $initialNextNumber);
            if ($next > 999_999_999) {
                throw new InvalidArgumentException('A sequência fiscal atingiu o limite nacional de numeração.');
            }
            $sequence->next_number = $next + 1;
            $sequence->save();

            return $next;
        }, 3);
    }

    public function nextNumber(int $companyId, string $model, string $environment, int $series): int
    {
        return (int) ErpFiscalNumberSequence::query()
            ->where('erp_company_id', $companyId)->where('model', $model)->where('environment', $environment)->where('series', $series)
            ->value('next_number');
    }

    public function assertExplicitNumberAvailable(int $companyId, string $model, string $environment, int $series, int $number, int $establishmentId): void
    {
        unset($establishmentId); // Establishment identity is intentionally not part of the shared sequence scope.
        $next = $this->nextNumber($companyId, $model, $environment, $series);
        if ($number <= 0 || $next <= 0 || $number !== $next) {
            throw new InvalidArgumentException('Número explícito não corresponde ao próximo número compartilhado disponível.');
        }
    }

    public function reserveExplicitNumber(int $companyId, string $model, string $environment, int $series, int $number): int
    {
        $this->assertScope($companyId, $model, $environment, $series, $number);

        return DB::transaction(function () use ($companyId, $model, $environment, $series, $number): int {
            $sequence = ErpFiscalNumberSequence::query()
                ->where('erp_company_id', $companyId)->where('model', $model)->where('environment', $environment)->where('series', $series)
                ->lockForUpdate()->first();
            if (! $sequence || (int) $sequence->next_number !== $number) {
                throw new InvalidArgumentException('Número explícito não corresponde ao próximo número compartilhado disponível.');
            }
            $sequence->next_number = $number + 1;
            $sequence->save();

            return $number;
        }, 3);
    }

    public function reserveRange(int $companyId, string $model, string $environment, int $series, int $from, int $to): void
    {
        $this->assertScope($companyId, $model, $environment, $series, $from);
        if (! in_array($model, ['55', '65'], true) || $to < $from || $to > 999999999) {
            throw new InvalidArgumentException('Faixa de inutilização inválida.');
        }
        $sequence = ErpFiscalNumberSequence::query()->where('erp_company_id', $companyId)->where('model', $model)->where('environment', $environment)->where('series', $series)->lockForUpdate()->first();
        $maximum = (int) ErpFiscalDocument::query()->where('erp_company_id', $companyId)->where('model', $model)->where('environment', $environment)->where('series', $series)->max('number');
        if (! $sequence || $from < (int) $sequence->next_number || $from <= $maximum) {
            throw new InvalidArgumentException('Faixa já reservada ou anterior à sequência compartilhada.');
        }
        $sequence->next_number = $to + 1;
        $sequence->save();
    }

    private function assertScope(int $companyId, string $model, string $environment, int $series, int $number): void
    {
        if ($companyId <= 0 || ! in_array($model, ['55', '65', '57', '58'], true) || ! in_array($environment, ['homologation', 'production'], true) || $series < 0 || $series > 999 || $number <= 0 || $number > 999_999_999) {
            throw new InvalidArgumentException('Escopo de numeração fiscal inválido.');
        }
    }
}
