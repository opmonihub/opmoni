<?php

namespace App\Console\Commands;

use App\Erp\Fiscal\ErpFiscalAttemptService;
use App\Models\Erp\Fiscal\ErpFiscalAttempt;
use Illuminate\Console\Command;

class ScanNewErpFiscalAttempts extends Command
{
    protected $signature = 'erp:fiscal-scan-new-attempts';

    protected $description = 'Dispatch a bounded batch of newly persisted ERP fiscal attempts';

    public function handle(ErpFiscalAttemptService $attempts): int
    {
        ErpFiscalAttempt::query()
            ->where(fn ($query) => $query->where('outbox_state', 'new')->orWhere(fn ($leased) => $leased->where('outbox_state', 'leased')->where('lease_expires_at', '<=', now())))
            ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->orderBy('id')
            ->limit(100)
            ->pluck('id')
            ->each(fn ($id) => $attempts->dispatchAfterCommit(ErpFiscalAttempt::query()->findOrFail((int) $id)));

        return self::SUCCESS;
    }
}
