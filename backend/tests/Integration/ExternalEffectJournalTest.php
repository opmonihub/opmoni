<?php

namespace Tests\Integration;

use App\Models\Account;
use App\Services\ExternalEffectJournal;
use App\Services\ExternalEffectJournalConnection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class ExternalEffectJournalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('pgsql', config('database.default'));
        $this->assertSame('opmoni_test', DB::connection()->getDatabaseName());
        $this->assertSame('opmoni_test', DB::selectOne('select current_database() as name')->name);
        $this->assertSame('13', (string) config('database.redis.default.database'));
        $this->assertSame('14', (string) config('database.redis.cache.database'));
        $this->assertSame('15', (string) config('database.redis.sessions.database'));
        $this->assertSame('opmoni-access-test-', config('database.redis.options.prefix'));
        $this->assertStringStartsWith('opmoni-access-test-cache-', config('cache.prefix'));
        $this->assertSame('13', (string) Redis::connection('default')->client()->getDBNum());
        $this->assertSame('14', (string) Redis::connection('cache')->client()->getDBNum());
        $this->assertSame('15', (string) Redis::connection('sessions')->client()->getDBNum());
        $this->assertSame('opmoni-access-test-', Redis::connection('cache')->client()->getOption(\Redis::OPT_PREFIX));
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
    }

    public function test_receipt_survives_primary_rollback_with_account_row_locked_and_independent_pdo(): void
    {
        $account = Account::factory()->create();
        $primary = DB::connection();
        $factory = new ExternalEffectJournalConnection;
        $journal = new ExternalEffectJournal($factory);
        $this->assertNotSame($primary->getPdo(), $factory->connection()->getPdo());
        $this->assertSame(0, $factory->connection()->transactionLevel());
        $this->assertFalse($factory->connection()->getPdo()->inTransaction());
        $primary->beginTransaction();
        try {
            DB::table('accounts')->where('id', $account->id)->lockForUpdate()->first();
            DB::table('accounts')->where('id', $account->id)->update(['name' => 'rollback-name']);
            $effect = $journal->begin($account->id, 123, ExternalEffectJournal::resourceHash($account->id, 'mailbox', ['id' => 'message-1']), 'serpro.mailbox.read');
            $effect = $journal->arm($effect);
            $effect = $journal->confirm($effect, 'applied', ['response_id' => 'pg-receipt', 'http_status' => 200]);
            $uncertain = $journal->arm($journal->begin($account->id, 123, ExternalEffectJournal::resourceHash($account->id, 'mailbox', ['id' => 'message-2']), 'serpro.mailbox.read'));
        } finally {
            $primary->rollBack();
        }
        $this->assertNotSame('rollback-name', $account->fresh()->name);
        $fresh = (new ExternalEffectJournal(new ExternalEffectJournalConnection))->find($account->id, $effect['operation_id'], $effect['resource_hash'], 'serpro.mailbox.read');
        $this->assertSame('uncertain', (new ExternalEffectJournal(new ExternalEffectJournalConnection))->find($account->id, $uncertain['operation_id'], $uncertain['resource_hash'], 'serpro.mailbox.read')['state']);
        $this->assertSame('confirmed', $fresh['state']);
        $this->assertSame('applied', $fresh['receipt']);
        $this->assertSame('pg-receipt', $fresh['payload']['response_id']);
        $this->assertSame('1500ms', $factory->connection()->selectOne('show statement_timeout')->statement_timeout);
        $this->assertSame('500ms', $factory->connection()->selectOne('show lock_timeout')->lock_timeout);
    }
}
