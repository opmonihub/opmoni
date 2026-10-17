<?php

namespace App\Services\Fiscal\Read;

use App\Enums\FiscalProcessingState;
use App\Enums\FiscalStage;
use App\Enums\FiscalStorageState;
use App\Models\FiscalDocument;
use App\Tenant\CurrentTenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class FiscalProcessing
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *     rows: LengthAwarePaginator<FiscalDocument>,
     *     summary: array{active: int, deferred: int, attention: int}
     * }
     */
    public function page(int $accountId, array $filters): array
    {
        return resolve(CurrentTenant::class)->run($accountId, function () use ($accountId, $filters) {
            $query = $this->baseQuery($accountId, $filters);

            return [
                'rows' => $query
                    ->with(['client'])
                    ->orderByDesc('next_attempt_at')
                    ->orderByDesc('id')
                    ->paginate(min(max((int) ($filters['per_page'] ?? 25), 1), 100))
                    ->withQueryString(),
                'summary' => $this->summaryCounts($accountId),
            ];
        });
    }

    /**
     * @return array{active: int, deferred: int, attention: int}
     */
    public function summaryCounts(int $accountId): array
    {
        $rows = FiscalDocument::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $accountId)
            ->where(function (Builder $query): void {
                $query->where(function (Builder $summary): void {
                    $summary->where('stage', FiscalStage::Summary)
                        ->where(function (Builder $states): void {
                            $states->whereNull('processing_state')
                                ->orWhere('processing_state', '!=', FiscalProcessingState::Complete);
                        });
                })->orWhere(function (Builder $document): void {
                    $document->where('stage', FiscalStage::Document)
                        ->whereIn('storage_state', [
                            FiscalStorageState::Missing,
                            FiscalStorageState::Corrupt,
                            FiscalStorageState::Unverified,
                        ]);
                });
            })
            ->get(['processing_state', 'stage', 'storage_state']);

        $active = 0;
        $deferred = 0;
        $attention = 0;

        foreach ($rows as $row) {
            if ($row->stage === FiscalStage::Document) {
                $attention++;

                continue;
            }

            if ($row->processing_state === FiscalProcessingState::Deferred) {
                $deferred++;
            } elseif ($row->processing_state === FiscalProcessingState::Attention) {
                $attention++;
            } else {
                $active++;
            }
        }

        return compact('active', 'deferred', 'attention');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<FiscalDocument>
     */
    private function baseQuery(int $accountId, array $filters): Builder
    {
        return FiscalDocument::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $accountId)
            ->when(
                isset($filters['client_id']),
                fn (Builder $query): Builder => $query->where('client_id', (int) $filters['client_id'])
            )
            ->when(
                filled($filters['state'] ?? null),
                function (Builder $query) use ($filters): void {
                    if ($filters['state'] === 'attention') {
                        $query->where(function (Builder $inner): void {
                            $inner->where('processing_state', FiscalProcessingState::Attention)
                                ->orWhereIn('storage_state', [FiscalStorageState::Missing, FiscalStorageState::Corrupt]);
                        });
                    } else {
                        $query->where('processing_state', $filters['state']);
                    }
                }
            )
            ->where(function (Builder $query): void {
                $query->where(function (Builder $summary): void {
                    $summary->where('stage', FiscalStage::Summary)
                        ->where(function (Builder $states): void {
                            $states->whereNull('processing_state')
                                ->orWhereIn('processing_state', [
                                    FiscalProcessingState::AwaitingScience,
                                    FiscalProcessingState::ScienceQueued,
                                    FiscalProcessingState::AwaitingXml,
                                    FiscalProcessingState::RecoveryQueued,
                                    FiscalProcessingState::Deferred,
                                    FiscalProcessingState::Attention,
                                ]);
                        });
                })->orWhere(function (Builder $document): void {
                    $document->where('stage', FiscalStage::Document)
                        ->whereIn('storage_state', [
                            FiscalStorageState::Missing,
                            FiscalStorageState::Corrupt,
                            FiscalStorageState::Unverified,
                        ]);
                });
            });
    }
}
