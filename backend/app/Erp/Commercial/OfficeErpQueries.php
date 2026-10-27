<?php

namespace App\Erp\Commercial;

use App\Http\Requests\OfficeErp\ListCommercialRequest;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Client;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpInvoice;
use App\Models\Erp\Commercial\OfficeErpLicenceAllocation;
use App\Models\Erp\Commercial\OfficeErpReceipt;
use App\Models\Erp\Commercial\OfficeErpSalesPlan;
use App\Models\User;
use App\Support\LiteralSearch;
use App\Support\ValidatedListOrdering;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class OfficeErpQueries
{
    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function summary(OfficeErpExecutionContext $ctx, array $filters): array
    {
        $this->assertReportAuthority($ctx);
        $filters = ListCommercialRequest::validateFilters('summary', $filters);
        $timezone = $this->timezone($ctx);
        [$from, $to] = $this->periodBounds($filters, $timezone);
        $movement = '0.00';
        $effectiveReceived = '0.00';
        $outstanding = '0.00';
        $overdue = '0.00';

        OfficeErpRecords::query($ctx, OfficeErpInvoice::class)
            ->with(['adjustments', 'allocations.receipt.reversal'])
            ->where('document_status', 'issued')
            ->orderBy('id')
            ->chunkById(200, function ($invoices) use ($from, $to, $timezone, $filters, &$movement, &$outstanding, &$overdue): void {
                foreach ($invoices as $invoice) {
                    $issuedAt = $invoice->issued_at?->copy()->setTimezone($timezone);
                    if ($issuedAt !== null && $this->between($issuedAt->toDateString(), $from, $to)) {
                        $movement = OfficeErpMoney::add(
                            $movement,
                            OfficeErpMoney::canonical((string) $invoice->getRawOriginal('original_total'), 'original_total'),
                        );
                    }
                    foreach ($invoice->adjustments as $adjustment) {
                        $postedAt = $adjustment->posted_at?->copy()->setTimezone($timezone);
                        if ($postedAt === null || ! $this->between($postedAt->toDateString(), $from, $to)) {
                            continue;
                        }
                        $amount = OfficeErpMoney::canonical((string) $adjustment->getRawOriginal('amount'), 'amount');
                        $movement = $adjustment->direction === 'credit'
                            ? OfficeErpMoney::subtract($movement, $amount)
                            : OfficeErpMoney::add($movement, $amount);
                    }

                    $dueOn = $invoice->due_on->toDateString();
                    if ((! isset($filters['due_from']) || $dueOn >= $filters['due_from'])
                        && (! isset($filters['due_to']) || $dueOn <= $filters['due_to'])) {
                        $balance = OfficeErpBalances::forInvoice($invoice);
                        $outstanding = OfficeErpMoney::add($outstanding, $balance['outstanding']);
                        if ($balance['overdue']) {
                            $overdue = OfficeErpMoney::add($overdue, $balance['outstanding']);
                        }
                    }
                }
            });

        OfficeErpRecords::query($ctx, OfficeErpReceipt::class)
            ->whereDoesntHave('reversal')
            ->whereBetween('received_on', [$from, $to])
            ->orderBy('id')
            ->chunkById(200, function ($receipts) use (&$effectiveReceived): void {
                foreach ($receipts as $receipt) {
                    $effectiveReceived = OfficeErpMoney::add(
                        $effectiveReceived,
                        OfficeErpMoney::canonical((string) $receipt->getRawOriginal('amount'), 'amount'),
                    );
                }
            });

        $allocations = OfficeErpRecords::query($ctx, OfficeErpLicenceAllocation::class)
            ->where(function (Builder $query): void {
                $query->where('state', 'occupied')
                    ->orWhere(fn (Builder $reserved) => $reserved->where('state', 'reserved')->where('expires_at', '>', now()));
            });

        return [
            'contracted_licences' => (clone $allocations)->where('state', 'occupied')->count(),
            'reserved_licences' => (clone $allocations)->where('state', 'reserved')->count(),
            'invoiced_movement' => $movement,
            'effective_received' => $effectiveReceived,
            'outstanding' => $outstanding,
            'overdue' => $overdue,
            'from_on' => $from,
            'to_on' => $to,
            'due_from' => $filters['due_from'] ?? null,
            'due_to' => $filters['due_to'] ?? null,
        ];
    }

    /** @param array<string, mixed> $filters */
    public function clients(OfficeErpExecutionContext $ctx, array $filters): LengthAwarePaginator
    {
        $filters = $this->filters($ctx, 'clients', $filters);
        $query = Client::query()
            ->where('account_id', $ctx->accountId)
            ->where('status', 'active')
            ->withTrashed(false);
        if (($search = trim((string) ($filters['search'] ?? ''))) !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where(fn (Builder $matches) => LiteralSearch::whereContains($matches, 'name', $search))
                    ->orWhere(fn (Builder $matches) => LiteralSearch::whereContains($matches, 'trade_name', $search))
                    ->orWhere(fn (Builder $matches) => LiteralSearch::whereContains($matches, 'tax_id', $search));
            });
        }

        $sort = $filters['sort'] ?? null;
        if ($sort === null) {
            $query->orderBy('name');
        } else {
            ValidatedListOrdering::apply($query, $sort, $filters['direction'] ?? null, [
                'id' => 'clients.id',
                'name' => 'clients.name',
            ], 'clients.id');
        }

        return $ctx->run(fn (): LengthAwarePaginator => $this->paginate($query, $filters));
    }

    /** @param array<string, mixed> $filters */
    public function plans(OfficeErpExecutionContext $ctx, array $filters): LengthAwarePaginator
    {
        $filters = $this->filters($ctx, 'plans', $filters);
        $query = OfficeErpRecords::query($ctx, OfficeErpSalesPlan::class)->with('currentRevision');
        $query->where('status', $filters['status'] ?? 'active');
        if (isset($filters['search']) && $filters['search'] !== '') {
            LiteralSearch::whereContains($query, 'name', $filters['search']);
        }

        $sort = $filters['sort'] ?? null;
        if ($sort === null) {
            $query->orderBy('name');
        } else {
            ValidatedListOrdering::apply($query, $sort, $filters['direction'] ?? null, [
                'id' => 'office_erp_sales_plans.id',
                'name' => 'office_erp_sales_plans.name',
                'status' => 'office_erp_sales_plans.status',
            ], 'office_erp_sales_plans.id');
        }

        return $ctx->run(fn (): LengthAwarePaginator => $this->paginate($query, $filters));
    }

    /** @param array<string, mixed> $filters */
    public function contracts(OfficeErpExecutionContext $ctx, array $filters): LengthAwarePaginator
    {
        $filters = $this->filters($ctx, 'contracts', $filters);
        $query = OfficeErpRecords::query($ctx, OfficeErpContract::class)
            ->with([
                'client', 'company.subscription.trial', 'company.subscription.plan', 'currentRevision', 'pendingRevision', 'licenceAllocation',
            ]);
        if (isset($filters['status'])) {
            $query->where('agreement_status', $filters['status']);
        }
        if (isset($filters['search']) && $filters['search'] !== '') {
            $search = $filters['search'];
            $query->whereHas('client', fn (Builder $client) => $client
                ->where(fn (Builder $matches) => LiteralSearch::whereContains($matches, 'name', $search))
                ->orWhere(fn (Builder $matches) => LiteralSearch::whereContains($matches, 'trade_name', $search)));
        }

        $sort = $filters['sort'] ?? null;
        if ($sort === null) {
            $query->orderByDesc('id');
        } else {
            ValidatedListOrdering::apply($query, $sort, $filters['direction'] ?? null, [
                'id' => 'office_erp_contracts.id',
                'client' => static function (Builder $builder, string $direction) use ($ctx): void {
                    $clientName = Client::query()
                        ->select('name')
                        ->whereColumn('clients.id', 'office_erp_contracts.client_id')
                        ->where('clients.account_id', $ctx->accountId);

                    $builder->orderByRaw('('.$clientName->toSql().') IS NULL ASC', $clientName->getBindings())
                        ->orderBy($clientName, $direction);
                },
                'agreement_status' => 'office_erp_contracts.agreement_status',
            ], 'office_erp_contracts.id');
        }

        return $ctx->run(fn (): LengthAwarePaginator => $this->paginate($query, $filters));
    }

    /** @param array<string, mixed> $filters */
    public function invoices(OfficeErpExecutionContext $ctx, array $filters): LengthAwarePaginator
    {
        $filters = $this->filters($ctx, 'invoices', $filters);
        $query = OfficeErpRecords::query($ctx, OfficeErpInvoice::class)
            ->with(['contract.client', 'contract.company', 'contract.currentRevision', 'adjustments', 'allocations.receipt.reversal']);
        if (isset($filters['status'])) {
            $query->where('document_status', $filters['status']);
        }
        if (isset($filters['contract_id'])) {
            $query->where('contract_id', $filters['contract_id']);
        }
        if (isset($filters['search']) && $filters['search'] !== '') {
            $search = $filters['search'];
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where(fn (Builder $matches) => LiteralSearch::whereContains($matches, 'number', $search))
                    ->orWhereHas('contract.client', fn (Builder $client) => $client
                        ->where(fn (Builder $matches) => LiteralSearch::whereContains($matches, 'name', $search))
                        ->orWhere(fn (Builder $matches) => LiteralSearch::whereContains($matches, 'trade_name', $search)))
                    ->orWhereHas('contract.company', fn (Builder $company) => LiteralSearch::whereContains($company, 'name', $search));
            });
        }
        $this->applyDateFilters($query, $filters, 'issued_at', $this->timezone($ctx));
        if (isset($filters['due_from'])) {
            $query->whereDate('due_on', '>=', $filters['due_from']);
        }
        if (isset($filters['due_to'])) {
            $query->whereDate('due_on', '<=', $filters['due_to']);
        }

        $sort = $filters['sort'] ?? null;
        if ($sort === null) {
            $query->orderByDesc('issued_at')->orderByDesc('id');
        } else {
            ValidatedListOrdering::apply($query, $sort, $filters['direction'] ?? null, [
                'id' => 'office_erp_invoices.id',
                'number' => 'office_erp_invoices.number',
                'due_on' => 'office_erp_invoices.due_on',
                'document_status' => 'office_erp_invoices.document_status',
            ], 'office_erp_invoices.id');
        }

        return $ctx->run(fn (): LengthAwarePaginator => $this->paginate($query, $filters));
    }

    /** @param array<string, mixed> $filters */
    public function receipts(OfficeErpExecutionContext $ctx, array $filters): LengthAwarePaginator
    {
        $filters = $this->filters($ctx, 'receipts', $filters);
        $query = OfficeErpRecords::query($ctx, OfficeErpReceipt::class)->with(['reversal', 'allocations.invoice.contract.client']);
        if (isset($filters['status'])) {
            $filters['status'] === 'reversed'
                ? $query->whereHas('reversal')
                : $query->whereDoesntHave('reversal');
        }
        if (isset($filters['search']) && $filters['search'] !== '') {
            LiteralSearch::whereContains($query, 'reference', $filters['search']);
        }
        if (isset($filters['from_on'])) {
            $query->whereDate('received_on', '>=', $filters['from_on']);
        }
        if (isset($filters['to_on'])) {
            $query->whereDate('received_on', '<=', $filters['to_on']);
        }

        $sort = $filters['sort'] ?? null;
        if ($sort === null) {
            $query->orderByDesc('received_on')->orderByDesc('id');
        } else {
            ValidatedListOrdering::apply($query, $sort, $filters['direction'] ?? null, [
                'id' => 'office_erp_receipts.id',
                'received_on' => 'office_erp_receipts.received_on',
                'reference' => 'office_erp_receipts.reference',
            ], 'office_erp_receipts.id');
        }

        return $ctx->run(fn (): LengthAwarePaginator => $this->paginate($query, $filters));
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function filters(OfficeErpExecutionContext $ctx, string $resource, array $filters): array
    {
        $this->assertReportAuthority($ctx);

        return ListCommercialRequest::validateFilters($resource, $filters);
    }

    private function assertReportAuthority(OfficeErpExecutionContext $ctx): void
    {
        $ctx->assertVerified();
        abort_unless($ctx->actorKind === 'office_admin' && $ctx->actor instanceof User, 403);
        abort_unless(Account::query()->whereKey($ctx->accountId)->where('status', 'active')->exists(), 403);
        abort_unless(AccountMembership::query()
            ->where('account_id', $ctx->accountId)
            ->where('user_id', $ctx->actor->getKey())
            ->where('role', 'admin')
            ->exists(), 403);
    }

    private function timezone(OfficeErpExecutionContext $ctx): string
    {
        return (string) DB::table('office_erp_commercial_settings')
            ->where('account_id', $ctx->accountId)
            ->value('billing_timezone') ?: 'America/Sao_Paulo';
    }

    /** @param array<string, mixed> $filters
     * @return array{0: string, 1: string}
     */
    private function periodBounds(array $filters, string $timezone): array
    {
        $now = now($timezone);

        return [$filters['from_on'] ?? $now->copy()->startOfMonth()->toDateString(),
            $filters['to_on'] ?? $now->copy()->endOfMonth()->toDateString()];
    }

    private function between(string $date, string $from, string $to): bool
    {
        return $date >= $from && $date <= $to;
    }

    /** @param array<string, mixed> $filters */
    private function applyDateFilters(Builder $query, array $filters, string $column, ?string $timezone = null): void
    {
        if ($timezone === null) {
            if (isset($filters['from_on'])) {
                $query->whereDate($column, '>=', $filters['from_on']);
            }
            if (isset($filters['to_on'])) {
                $query->whereDate($column, '<=', $filters['to_on']);
            }

            return;
        }
        if (isset($filters['from_on'])) {
            $from = Carbon::parse($filters['from_on'], $timezone)->startOfDay()->utc();
            $query->where($column, '>=', $from);
        }
        if (isset($filters['to_on'])) {
            $to = Carbon::parse($filters['to_on'], $timezone)->endOfDay()->utc();
            $query->where($column, '<=', $to);
        }
    }

    /** @param array<string, mixed> $filters */
    private function paginate(Builder $query, array $filters): LengthAwarePaginator
    {
        return $query->paginate(
            (int) ($filters['per_page'] ?? 25),
            ['*'],
            'page',
            (int) ($filters['page'] ?? 1),
        );
    }
}
