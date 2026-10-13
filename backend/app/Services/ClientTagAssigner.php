<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Tag;
use App\Tenant\CurrentTenant;
use Illuminate\Support\Facades\DB;

class ClientTagAssigner
{
    /**
     * Attach or detach tags that belong to the account. Foreign clients and tags are skipped.
     *
     * @param  list<int>  $clientIds
     * @param  list<int>  $tagIds
     */
    public function apply(int $accountId, array $clientIds, array $tagIds, string $action): int
    {
        return resolve(CurrentTenant::class)->run($accountId, function () use ($accountId, $clientIds, $tagIds, $action) {

            $clients = Client::query()->whereIn('id', $clientIds)->pluck('id');
            $tags = Tag::query()->whereIn('id', $tagIds)->pluck('id');

            if ($clients->isEmpty() || $tags->isEmpty()) {
                return 0;
            }

            $touched = 0;

            foreach ($clients->chunk(200) as $chunk) {
                $chunkIds = $chunk->all();

                if ($action === 'detach') {
                    DB::table('client_tag')
                        ->where('account_id', $accountId)
                        ->whereIn('client_id', $chunkIds)
                        ->whereIn('tag_id', $tags->all())
                        ->delete();
                } else {
                    $rows = [];

                    foreach ($chunkIds as $clientId) {
                        foreach ($tags as $tagId) {
                            $rows[] = [
                                'account_id' => $accountId,
                                'client_id' => $clientId,
                                'tag_id' => $tagId,
                            ];
                        }
                    }

                    foreach (array_chunk($rows, 500) as $batch) {
                        DB::table('client_tag')->insertOrIgnore($batch);
                    }
                }

                $touched += count($chunkIds);
            }

            return $touched;

        });
    }
}
