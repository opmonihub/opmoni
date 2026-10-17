<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountContractingCertificate;
use App\Models\SerproConnection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PlatformCredentialSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_vault_is_unique_and_restricted_and_key_is_hidden_encrypted(): void
    {
        $this->assertTrue(Schema::hasTable('account_contracting_certificates'));
        $certificate = AccountContractingCertificate::factory()->create();
        $this->rejects(fn () => AccountContractingCertificate::factory()->create(['account_id' => $certificate->account_id]));
        $this->rejects(fn () => Account::whereKey($certificate->account_id)->delete());
        $connection = SerproConnection::factory()->create(['consumer_key' => 'preserved-consumer-key']);
        $this->assertSame('preserved-consumer-key', $connection->fresh()->consumer_key);
        $this->assertSame('preserved-consumer-key', Crypt::decryptString(DB::table('serpro_connections')->value('consumer_key')));
        $this->assertArrayNotHasKey('consumer_key', $connection->toArray());
    }

    public function test_source_fk_cannot_link_a_different_account_or_be_deleted_while_attached(): void
    {
        $certificate = AccountContractingCertificate::factory()->create();
        $other = Account::factory()->create();
        $connection = SerproConnection::factory()->create([
            'contracting_account_id' => $certificate->account_id,
            'account_contracting_certificate_id' => $certificate->id,
        ]);
        $this->rejects(fn () => DB::table('serpro_connections')->where('id', $connection->id)->update(['contracting_account_id' => $other->id]));
        $this->rejects(fn () => $certificate->delete());
        $this->rejects(fn () => $connection->forceFill(['certificate_encrypted' => Crypt::encryptString('duplicate')])->save());
    }

    public function test_rollback_refuses_to_destroy_existing_platform_vault(): void
    {
        AccountContractingCertificate::factory()->create();
        $migration = require database_path('migrations/2026_10_17_230000_add_platform_credential_lifecycle.php');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Platform vault contains credentials');
        $migration->down();
    }

    #[DataProvider('invalidConnectionValues')]
    public function test_connection_constraints_reject_invalid_values(array $values): void
    {
        $this->assertTrue(Schema::hasColumns('serpro_connections', array_keys($values)));
        $connection = SerproConnection::factory()->create();
        $this->rejects(fn () => DB::table('serpro_connections')->where('id', $connection->id)->update($values));
    }

    public static function invalidConnectionValues(): array
    {
        return [
            [['singleton' => 2]], [['state' => 'invented']], [['revision' => 0]],
            [['account_contracting_certificate_id' => 999999]],
        ];
    }

    public function test_key_migration_preserves_historical_plaintext_and_existing_source(): void
    {
        $migration = require database_path('migrations/2026_10_17_230000_add_platform_credential_lifecycle.php');
        $migration->down();
        $account = Account::factory()->create();
        $id = DB::table('serpro_connections')->insertGetId([
            'consumer_key' => 'historical-key', 'consumer_secret_encrypted' => Crypt::encryptString('historical-secret'),
            'contratante_numero' => '12345678000195', 'contracting_account_id' => $account->id,
        ]);
        $migration->up();
        $row = DB::table('serpro_connections')->where('id', $id)->first();
        $this->assertNotSame('historical-key', $row->consumer_key);
        $this->assertSame('historical-key', Crypt::decryptString($row->consumer_key));
        $this->assertSame('historical-secret', Crypt::decryptString($row->consumer_secret_encrypted));
        $this->assertSame($account->id, $row->contracting_account_id);
        $this->assertNull($row->account_contracting_certificate_id);
        $this->assertDatabaseCount('account_contracting_certificates', 0);
    }

    private function rejects(callable $mutation): void
    {
        try {
            DB::transaction($mutation);
            $this->fail('The database accepted invalid platform credential data.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
