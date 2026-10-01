<?php

namespace App\Services;

use App\Enums\ClientStatus;
use App\Enums\DeadlineStatus;
use App\Models\Client;
use App\Support\BrazilianRegions;
use App\Tenant\CurrentTenant;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class ClientPortfolio
{
    /**
     * Tenant scope comes from BelongsToAccount. Filters run before sort and pagination.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Client>
     */
    public function filtered(array $filters): Builder
    {
        return Client::query()
            ->when(
                isset($filters['client_id']) && $filters['client_id'] !== null && $filters['client_id'] !== '',
                fn (Builder $query): Builder => $query->whereKey((int) $filters['client_id'])
            )
            ->search($filters['q'] ?? null)
            ->withStatus($filters['status'] ?? null)
            ->withTaxRegime($filters['tax_regime'] ?? null)
            ->withDeadlineStatus($filters['deadline_status'] ?? null)
            ->withCertificateStatus($filters['certificate_status'] ?? null)
            ->withPoaStatus($filters['poa_status'] ?? null)
            ->withTag(isset($filters['tag_id']) ? (int) $filters['tag_id'] : null)
            ->withPortfolioView($filters['view'] ?? null);
    }

    /**
     * @param  Builder<Client>  $clients
     * @return Builder<Client>
     */
    public function sorted(Builder $clients, string $sort, string $direction): Builder
    {
        return match ($sort) {
            'certificate' => $clients->orderByCurrentCertificate($direction),
            'poa' => $clients->orderByPowerOfAttorney($direction),
            default => $clients->orderBy($sort, $direction),
        };
    }

    /**
     * Counts follow the same portfolio filters as the list, except the document status tab.
     *
     * @param  array<string, mixed>  $filters
     * @return array{
     *     total: int,
     *     active: int,
     *     inactive: int,
     *     certificate: array<string, int>,
     *     poa: array<string, int>
     * }
     */
    public function counts(array $filters): array
    {
        $accountId = resolve(CurrentTenant::class)->accountId;
        $key = 'portfolio:counts:'.$accountId.':'.md5((string) json_encode($filters));

        return Cache::remember($key, 60, fn (): array => $this->countsUncached($filters));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *     total: int,
     *     active: int,
     *     inactive: int,
     *     certificate: array<string, int>,
     *     poa: array<string, int>
     * }
     */
    public function countsUncached(array $filters): array
    {
        unset($filters['view']);
        $base = $this->filtered($filters);
        $statuses = array_map(fn (DeadlineStatus $status): string => $status->value, DeadlineStatus::cases());
        $powerCounts = resolve(ClientPowerOfAttorneySummary::class)->forQuery($base)->countBy('status')->all();

        $bucket = function (string $document) use ($base, $statuses): array {
            $counts = [];

            foreach ($statuses as $status) {
                $counts[$status] = (clone $base)->withPortfolioView("{$document}_{$status}")->count();
            }

            return $counts;
        };

        return [
            'total' => (clone $base)->count(),
            'active' => (clone $base)->where('status', ClientStatus::Active)->count(),
            'inactive' => (clone $base)->where('status', ClientStatus::Inactive)->count(),
            'certificate' => $bucket('certificate'),
            'poa' => array_replace(array_fill_keys($statuses, 0), $powerCounts),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *     by_state: list<array{key: string, count: int}>,
     *     by_region: list<array{key: string, count: int}>,
     *     by_city: list<array{key: string, count: int}>,
     *     by_tax_regime: list<array{key: string, count: int}>,
     *     by_legal_nature: list<array{key: string, count: int}>,
     *     by_activity: list<array{key: string, label: string|null, count: int}>,
     *     growth_by_month: list<array{key: string, count: int}>,
     *     attention: array{
     *         certificate: list<array{id: int, name: string, tax_id: string|null, status: string, expires_at: string|null}>,
     *         poa: list<array{id: int, name: string, tax_id: string|null, status: string, expires_at: string|null}>
     *     }
     * }
     */
    public function analytics(array $filters): array
    {
        $accountId = resolve(CurrentTenant::class)->accountId;
        $key = 'portfolio:analytics:'.$accountId.':'.md5((string) json_encode($filters));

        return Cache::remember($key, 60, fn (): array => $this->analyticsUncached($filters));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *     by_state: list<array{key: string, count: int}>,
     *     by_region: list<array{key: string, count: int}>,
     *     by_city: list<array{key: string, count: int}>,
     *     by_tax_regime: list<array{key: string, count: int}>,
     *     by_legal_nature: list<array{key: string, count: int}>,
     *     by_activity: list<array{key: string, label: string|null, count: int}>,
     *     growth_by_month: list<array{key: string, count: int}>,
     *     attention: array{
     *         certificate: list<array{id: int, name: string, tax_id: string|null, status: string, expires_at: string|null}>,
     *         poa: list<array{id: int, name: string, tax_id: string|null, status: string, expires_at: string|null}>
     *     }
     * }
     */
    public function analyticsUncached(array $filters): array
    {
        unset($filters['view']);
        $base = $this->filtered($filters);

        return [
            'by_state' => $this->groupCounts($base, 'state'),
            'by_region' => $this->regionCounts($base),
            'by_city' => $this->groupCounts($base, 'city', 15),
            'by_tax_regime' => $this->groupCounts($base, 'tax_regime'),
            'by_legal_nature' => $this->groupCounts($base, 'legal_nature', 10),
            'by_activity' => $this->activityCounts($base, 10),
            'growth_by_month' => $this->growthByMonth($base),
            'attention' => [
                'certificate' => $this->attention($base, 'certificate'),
                'poa' => $this->attention($base, 'poa'),
            ],
        ];
    }

    /**
     * @param  Builder<Client>  $base
     * @return list<array{key: string, count: int}>
     */
    private function groupCounts(Builder $base, string $column, ?int $limit = null): array
    {
        $grammar = $base->getQuery()->getGrammar();
        $key = $grammar->wrap('key');
        $count = $grammar->wrap('count');

        $query = (clone $base)
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->selectRaw("{$grammar->wrap($column)} as {$key}, count(*) as {$count}")
            ->groupBy($column)
            ->orderByDesc('count')
            ->orderBy('key');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get()
            ->map(fn ($row): array => [
                'key' => (string) $row->key,
                'count' => (int) $row->count,
            ])
            ->all();
    }

    /**
     * @param  Builder<Client>  $base
     * @return list<array{key: string, count: int}>
     */
    private function regionCounts(Builder $base): array
    {
        $totals = array_fill_keys(BrazilianRegions::names(), 0);

        (clone $base)
            ->whereNotNull('state')
            ->where('state', '!=', '')
            ->selectRaw('state, count(*) as aggregate')
            ->groupBy('state')
            ->get()
            ->each(function ($row) use (&$totals): void {
                $region = BrazilianRegions::forState((string) $row->state);
                if ($region === null) {
                    return;
                }
                $totals[$region] += (int) $row->aggregate;
            });

        return collect($totals)
            ->filter(fn (int $count): bool => $count > 0)
            ->map(fn (int $count, string $key): array => ['key' => $key, 'count' => $count])
            ->values()
            ->all();
    }

    /**
     * @param  Builder<Client>  $base
     * @return list<array{key: string, label: string|null, count: int}>
     */
    private function activityCounts(Builder $base, int $limit): array
    {
        $grammar = $base->getQuery()->getGrammar();
        $key = $grammar->wrap('key');
        $label = $grammar->wrap('label');
        $count = $grammar->wrap('count');

        return (clone $base)
            ->whereNotNull('primary_activity_code')
            ->where('primary_activity_code', '!=', '')
            ->selectRaw("primary_activity_code as {$key}, max(primary_activity_description) as {$label}, count(*) as {$count}")
            ->groupBy('primary_activity_code')
            ->orderByDesc('count')
            ->orderBy('key')
            ->limit($limit)
            ->get()
            ->map(fn ($row): array => [
                'key' => (string) $row->key,
                'label' => $row->label !== null ? (string) $row->label : null,
                'count' => (int) $row->count,
            ])
            ->all();
    }

    /**
     * @param  Builder<Client>  $base
     * @return list<array{key: string, count: int}>
     */
    private function growthByMonth(Builder $base): array
    {
        $end = now()->startOfMonth();
        $start = $end->copy()->subMonths(11);
        $driver = $base->getConnection()->getDriverName();
        $grammar = $base->getQuery()->getGrammar();
        $key = $grammar->wrap('key');
        $count = $grammar->wrap('count');
        $expression = match ($driver) {
            'pgsql' => "to_char(created_at, 'YYYY-MM')",
            'mysql' => "date_format(created_at, '%Y-%m')",
            default => "strftime('%Y-%m', created_at)",
        };

        $counts = (clone $base)
            ->where('created_at', '>=', $start)
            ->selectRaw("{$expression} as {$key}, count(*) as {$count}")
            ->groupBy('key')
            ->pluck('count', 'key');

        $months = [];
        for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addMonth()) {
            $key = $cursor->format('Y-m');
            $months[] = [
                'key' => $key,
                'count' => (int) ($counts[$key] ?? 0),
            ];
        }

        return $months;
    }

    /**
     * @param  Builder<Client>  $base
     * @return list<array{id: int, name: string, tax_id: string|null, status: string, expires_at: string|null}>
     */
    private function attention(Builder $base, string $document): array
    {
        $items = [];
        $limit = 10;

        foreach ([DeadlineStatus::Expired->value, DeadlineStatus::Expiring->value] as $status) {
            if (count($items) >= $limit) {
                break;
            }

            $remaining = $limit - count($items);

            /** @var Collection<int, Client> $clients */
            $clients = (clone $base)
                ->withPortfolioView("{$document}_{$status}")
                ->when($document === 'certificate', fn (Builder $query): Builder => $query->with('currentCertificate'))
                ->orderBy('name')
                ->limit($remaining)
                ->get(['id', 'account_id', 'name', 'tax_id']);

            $summaries = $document === 'poa'
                ? resolve(ClientPowerOfAttorneySummary::class)->forClients($clients)
                : collect();

            foreach ($clients as $client) {
                $expiresAt = $document === 'certificate'
                    ? $client->currentCertificate?->valid_until
                    : $summaries->get($client->getKey())['expires_on'];

                $items[] = [
                    'id' => $client->getKey(),
                    'name' => $client->name,
                    'tax_id' => $client->tax_id,
                    'status' => $status,
                    'expires_at' => $expiresAt instanceof CarbonImmutable || $expiresAt instanceof CarbonInterface
                        ? $expiresAt->toDateString()
                        : ($expiresAt !== null ? (string) $expiresAt : null),
                ];
            }
        }

        return $items;
    }
}
