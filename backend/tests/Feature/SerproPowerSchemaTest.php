<?php

namespace Tests\Feature;

use App\Enums\SerproPowerOfAttorneyState;
use App\Models\Account;
use App\Models\Client;
use App\Models\SerproClientAuthorization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * O esquema da autorização por família: uma linha por
 * `(account_id, client_id, family)`, com o código de procuração que o
 * provedor respondeu e o estado observado. A unicidade é o que torna o
 * re-sync idempotente — a segunda leitura do mesmo par cliente/família
 * atualiza a linha em vez de duplicar.
 */
class SerproPowerSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_tabela_da_procuracao_manual_nao_existe_mais(): void
    {
        // A procuração digitada virou a autorização derivada do provedor —
        // a tabela que a guardava é removida pela migration de drop.
        $this->assertFalse(Schema::hasTable('client_ecac_powers_of_attorney'));
    }

    public function test_tabela_de_autorizacoes_por_familia_existe_com_as_colunas(): void
    {
        $this->assertTrue(Schema::hasTable('serpro_client_authorizations'));

        foreach (['account_id', 'client_id', 'family', 'code', 'state', 'expires_on', 'verified_at'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('serpro_client_authorizations', $column),
                "coluna ausente: {$column}"
            );
        }
    }

    public function test_family_e_codigo_do_serpro_aceitam_codigo_compartilhado(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);

        $authorization = SerproClientAuthorization::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'family' => '00146',
            'code' => '00146',
        ]);

        // `00146` cobre PGDASD e DEFIS com uma outorga só: o código permanece
        // texto e o estado lê o enum da procuração.
        $this->assertSame('00146', $authorization->code);
        $this->assertSame(SerproPowerOfAttorneyState::Established, $authorization->state);
    }

    public function test_unicidade_de_cliente_e_familia_por_conta(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);

        SerproClientAuthorization::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'family' => '00006',
        ]);

        $this->expectException(QueryException::class);

        SerproClientAuthorization::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'family' => '00006',
        ]);
    }

    public function test_a_mesma_familia_em_outro_cliente_ou_outra_conta_nao_colide(): void
    {
        $account = Account::factory()->create();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);

        SerproClientAuthorization::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $client->getKey(),
            'family' => '00006',
        ]);

        $otherClient = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        $otherAccount = Account::factory()->create();
        $foreignClient = Client::factory()->company()->create(['account_id' => $otherAccount->getKey()]);

        // Mesma família, mesmo código: a unicidade é por (conta, cliente,
        // família), e a autorização de um cliente nunca habilita o outro.
        SerproClientAuthorization::factory()->create([
            'account_id' => $account->getKey(),
            'client_id' => $otherClient->getKey(),
            'family' => '00006',
        ]);
        SerproClientAuthorization::factory()->create([
            'account_id' => $otherAccount->getKey(),
            'client_id' => $foreignClient->getKey(),
            'family' => '00006',
        ]);

        $this->assertDatabaseCount('serpro_client_authorizations', 3);
    }
}
