<?php

namespace Tests\Feature;

use App\Models\WhatsAppDispatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BusinessTestCase;

/**
 * `withLogicalKey()` deriva a chave dos ids **reais** da linha. O estado
 * não pode ser um `state()` comum: lá dentro o `account_id` ainda é a
 * factory e o `client_id` ainda é o Closure, e a chave saía calculada
 * sobre `1|1|...` — determinística, errada e igual para todo mundo.
 */
class WhatsAppDispatchFactoryTest extends BusinessTestCase
{
    use RefreshDatabase;

    public function test_with_logical_key_calcula_a_chave_sobre_os_ids_reais_da_linha(): void
    {
        $dispatch = WhatsAppDispatch::factory()->withLogicalKey()->create([
            'client_dispatch_contact_id' => null,
            'slip_number' => '07202215764027873',
        ]);

        $this->assertSame(
            WhatsAppDispatch::idempotencyKey(
                $dispatch->account_id,
                $dispatch->client_id,
                $dispatch->obligation,
                $dispatch->artifact_type,
                $dispatch->competence,
                null,
                '07202215764027873',
            ),
            $dispatch->idempotency_key,
        );
    }

    public function test_with_logical_key_gera_chaves_diferentes_para_contas_diferentes(): void
    {
        $a = WhatsAppDispatch::factory()->withLogicalKey()->create();
        $b = WhatsAppDispatch::factory()->withLogicalKey()->create();

        $this->assertNotSame($a->idempotency_key, $b->idempotency_key);
    }

    public function test_make_com_with_logical_key_preenche_a_chave_sem_salvar(): void
    {
        $dispatch = WhatsAppDispatch::factory()->withLogicalKey()->make();

        $this->assertFalse($dispatch->exists);
        $this->assertSame(
            WhatsAppDispatch::idempotencyKey(
                $dispatch->account_id,
                $dispatch->client_id,
                $dispatch->obligation,
                $dispatch->artifact_type,
                $dispatch->competence,
            ),
            $dispatch->idempotency_key,
        );
    }
}
