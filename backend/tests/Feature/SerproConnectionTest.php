<?php

namespace Tests\Feature;

use App\Enums\SerproFailure;
use App\Models\Account;
use App\Models\AccountCertificate;
use App\Models\SerproConnection;
use App\Services\SerproException;
use App\Tenant\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class SerproConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_the_secret_encrypted_and_never_returns_it(): void
    {
        $connection = SerproConnection::factory()->create([
            'consumer_secret_encrypted' => Crypt::encryptString('super-secret'),
        ]);

        $this->assertNotSame('super-secret', $connection->getRawOriginal('consumer_secret_encrypted'));
        $this->assertSame('super-secret', $connection->consumerSecret());

        // A garantia quem dá é o `$hidden` do modelo, não um método auxiliar de
        // metadados: serializar a linha inteira não pode devolver nenhuma das
        // colunas cifradas, e a resource lista o que devolve por cima disso.
        foreach (['consumer_secret_encrypted', 'certificate_encrypted', 'certificate_password_encrypted'] as $column) {
            $this->assertArrayNotHasKey($column, $connection->toArray());
        }
    }

    public function test_current_reports_a_configuracao_pela_chave_e_pelo_segredo(): void
    {
        // Sem chave ou sem segredo não existe credencial utilizável, e quem
        // responde por isso — a resource e o teste de conectividade — precisa da
        // mesma resposta que o provedor daria.
        SerproConnection::factory()->create();
        $this->assertTrue(SerproConnection::current()?->isConfigured());

        SerproConnection::query()->update(['consumer_key' => '']);
        $this->assertFalse(SerproConnection::current()?->isConfigured());
    }

    public function test_current_returns_null_when_absent(): void
    {
        $this->assertNull(SerproConnection::current());
    }

    public function test_services_map_has_correct_shape(): void
    {
        $services = config('integra-contador.services');

        $this->assertIsArray($services);
        $this->assertNotEmpty($services);

        foreach ($services as $key => $entry) {
            $this->assertIsString($key);
            $this->assertNotEmpty($key);
            $this->assertIsString($entry['path']);
            $this->assertNotEmpty($entry['path'], "path for {$key} must not be empty");
            $this->assertIsString($entry['versaoSistema']);
            $this->assertNotEmpty($entry['versaoSistema'], "versaoSistema for {$key} must not be empty");
            $this->assertIsBool($entry['billable'], "billable for {$key} must be a bool");
        }

        $hasFreeEntry = collect($services)->contains(fn ($entry) => $entry['billable'] === false);
        $this->assertTrue($hasFreeEntry, 'At least one service must have billable === false');

        $this->assertSame(
            ['path' => 'Emitir', 'versaoSistema' => '2.0', 'billable' => true],
            $services['RELATORIOSITFIS92']
        );
    }

    public function test_o_lookup_do_certificado_contratante_ignora_o_tenant_da_conta_operadora(): void
    {
        // O job da fila deixa o CurrentTenant na conta que está sendo servida.
        // A credencial é da plataforma e o e-CNPJ mora na conta contratante:
        // o escopo global da operadora não pode esconder essa linha.
        $contratante = Account::factory()->create();
        $operadora = Account::factory()->create();
        $certificado = AccountCertificate::factory()->create([
            'account_id' => $contratante->getKey(),
            'document' => '12345678000195',
        ]);
        $conexao = SerproConnection::factory()->create([
            'contracting_account_id' => $contratante->getKey(),
            'certificate_encrypted' => null,
            'certificate_password_encrypted' => null,
            'contratante_numero' => '12345678000195',
        ]);

        resolve(CurrentTenant::class)->accountId = $operadora->getKey();

        $this->assertNull(AccountCertificate::currentFor($operadora->getKey()));
        $this->assertSame($certificado->getKey(), $conexao->contractingCertificate()?->getKey());
        $conexao->assertIdentity();
    }

    public function test_vinculo_sem_ecnpj_corrente_recusa_com_a_mesma_mensagem(): void
    {
        // Histórico não é e-CNPJ corrente: a conta apontada só tem linha
        // removida, e a recusa continua sendo de configuração, com ou sem
        // tenant da operadora por cima.
        $contratante = Account::factory()->create();
        $operadora = Account::factory()->create();
        AccountCertificate::factory()->removed()->create([
            'account_id' => $contratante->getKey(),
            'document' => '12345678000195',
        ]);
        $conexao = SerproConnection::factory()->create([
            'contracting_account_id' => $contratante->getKey(),
            'certificate_encrypted' => null,
            'certificate_password_encrypted' => null,
            'contratante_numero' => '12345678000195',
        ]);

        resolve(CurrentTenant::class)->accountId = $operadora->getKey();

        try {
            $conexao->assertIdentity();
            $this->fail('Um vínculo sem e-CNPJ corrente deve levantar SerproException.');
        } catch (SerproException $exception) {
            $this->assertSame(SerproFailure::DoNotRetry, $exception->failure);
            $this->assertSame(
                'O certificado do contratante não está configurado: a conta apontada não tem e-CNPJ corrente.',
                $exception->getMessage(),
            );
        }
    }
}
