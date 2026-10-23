<?php

namespace App\Erp\Accounting;

use App\Jobs\DeliverErpAccountingDocument;
use App\Models\Erp\Accounting\ErpAccountingDeliveryDispatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ErpAccountingDispatchOutbox
{
    private const BATCH_SIZE = 100;

    private const LEASE_SECONDS = 120;

    private const RETRY_SECONDS = 300;

    public function scheduleWake(ErpAccountingDeliveryDispatch $dispatch): void
    {
        DB::afterCommit(fn () => $this->publish($dispatch->id));
    }

    public function dispatchDue(): int
    {
        $ids = ErpAccountingDeliveryDispatch::query()
            ->where(function ($query): void {
                $query->where(fn ($pending) => $pending->where('status', 'pending')->where(fn ($due) => $due->whereNull('available_at')->orWhere('available_at', '<=', now())))
                    ->orWhere(fn ($expired) => $expired->where('status', 'leased')->where('lease_expires_at', '<=', now()));
            })
            ->orderBy('id')
            ->limit(self::BATCH_SIZE)
            ->pluck('id');
        $queued = 0;

        foreach ($ids as $id) {
            if ($this->publish((int) $id)) {
                $queued++;
            }
        }

        return $queued;
    }

    public function complete(int $dispatchId): void
    {
        ErpAccountingDeliveryDispatch::query()->whereKey($dispatchId)->whereIn('status', ['pending', 'leased'])->update([
            'status' => 'completed',
            'completed_at' => now(),
            'lease_expires_at' => null,
            'lease_token' => null,
            'last_error' => null,
        ]);
    }

    public function recordDeliveryResult(ErpAccountingDeliveryDispatch $dispatch, string $status, int $attempts): void
    {
        if ($status === 'failed' && $attempts < ErpAccountingHandoffService::MAX_ATTEMPTS
            && $dispatch->attempt_sequence < ErpAccountingHandoffService::MAX_ATTEMPTS) {
            $delay = min(60, 2 ** min($dispatch->attempt_sequence, 5));
            $dispatch->forceFill([
                'status' => 'pending',
                'attempt_sequence' => $dispatch->attempt_sequence + 1,
                'available_at' => now()->addSeconds($delay),
                'lease_expires_at' => null,
                'lease_token' => null,
            ])->save();

            return;
        }

        $this->complete((int) $dispatch->id);
    }

    public function publish(int $dispatchId): bool
    {
        $dispatch = DB::transaction(function () use ($dispatchId): ?ErpAccountingDeliveryDispatch {
            $row = ErpAccountingDeliveryDispatch::query()->whereKey($dispatchId)->lockForUpdate()->first();
            $pendingDue = $row?->status === 'pending' && ($row->available_at === null || $row->available_at->lte(now()));
            $leaseExpired = $row?->status === 'leased' && $row->lease_expires_at?->lte(now());
            if ($row === null || (! $pendingDue && ! $leaseExpired)) {
                return null;
            }
            $row->forceFill([
                'status' => 'leased',
                'dispatch_attempts' => $row->dispatch_attempts + 1,
                'lease_expires_at' => now()->addSeconds(self::LEASE_SECONDS),
                'available_at' => now()->addSeconds(self::RETRY_SECONDS),
                'lease_token' => (string) Str::uuid(),
            ])->save();

            return $row;
        });
        if ($dispatch === null) {
            return false;
        }
        $leaseToken = (string) $dispatch->lease_token;
        $connection = (string) config('queue.default');
        if (! in_array(config('queue.connections.'.$connection.'.driver'), ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
            $this->release($dispatchId, $leaseToken, 'accounting_worker_not_configured');

            return false;
        }
        try {
            DeliverErpAccountingDocument::dispatch(
                (int) $dispatch->erp_accounting_delivery_id,
                (int) $dispatch->id,
                (int) $dispatch->generation,
                (int) $dispatch->attempt_sequence,
                (string) $dispatch->lease_token,
            )
                ->onConnection($connection);
        } catch (Throwable) {
            $this->release($dispatchId, $leaseToken, 'queue_publication_failed');

            return false;
        }

        return true;
    }

    private function release(int $dispatchId, string $leaseToken, string $reason): void
    {
        ErpAccountingDeliveryDispatch::query()->whereKey($dispatchId)->where('status', 'leased')->where('lease_token', $leaseToken)->update([
            'status' => 'pending',
            'available_at' => now()->addSeconds(self::RETRY_SECONDS),
            'lease_expires_at' => null,
            'lease_token' => null,
            'last_error' => $reason,
        ]);
    }
}
