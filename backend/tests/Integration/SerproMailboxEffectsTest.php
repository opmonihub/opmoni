<?php

namespace Tests\Integration;

use App\Enums\SerproAuthorizationTermState;
use App\Http\Middleware\AccessSecurity;
use App\Http\Middleware\SerializedAccessSession;
use App\Http\Middleware\TrustedIngress;
use App\Models\Account;
use App\Models\AccountCertificate;
use App\Models\AccountUser;
use App\Models\Client;
use App\Models\SerproAuthorizationTerm;
use App\Models\SerproCall;
use App\Models\SerproConnection;
use App\Models\SerproMonitoring;
use App\Models\User;
use App\Services\ExternalEffectJournal;
use App\Services\ExternalEffectJournalConnection;
use App\Services\SerproTokenPair;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Tests\BusinessTestCase;

/** PostgreSQL same-database durability proof. Run only with the coordinated integration configuration. */
class SerproMailboxEffectsTest extends BusinessTestCase
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
        Cache::flush();
        Http::preventStrayRequests();
        Route::prefix('api/v1')->middleware([TrustedIngress::class, EncryptCookies::class, AddQueuedCookiesToResponse::class, SerializedAccessSession::class, AccessSecurity::class, 'auth'])
            ->group(base_path('routes/external-mailbox.php'));
    }

    public function test_lease_lost_after_response_rolls_back_projection_but_receipt_survives_and_prevents_resend_after_reauthentication(): void
    {
        $account = Account::factory()->create(['settings' => ['serpro_enabled' => true]]);
        $actor = User::factory()->create();
        AccountUser::create(['account_id' => $account->id, 'user_id' => $actor->id, 'role' => 'operador']);
        $this->selectBusinessAccount($actor, $account->id);
        $client = Client::factory()->company()->create(['account_id' => $account->id, 'tax_id' => '33683111000875']);
        $monitoring = SerproMonitoring::factory()->create(['account_id' => $account->id, 'client_id' => $client->id,
            'obligation' => 'caixas-postais/e-cac', 'messages' => [['id' => 82838, 'assunto' => 'Intimação privada', 'unread' => true]],
        ]);
        $derived = SerproMonitoring::factory()->create(['account_id' => $account->id, 'client_id' => $client->id,
            'obligation' => 'caixas-postais/fgts-digital', 'messages' => [['id' => 82838, 'assunto' => 'Intimação privada', 'unread' => true]],
        ]);
        AccountCertificate::factory()->create(['account_id' => $account->id, 'document' => '12345678000195']);
        SerproAuthorizationTerm::forceCreate(['account_id' => $account->id, 'author_document' => '12345678000195',
            'document_encrypted' => Crypt::encryptString('<termo/>'), 'document_expires_on' => now()->addYear(),
            'signed_at' => now(), 'state' => SerproAuthorizationTermState::Autenticado,
            'token_encrypted' => Crypt::encryptString('term-secret'), 'token_expires_at' => now()->addDay(),
        ]);
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['CN' => 'SERPRO PLATAFORMA LTDA:12345678000195'], $key);
        $certificate = openssl_csr_sign($csr, null, $key, 365);
        $pfx = '';
        $this->assertTrue(openssl_pkcs12_export($certificate, $pfx, $key, 'senha'));
        SerproConnection::factory()->create(['certificate_encrypted' => Crypt::encryptString($pfx),
            'certificate_password_encrypted' => Crypt::encryptString('senha'),
            'contratante_numero' => '12345678000195', 'certificate_valid_until' => now()->addYear(),
        ]);
        Cache::put('serpro:token-pair', new SerproTokenPair('access-secret', 'jwt-secret', 2008), 2008);
        Http::fake(['*/integra-contador/v1/Consultar' => function () {
            request()->attributes->get('access.session_lock')->forceRelease();

            return Http::response(['status' => 200, 'dados' => json_encode(['codigo' => '00', 'conteudo' => [[
                'isn' => '0000082838', 'dataLeitura' => '20261015', 'horaLeitura' => '120000',
                'dataCiencia' => '20261015', 'dataExpiracao' => '20261115',
                'assuntoModelo' => 'private-subject', 'corpoModelo' => 'private-body',
            ]]]), 'responseId' => 'pg-mailbox-receipt', 'mensagens' => [['codigo' => '00', 'texto' => 'OK']]]);
        }]);
        $uri = "/api/v1/serpro/monitoring/obligations/caixas-postais/e-cac/clients/{$client->id}/messages/82838";
        $this->actingAs($actor)->postJson($uri, ['ciencia' => true])->assertStatus(503);
        Http::assertSentCount(1);
        $this->assertTrue($monitoring->fresh()->messages[0]['unread']);
        $this->assertSame(0, SerproCall::query()->withoutGlobalScope('account')->count());
        $factory = new ExternalEffectJournalConnection;
        $this->assertNotSame(DB::connection()->getPdo(), $factory->connection()->getPdo());
        $row = $factory->connection()->table('external_effect_journal')->sole();
        $effect = (new ExternalEffectJournal($factory))->forResource($account->id, $row->resource_hash)[0];
        $this->assertSame('confirmed', $effect['state']);
        $this->assertSame('applied', $effect['receipt']);
        $this->assertSame('2026-10-15', $effect['payload']['ciencia_em']);
        $this->assertArrayNotHasKey('corpo', $effect['payload']);
        $this->assertArrayNotHasKey('assunto', $effect['payload']);
        SerproAuthorizationTerm::query()->withoutGlobalScope('account')->delete();
        AccountCertificate::query()->withoutGlobalScope('account')->delete();
        SerproConnection::query()->delete();
        Cache::forget('serpro:token-pair');
        Http::fake();
        $this->actingAs($actor)->postJson($uri, ['ciencia' => true])->assertConflict()->assertJsonPath('code', 'operation_confirmed');
        $this->actingAs($actor)->postJson($uri.'/reconcile')->assertOk()
            ->assertJsonPath('data.body_available', false)->assertJsonPath('data.effect_status', 'confirmed')
            ->assertJsonPath('data.ciencia_em', '2026-10-15')->assertJsonMissingPath('data.corpo');
        $this->assertFalse($monitoring->fresh()->messages[0]['unread']);
        $derivedUri = str_replace('caixas-postais/e-cac', 'caixas-postais/fgts-digital', $uri);
        $this->actingAs($actor)->postJson($derivedUri, ['ciencia' => true])->assertConflict()->assertJsonPath('code', 'operation_confirmed');
        $this->actingAs($actor)->postJson($derivedUri.'/reconcile')->assertOk()
            ->assertJsonPath('data.body_available', false)->assertJsonPath('data.ciencia_em', '2026-10-15');
        $this->assertFalse($derived->fresh()->messages[0]['unread']);
        $this->assertSame(1, $factory->connection()->table('external_effect_journal')->count());
        Http::assertNothingSent();
    }
}
