<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ClientSelectionStore
{
    private const TTL_SECONDS = 7200;

    /**
     * Snapshot the authorized ids at selection time so later inserts are not included.
     *
     * @param  array<string, mixed>  $filters
     * @param  list<int>  $ids
     */
    public function put(int $accountId, int $userId, array $filters, array $ids): string
    {
        $id = (string) Str::uuid();

        Cache::put($this->key($id), [
            'account_id' => $accountId,
            'user_id' => $userId,
            'filters' => $filters,
            'ids' => array_values(array_map(intval(...), $ids)),
        ], self::TTL_SECONDS);

        return $id;
    }

    /**
     * @return array{account_id: int, user_id: int, filters: array<string, mixed>, ids: list<int>}|null
     */
    public function getFor(string $id, int $accountId, int $userId): ?array
    {
        $payload = Cache::get($this->key($id));

        if (! is_array($payload)) {
            return null;
        }

        if (($payload['account_id'] ?? null) !== $accountId || ($payload['user_id'] ?? null) !== $userId) {
            return null;
        }

        return $payload;
    }

    /**
     * Explicit ids, or the snapshot minus exclusions plus extra ids.
     * Null means the snapshot is missing or belongs to another account or user.
     *
     * @param  list<int>  $explicit
     * @param  list<int>  $excluded
     * @return list<int>|null
     */
    public function resolve(int $accountId, int $userId, array $explicit, ?string $selectionId, array $excluded): ?array
    {
        if ($selectionId === null) {
            return array_values(array_unique($explicit));
        }

        $snapshot = $this->getFor($selectionId, $accountId, $userId);

        if ($snapshot === null) {
            return null;
        }

        $excludedMap = array_flip($excluded);
        $fromSnapshot = array_values(array_filter(
            $snapshot['ids'],
            fn (int $id) => ! isset($excludedMap[$id]),
        ));

        return array_values(array_unique([...$fromSnapshot, ...$explicit]));
    }

    private function key(string $id): string
    {
        return 'client-selection:'.$id;
    }
}
