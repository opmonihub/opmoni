<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Support\AccessTransaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillOfficeErpCompanyRegistrations extends Command
{
    protected $signature = 'office-erp:backfill-registrations {--dry-run : Report without inserting registrations}';

    protected $description = 'Insert missing ERP Company registrations from unambiguous historical office contracts';

    public function handle(): int
    {
        $counts = ['created' => 0, 'unchanged' => 0, 'conflicts' => 0];
        $candidates = DB::table('office_erp_contracts')->whereNotNull('erp_company_id')
            ->select(['account_id', 'client_id', 'erp_company_id'])
            ->groupBy('account_id', 'client_id', 'erp_company_id')
            ->orderBy('account_id')->orderBy('client_id')->orderBy('erp_company_id')->cursor();
        foreach ($candidates as $candidate) {
            $result = DB::transaction(function () use ($candidate): string {
                AccessTransaction::boundLockWaits();
                Account::query()->whereKey($candidate->account_id)->lockForUpdate()->firstOrFail();
                $related = DB::table('office_erp_contracts')->where('account_id', $candidate->account_id)
                    ->whereNotNull('erp_company_id')
                    ->where(fn ($query) => $query->where('client_id', $candidate->client_id)->orWhere('erp_company_id', $candidate->erp_company_id));
                $ambiguous = (clone $related)->where(fn ($query) => $query
                    ->where('client_id', '<>', $candidate->client_id)
                    ->orWhere('erp_company_id', '<>', $candidate->erp_company_id))->exists();
                if ($ambiguous) {
                    return 'conflicts';
                }
                $registrations = DB::table('office_erp_company_registrations')
                    ->where('account_id', $candidate->account_id)
                    ->where(fn ($query) => $query->where('client_id', $candidate->client_id)->orWhere('erp_company_id', $candidate->erp_company_id))
                    ->orderBy('id')->lockForUpdate()->get();
                if ($registrations->isNotEmpty()) {
                    return $registrations->count() === 1
                        && (int) $registrations[0]->client_id === (int) $candidate->client_id
                        && (int) $registrations[0]->erp_company_id === (int) $candidate->erp_company_id
                        ? 'unchanged' : 'conflicts';
                }
                $registeredAt = (clone $related)->min('created_at');
                if ($registeredAt === null) {
                    return 'conflicts';
                }
                if (! $this->option('dry-run')) {
                    DB::table('office_erp_company_registrations')->insert([
                        'account_id' => $candidate->account_id, 'client_id' => $candidate->client_id,
                        'erp_company_id' => $candidate->erp_company_id, 'created_by_user_id' => null,
                        'registered_at' => $registeredAt, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }

                return 'created';
            });
            $counts[$result]++;
            if ($result === 'conflicts') {
                $this->warn(sprintf('Association conflict: account=%d client=%d company=%d', $candidate->account_id, $candidate->client_id, $candidate->erp_company_id));
            }
        }
        $this->line(sprintf('%screated=%d unchanged=%d conflicts=%d', $this->option('dry-run') ? 'dry-run ' : '', ...array_values($counts)));

        return $counts['conflicts'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
