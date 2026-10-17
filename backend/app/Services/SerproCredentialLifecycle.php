<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountCertificate;
use App\Models\AccountContractingCertificate;
use App\Models\SerproConnection;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SerproCredentialLifecycle
{
    /** Shared by every platform credential writer; transaction scoped on PostgreSQL. */
    public const ADVISORY_LOCK = 72493101;

    /**
     * Discover source Accounts, lock ascending Account IDs, then the lifecycle lock,
     * then the singleton. Recheck discovery before touching source rows. The callback
     * must not acquire additional Account locks or perform provider HTTP.
     *
     * @template T
     *
     * @param  list<int>  $accountIds
     * @param  Closure(?SerproConnection, Collection<int, Account>): T  $callback
     * @return T
     */
    public function locked(array $accountIds, Closure $callback): mixed
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $discovered = SerproConnection::query()->value('contracting_account_id');
            $ids = array_values(array_unique(array_map('intval', array_filter([...$accountIds, $discovered], fn ($id) => $id !== null))));
            sort($ids, SORT_NUMERIC);
            $result = DB::transaction(function () use ($ids, $callback): array {
                $accounts = Account::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                if (DB::getDriverName() === 'pgsql') {
                    DB::select('SELECT pg_advisory_xact_lock(?)', [self::ADVISORY_LOCK]);
                }
                $connection = SerproConnection::query()->lockForUpdate()->first();
                if ($connection?->contracting_account_id !== null && ! in_array((int) $connection->contracting_account_id, $ids, true)) {
                    return ['retry' => true];
                }

                return ['retry' => false, 'value' => $callback($connection, $accounts)];
            }, 3);
            if (! $result['retry']) {
                return $result['value'];
            }
        }

        throw new RuntimeException('Credential source changed repeatedly; retry the operation.');
    }

    public function snapshot(): ?SerproCredentialSnapshot
    {
        return $this->locked([], function (?SerproConnection $connection, Collection $accounts): ?SerproCredentialSnapshot {
            if ($connection === null) {
                return null;
            }
            $source = 'upload';
            $certificate = null;
            if ($connection->account_contracting_certificate_id !== null) {
                $source = 'account';
                $certificate = AccountContractingCertificate::query()->whereKey($connection->account_contracting_certificate_id)->lockForUpdate()->first();
            } elseif ($connection->contracting_account_id !== null) {
                $source = 'legacy_account';
                $certificate = AccountCertificate::query()->withoutGlobalScope('account')->where('account_id', $connection->contracting_account_id)
                    ->whereNull('replaced_at')->whereNull('removed_at')->orderByDesc('id')->lockForUpdate()->first();
            }
            $linked = $source !== 'upload';
            $encrypted = [
                'key' => $connection->getRawOriginal('consumer_key'),
                'secret' => $connection->consumer_secret_encrypted,
                'certificate' => $linked ? $certificate?->certificate_encrypted : $connection->certificate_encrypted,
                'password' => $linked ? $certificate?->password_encrypted : $connection->certificate_password_encrypted,
            ];
            $account = $accounts->get($connection->contracting_account_id);
            $before = $linked ? $certificate?->valid_from : $connection->certificate_valid_from;
            $after = $linked ? $certificate?->valid_until : $connection->certificate_valid_until;
            $identity = [
                $connection->id, $connection->revision, $connection->contracting_account_id, $account?->status,
                $source, $certificate?->id, $certificate?->revision, $certificate?->updated_at?->toISOString(),
                $certificate?->replaced_at?->toISOString(), $certificate?->removed_at?->toISOString(),
                $connection->contratante_numero, $certificate?->document, $before?->toISOString(), $after?->toISOString(), $connection->state,
            ];

            return new SerproCredentialSnapshot(
                $connection->id, $connection->revision, $source, $connection->contracting_account_id, $account?->status,
                $certificate?->id, $certificate?->revision,
                (string) ($linked ? $certificate?->document : $connection->contratante_numero),
                $before === null ? null : CarbonImmutable::instance($before),
                $after === null ? null : CarbonImmutable::instance($after), $connection->state,
                $encrypted, hash('sha256', json_encode([$identity, $encrypted], JSON_THROW_ON_ERROR)),
            );
        });
    }

    public function isCurrent(SerproCredentialSnapshot $snapshot): bool
    {
        $current = $this->snapshot();

        return $current !== null && $snapshot->sameGeneration($current);
    }
}
