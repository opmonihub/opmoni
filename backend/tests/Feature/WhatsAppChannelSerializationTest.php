<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\WhatsAppChannel;
use App\Tenant\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\BusinessTestCase;

/**
 * A apikey da instância nunca sai: nem em claro, nem a cifra (que com a
 * `APP_KEY` da aplicação vira a chave de novo). O teste serializa o canal
 * pelas três portas comuns — `toArray`, `toJson` e `json_encode` — e
 * procura as duas formas do segredo.
 */
class WhatsAppChannelSerializationTest extends BusinessTestCase
{
    use RefreshDatabase;

    public function test_o_json_do_canal_nao_contem_a_apikey_nem_a_cifra(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();

        $plainKey = 'wzap-chave-super-secreta-123';
        $channel = WhatsAppChannel::factory()->connected($plainKey)->create([
            'account_id' => $account->getKey(),
        ]);

        foreach ([$channel->toArray(), $channel->toJson(), json_encode($channel)] as $serialized) {
            $haystack = is_string($serialized) ? $serialized : json_encode($serialized);

            $this->assertStringNotContainsString($plainKey, (string) $haystack);
            $this->assertStringNotContainsString('instance_api_key_encrypted', (string) $haystack);
            $this->assertStringNotContainsString(
                (string) $channel->instance_api_key_encrypted,
                (string) $haystack,
            );
        }

        // E o accessor público também não é serializável por acidente:
        // `instanceApiKey` é método, e não `getInstanceApiKeyAttribute`.
        $this->assertArrayNotHasKey('instance_api_key', $channel->toArray());
    }

    public function test_a_apikey_em_claro_so_sai_pelo_metodo_interno(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();

        $plainKey = 'wzap-chave-super-secreta-123';
        $channel = WhatsAppChannel::factory()->create(['account_id' => $account->getKey()]);

        $channel->storeInstanceApiKey($plainKey);

        $this->assertSame($plainKey, $channel->instanceApiKey());

        // O que está gravado não é a chave — abre com a `APP_KEY`, e só.
        $this->assertNotSame($plainKey, $channel->getRawOriginal('instance_api_key_encrypted'));
        $this->assertSame($plainKey, Crypt::decryptString($channel->getRawOriginal('instance_api_key_encrypted')));
    }
}
