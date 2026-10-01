<?php

namespace Tests\Feature\Fiscal;

use App\Enums\FiscalModel;
use App\Models\Account;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\ClientCertificate;
use App\Models\FiscalDocument;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `client_certificate_status` na linha de documento fiscal: o estado do
 * certificado do cliente que explica a cobertura daquela fonte, derivado em
 * lote para a página inteira — a mesma regra que `FiscalCoverage` aplica,
 * com uma palavra a mais (`expiring`) porque a linha precisa dizer "a
 * vencer" onde o resumo só diz "capturável".
 */
class FiscalDocumentCertificateStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-28 12:00:00');
        Storage::fake('fiscal');
        Storage::fake('certificates');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_o_status_do_certificado_do_cliente_aparece_na_linha(): void
    {
        $account = Account::factory()->create();
        $semCertificado = $this->cliente($account, 'Cliente Sem Certificado');
        $comCertificado = $this->cliente($account, 'Cliente Com Certificado');
        $this->certificado($comCertificado, '2027-01-01');

        $this->documento($account, $semCertificado, '9000001');
        $this->documento($account, $comCertificado, '9000002');

        $linhas = $this->linhas($account);

        $this->assertSame('missing', $linhas['9000001']['client_certificate_status']);
        $this->assertSame('valid', $linhas['9000002']['client_certificate_status']);
    }

    public function test_certificado_vencido_e_expired_e_sem_senha_e_password_missing(): void
    {
        $account = Account::factory()->create();
        $vencido = $this->cliente($account, 'Cliente Certificado Vencido');
        $semSenha = $this->cliente($account, 'Cliente Sem Senha');
        $aVencer = $this->cliente($account, 'Cliente A Vencer');

        $this->certificado($vencido, '2026-09-01');
        $this->certificado($semSenha, '2027-01-01', comSenha: false);
        $this->certificado($aVencer, '2026-10-20');

        $this->documento($account, $vencido, '9000003');
        $this->documento($account, $semSenha, '9000004');
        $this->documento($account, $aVencer, '9000005');

        $linhas = $this->linhas($account);

        $this->assertSame('expired', $linhas['9000003']['client_certificate_status']);
        $this->assertSame('password_missing', $linhas['9000004']['client_certificate_status']);
        $this->assertSame('expiring', $linhas['9000005']['client_certificate_status']);
    }

    public function test_a_pagina_inteira_custa_uma_consulta_aos_certificados(): void
    {
        $account = Account::factory()->create();

        foreach (range(1, 30) as $indice) {
            $cliente = $this->cliente($account, "Cliente {$indice}");
            $this->certificado($cliente, '2027-01-01');
            $this->documento($account, $cliente, (string) (9000100 + $indice));
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($this->memberOf($account, 'operador'), 'sanctum')
            ->getJson('/api/fiscal/documents?per_page=100')
            ->assertOk()
            ->assertJsonPath('meta.total', 30);

        // Os 30 certificados vêm numa leitura só: com a linha carregando o
        // `currentCertificate` por conta própria, a página pagaria uma
        // consulta por linha — 30, e não uma.
        $certificados = collect(DB::getQueryLog())
            ->filter(fn (array $entry): bool => str_contains($entry['query'], 'client_certificates'));

        $this->assertLessThanOrEqual(1, $certificados->count());
        DB::disableQueryLog();
    }

    public function test_a_linha_nao_traz_segredo_do_certificado(): void
    {
        $account = Account::factory()->create();
        $cliente = $this->cliente($account, 'Cliente Com Certificado');
        $certificado = $this->certificado($cliente, '2027-01-01');
        $this->documento($account, $cliente, '9000006');

        $response = $this->actingAs($this->memberOf($account, 'operador'), 'sanctum')
            ->getJson('/api/fiscal/documents')
            ->assertOk();

        // Nem a senha cifrada, nem o caminho, nem o titular: a linha descreve
        // a cobertura, não o cofre. `storage_path` é `null` no fixture — o
        // nome da coluna é o que não pode aparecer.
        $response->assertDontSee('password_encrypted', false)
            ->assertDontSee('storage_path', false)
            ->assertDontSee('sha256', false)
            ->assertDontSee((string) $certificado->subject, false);
    }

    public function test_cliente_de_outra_conta_nao_entra_na_lista(): void
    {
        $account = Account::factory()->create();
        $proprio = $this->cliente($account, 'Cliente Da Conta');
        $this->certificado($proprio, '2027-01-01');
        $this->documento($account, $proprio, '9000007');

        $outra = Account::factory()->create();
        $alheio = $this->cliente($outra, 'Empresa Alienada Com Documento');
        $this->certificado($alheio, '2027-01-01');
        $this->documento($outra, $alheio, '9000008');

        $response = $this->actingAs($this->memberOf($account, 'operador'), 'sanctum')
            ->getJson('/api/fiscal/documents')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $response->assertDontSee('Alienada', false)
            ->assertDontSee('9000008', false);
    }

    /**
     * As linhas da página indexadas pelo `emitente_cnpj` que o teste escreveu
     * — é o campo pelo qual cada fixture se reconhece, já que a
     * `chave_acesso` de 44 dígitos não leva o número curto do teste.
     *
     * @return array<string, array<string, mixed>>
     */
    private function linhas(Account $account): array
    {
        return collect(
            $this->actingAs($this->memberOf($account, 'operador'), 'sanctum')
                ->getJson('/api/fiscal/documents?per_page=100')
                ->assertOk()
                ->json('data')
        )->keyBy(fn (array $linha): string => (string) $linha['emitente_cnpj'])->all();
    }

    private function cliente(Account $account, string $name): Client
    {
        return Client::factory()->company()->create([
            'account_id' => $account->getKey(),
            'name' => $name,
        ]);
    }

    private function certificado(Client $client, string $validUntil, bool $comSenha = true): ClientCertificate
    {
        return ClientCertificate::factory()
            ->state([
                'valid_until' => CarbonImmutable::parse($validUntil),
                'password_encrypted' => $comSenha ? Crypt::encryptString('senha-do-certificado') : null,
                'storage_path' => null,
            ])
            ->create([
                'account_id' => $client->account_id,
                'client_id' => $client->getKey(),
            ]);
    }

    private function documento(Account $account, Client $client, string $emitente): void
    {
        FiscalDocument::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'model' => FiscalModel::Nfe,
            'emitente_cnpj' => $emitente,
            'emissao_at' => '2026-09-28 10:00:00',
        ]);
    }

    private function memberOf(Account $account, string $role): User
    {
        $user = User::factory()->create();
        AccountUser::create(['account_id' => $account->getKey(), 'user_id' => $user->getKey(), 'role' => $role]);
        $user->forceFill(['current_account_id' => $account->getKey()])->save();

        return $user->refresh();
    }
}
