<?php

namespace Tests\Feature\WhatsApp;

use Illuminate\Foundation\Testing\Concerns\InteractsWithDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\BusinessTestCase;

/**
 * A migration que semeia os Contatos de envio: cada `clients.phone` não
 * nulo vira um registro `whatsapp` sem rótulo, com o telefone normalizado
 * em dígitos, na mesma conta do cliente. Cliente sem telefone não ganha
 * linha, e o `phone` da carteira permanece — quem decide a leitura do
 * destino é o Disparo, não o backfill.
 *
 * Sem `RefreshDatabase` de propósito: a suíte sobe o schema inteiro, e o
 * teste rebobina só a migration sob teste (drop + fora do `migrations`)
 * para inserir clientes num banco "pré-change" antes de rodá-la de novo.
 */
class ClientDispatchContactBackfillTest extends BusinessTestCase
{
    use InteractsWithDatabase;

    private const MIGRATION = '2026_10_12_204923_create_client_dispatch_contacts_table';

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate')->assertSuccessful();

        // Rebobina só a migration sob teste: o cenário entra num banco
        // pré-change, como no deploy real.
        Schema::dropIfExists('client_dispatch_contacts');
        DB::table('migrations')->where('migration', self::MIGRATION)->delete();
    }

    protected function tearDown(): void
    {
        Schema::dropAllTables();

        parent::tearDown();
    }

    public function test_phone_da_carteira_vira_contato_whatsapp_sem_rotulo(): void
    {
        $accountId = $this->insertAccount('Conta A');
        $clienteComTelefone = $this->insertClient($accountId, '(11) 98765-4321');
        $clienteSemTelefone = $this->insertClient($accountId, null);

        $this->artisan('migrate')->assertSuccessful();

        $this->assertDatabaseHas('client_dispatch_contacts', [
            'account_id' => $accountId,
            'client_id' => $clienteComTelefone,
            'channel' => 'whatsapp',
            'label' => null,
            'value' => '11987654321',
        ]);
        $this->assertDatabaseMissing('client_dispatch_contacts', [
            'client_id' => $clienteSemTelefone,
        ]);

        // O phone da carteira permanece — o backfill semeia, não move.
        $this->assertSame('(11) 98765-4321', DB::table('clients')->find($clienteComTelefone)->phone);
    }

    public function test_backfill_isola_as_contas(): void
    {
        $contaA = $this->insertAccount('Conta A');
        $contaB = $this->insertAccount('Conta B');
        $clienteB = $this->insertClient($contaB, '21987654321');

        $this->artisan('migrate')->assertSuccessful();

        $contato = DB::table('client_dispatch_contacts')->where('client_id', $clienteB)->sole();
        $this->assertSame($contaB, $contato->account_id);
        $this->assertDatabaseMissing('client_dispatch_contacts', [
            'account_id' => $contaA,
        ]);
    }

    private function insertAccount(string $name): int
    {
        return DB::table('accounts')->insertGetId([
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertClient(int $accountId, ?string $phone): int
    {
        return DB::table('clients')->insertGetId([
            'account_id' => $accountId,
            'name' => fake()->company(),
            'phone' => $phone,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
