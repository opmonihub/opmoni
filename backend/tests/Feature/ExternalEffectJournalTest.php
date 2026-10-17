<?php

namespace Tests\Feature;

use App\Services\ExternalEffectJournal;
use App\Services\ExternalEffectJournalConnection;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class ExternalEffectJournalTest extends TestCase
{
    private string $databaseFile;

    private Connection $primary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->databaseFile = tempnam(sys_get_temp_dir(), 'journal-contract-');
        config(['database.default' => 'journal_primary', 'database.connections.journal_primary' => [
            'driver' => 'sqlite', 'database' => $this->databaseFile, 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        $this->primary = DB::connection();
        (require database_path('migrations/2026_10_15_202558_create_external_effect_journal_table.php'))->up();
    }

    protected function tearDown(): void
    {
        DB::purge('journal_primary');
        @unlink($this->databaseFile);
        parent::tearDown();
    }

    public function test_independent_autocommit_cas_and_account_scoping(): void
    {
        $factory = new ExternalEffectJournalConnection;
        $connection = $factory->connection();
        $this->assertNotSame($this->primary->getPdo(), $connection->getPdo());
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($connection->getPdo()->inTransaction());
        $this->assertFalse($connection->logging());
        $journal = new ExternalEffectJournal($factory);
        $resource = ExternalEffectJournal::resourceHash(7, 'mailbox', ['client_id' => 9, 'message_id' => 'abc']);
        $this->assertSame($resource, ExternalEffectJournal::resourceHash(7, 'mailbox', ['message_id' => 'abc', 'client_id' => 9]));
        $effect = $journal->begin(7, 8, $resource, 'serpro.mailbox.read');
        $armed = $journal->arm($effect);
        $this->assertSame('uncertain', $armed['state']);
        $this->assertSame(2, $armed['version']);
        $this->assertNull($journal->find(99, $effect['operation_id'], $resource, 'serpro.mailbox.read'));
        $this->assertSame([], $journal->forResource(99, $resource));
        $confirmed = $journal->confirm($armed, 'applied', ['response_id' => 'receipt-1', 'http_status' => 200]);
        $this->assertSame('confirmed', $confirmed['state']);
        $this->assertSame(['response_id' => 'receipt-1', 'http_status' => 200], $confirmed['payload']);
        $raw = $connection->table('external_effect_journal')->first();
        $this->assertStringNotContainsString('receipt-1', $raw->payload_encrypted);
        $this->assertSame('confirmed', (new ExternalEffectJournal(new ExternalEffectJournalConnection))->find(7, $effect['operation_id'], $resource, 'serpro.mailbox.read')['state']);
        $this->expectException(RuntimeException::class);
        $journal->confirm($armed, 'not_applied', []);
    }

    public function test_mailbox_receipt_preserves_nullable_iso_dates_and_absence_without_message_content(): void
    {
        $journal = new ExternalEffectJournal(new ExternalEffectJournalConnection);
        $effect = $journal->arm($journal->begin(1, 2, ExternalEffectJournal::resourceHash(1, 'mailbox', ['id' => 'dates']), 'serpro.mailbox.read'));
        $payload = ['lida_em' => '2026-10-15T20:00:00.123456Z', 'ciencia_em' => null, 'prazo_limite' => '2026-11-01',
            'request_tag' => '23368311100010723368311100087501', 'provider_code' => 'Sucesso-01', 'codigo' => 'SC01'];
        $confirmed = $journal->confirm($effect, 'applied', $payload);
        $this->assertSame($payload, $confirmed['payload']);
        $effect = $journal->arm($journal->begin(1, 2, ExternalEffectJournal::resourceHash(1, 'mailbox', ['id' => 'missing']), 'serpro.mailbox.read'));
        $confirmed = $journal->confirm($effect, 'applied', ['lida_em' => '2026-10-15T17:00:00-03:00']);
        $this->assertArrayNotHasKey('ciencia_em', $confirmed['payload']);
        $this->assertArrayNotHasKey('prazo_limite', $confirmed['payload']);
    }

    public function test_mailbox_receipt_rejects_invalid_dates_and_free_text_codes(): void
    {
        $journal = new ExternalEffectJournal(new ExternalEffectJournalConnection);
        foreach ([['lida_em' => 'tomorrow'], ['prazo_limite' => '2026-02-30'], ['ciencia_em' => '2026-10-15T25:00:00Z'], ['provider_code' => 'provider free text'], ['request_tag' => '<unsafe>'], ['assunto' => 'private']] as $index => $payload) {
            $effect = $journal->arm($journal->begin(1, 2, ExternalEffectJournal::resourceHash(1, 'mailbox', ['id' => $index]), 'serpro.mailbox.read'));
            try {
                $journal->confirm($effect, 'applied', $payload);
                $this->fail('Invalid receipt metadata must be rejected.');
            } catch (\InvalidArgumentException) {
                $this->assertSame('uncertain', $journal->find(1, $effect['operation_id'], $effect['resource_hash'], 'serpro.mailbox.read')['state']);
            }
        }
    }

    public function test_whatsapp_error_status_is_a_valid_encrypted_receipt(): void
    {
        $journal = new ExternalEffectJournal(new ExternalEffectJournalConnection);
        $effect = $journal->arm($journal->begin(1, 2, ExternalEffectJournal::resourceHash(1, 'whatsapp', ['id' => 'error']), 'whatsapp.status'));
        $this->assertSame('error', $journal->confirm($effect, 'applied', ['status' => 'error'])['payload']['status']);
    }

    public function test_explicit_test_factory_cannot_reuse_primary_pdo(): void
    {
        $factory = new ExternalEffectJournalConnection(fn (): Connection => $this->primary);
        $this->expectException(RuntimeException::class);
        $factory->connection();
    }

    public function test_explicit_isolated_test_fixture_remains_autocommit_during_primary_transaction(): void
    {
        $fixture = DB::build(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $factory = new ExternalEffectJournalConnection(fn (): Connection => $fixture);
        $this->primary->beginTransaction();
        try {
            $connection = $factory->connection();
            $this->assertNotSame($this->primary->getPdo(), $connection->getPdo());
            $this->assertSame(0, $connection->transactionLevel());
            $this->assertFalse($connection->getPdo()->inTransaction());
        } finally {
            $this->primary->rollBack();
            $fixture->disconnect();
        }
    }

    public function test_wrong_owner_cannot_arm_and_unknown_operation_cannot_be_imported(): void
    {
        $journal = new ExternalEffectJournal(new ExternalEffectJournalConnection);
        $resource = ExternalEffectJournal::resourceHash(1, 'mailbox', ['id' => 'x']);
        $effect = $journal->begin(1, 2, $resource, 'serpro.mailbox.read');
        $wrongOwner = $effect;
        $wrongOwner['owner_token'] = (string) Str::uuid();
        try {
            $journal->arm($wrongOwner);
            $this->fail('Owner CAS must reject another owner.');
        } catch (RuntimeException) {
            $this->assertSame('pending', $journal->find(1, $effect['operation_id'], $resource, 'serpro.mailbox.read')['state']);
        }
        $next = $journal->begin(1, 2, $resource, 'serpro.sitfis.protocol', $effect['operation_id']);
        $this->assertSame($effect['operation_id'], $next['operation_id']);
        $this->expectException(\InvalidArgumentException::class);
        $journal->begin(99, 2, $resource, 'serpro.sitfis.protocol', $effect['operation_id']);
    }

    public function test_query_events_and_encrypted_bindings_are_not_dispatched(): void
    {
        $factory = new ExternalEffectJournalConnection;
        $events = [];
        DB::listen(function ($query) use (&$events): void {
            $events[] = $query;
        });
        $journal = new ExternalEffectJournal($factory);
        $effect = $journal->arm($journal->begin(1, 2, ExternalEffectJournal::resourceHash(1, 'whatsapp', ['id' => 'x']), 'whatsapp.instance.create'));
        $confirmed = $journal->confirm($effect, 'applied', ['instance_id' => 'inst-1', 'instance_api_key' => 'sensitive-onetime-key']);
        $this->assertSame('sensitive-onetime-key', $confirmed['payload']['instance_api_key']);
        $this->assertSame([], $events);
        $this->assertSame([], $factory->connection()->getQueryLog());
    }

    public function test_uncertain_is_never_rearmed_even_after_time_passes(): void
    {
        $journal = new ExternalEffectJournal(new ExternalEffectJournalConnection);
        $effect = $journal->begin(1, 2, ExternalEffectJournal::resourceHash(1, 'mailbox', ['id' => 'x']), 'serpro.mailbox.read');
        $armed = $journal->arm($effect);
        $this->travel(30)->days();
        $this->expectException(RuntimeException::class);
        $journal->arm($armed);
    }

    public function test_payload_is_strictly_allowlisted_and_never_contains_message_body_or_tokens(): void
    {
        $journal = new ExternalEffectJournal(new ExternalEffectJournalConnection);
        $effect = $journal->arm($journal->begin(1, 2, ExternalEffectJournal::resourceHash(1, 'mailbox', ['id' => 'x']), 'serpro.mailbox.read'));
        $this->expectException(\InvalidArgumentException::class);
        $journal->confirm($effect, 'applied', ['body' => 'private', 'access_token' => 'secret']);
    }

    public function test_sqlite_outer_writer_fails_closed_without_sharing_the_primary_transaction(): void
    {
        $journal = new ExternalEffectJournal(new ExternalEffectJournalConnection);
        $this->primary->beginTransaction();
        $this->primary->statement('CREATE TABLE primary_write (id INTEGER)');
        try {
            $this->expectException(RuntimeException::class);
            $journal->begin(1, 2, ExternalEffectJournal::resourceHash(1, 'mailbox', ['id' => 'x']), 'serpro.mailbox.read');
        } finally {
            $this->primary->rollBack();
        }
    }

    public function test_in_memory_default_requires_explicit_test_fixture_instead_of_false_durability(): void
    {
        DB::purge('journal_primary');
        config(['database.connections.journal_primary.database' => ':memory:']);
        $this->expectException(RuntimeException::class);
        (new ExternalEffectJournalConnection)->connection();
    }
}
