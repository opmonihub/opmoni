<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountCertificate;
use App\Models\AccountContractingCertificate;
use App\Models\SerproConnection;
use App\Services\SerproCredentialLifecycle;
use App\Services\SerproTokenProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SerproOperationalSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public static function sources(): array
    {
        return ['upload' => ['upload'], 'platform vault' => ['account'], 'legacy tenant vault' => ['legacy_account']];
    }

    #[DataProvider('sources')]
    public function test_cached_pair_reads_one_coherent_generation_inside_tenant_transaction(string $source): void
    {
        Http::preventStrayRequests();
        $account = Account::factory()->create();
        $connection = SerproConnection::factory()->create();
        if ($source !== 'upload') {
            $certificate = $source === 'account'
                ? AccountContractingCertificate::factory()->create(['account_id' => $account->id])
                : AccountCertificate::factory()->create(['account_id' => $account->id]);
            $connection->forceFill([
                'contracting_account_id' => $account->id,
                'account_contracting_certificate_id' => $source === 'account' ? $certificate->id : null,
                'certificate_encrypted' => null, 'certificate_password_encrypted' => null,
            ])->save();
        }
        $snapshot = app(SerproCredentialLifecycle::class)->snapshot();
        Cache::put('serpro:token-pair:'.$snapshot->generation(), [
            'access_token' => 'cached-access', 'jwt_token' => 'cached-jwt', 'expires_in' => 3600,
        ], 60);

        DB::transaction(function () use ($account): void {
            Account::whereKey($account->id)->lockForUpdate()->firstOrFail();
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                $pair = app(SerproTokenProvider::class)->pair();
                $queries = DB::getQueryLog();
            } finally {
                DB::disableQueryLog();
            }
            $this->assertSame('cached-jwt', $pair->jwtToken());
            $this->assertCount(1, $queries, 'Operational snapshot must use one statement without credential locks.');
            $this->assertStringNotContainsString('for update', strtolower($queries[0]['query']));
        });
        Http::assertNothingSent();
    }
}
