<?php

namespace App\Services;

use App\Models\Client;
use App\Tenant\CurrentTenant;
use Throwable;

class ClientBulkDeleter
{
    public function __construct(private ClientManager $clients) {}

    /**
     * Re-reads each id inside the tenant scope. Foreign ids are skipped.
     *
     * @param  list<int>  $ids
     * @return array{deleted: int, skipped: int, failed: int}
     */
    public function deleteIds(int $accountId, array $ids): array
    {
        return resolve(CurrentTenant::class)->run($accountId, function () use ($ids) {

            $deleted = 0;
            $skipped = 0;
            $failed = 0;

            foreach (array_chunk(array_values(array_unique(array_map(intval(...), $ids))), 50) as $chunk) {
                $clients = Client::query()->whereIn('id', $chunk)->get();
                $skipped += count($chunk) - $clients->count();

                foreach ($clients as $client) {
                    try {
                        $this->clients->delete($client);
                        $deleted++;
                    } catch (Throwable) {
                        $failed++;
                    }
                }
            }

            return ['deleted' => $deleted, 'skipped' => $skipped, 'failed' => $failed];

        });
    }
}
