<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\ClientBulkDeleter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class DeleteClientsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    /**
     * @param  list<int>  $ids
     */
    public function __construct(
        public string $operationId,
        public int $accountId,
        public int $userId,
        public array $ids,
    ) {}

    public function handle(ClientBulkDeleter $deleter): void
    {
        $user = User::query()->find($this->userId);
        $allowed = $user !== null && (
            in_array($user->accountRole($this->accountId), ['admin', 'operador'], true)
        );

        if (! $allowed) {
            $this->store(['status' => 'failed', 'deleted' => 0, 'skipped' => count($this->ids), 'failed' => 0]);

            return;
        }

        $result = $deleter->deleteIds($this->accountId, $this->ids);
        $this->store(['status' => 'completed', ...$result]);
    }

    public function failed(Throwable $exception): void
    {
        $this->store([
            'status' => 'failed',
            'deleted' => 0,
            'skipped' => 0,
            'failed' => count($this->ids),
        ]);
    }

    /**
     * @param  array{status: string, deleted: int, skipped: int, failed: int}  $result
     */
    private function store(array $result): void
    {
        Cache::put('client-bulk-deletion:'.$this->operationId, [
            ...$result,
            'account_id' => $this->accountId,
            'user_id' => $this->userId,
            'total' => count($this->ids),
        ], 7200);
    }
}
