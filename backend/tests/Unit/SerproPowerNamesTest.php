<?php

namespace Tests\Unit;

use App\Services\SerproPowerNames;
use PHPUnit\Framework\TestCase;

/**
 * A tabela nome-do-sistema-e-CAC → família de procuração. Ela só pode dar
 * família para o que foi comprovado no payload do provedor ou na
 * documentação: um nome que ninguém viu não é autorização.
 */
class SerproPowerNamesTest extends TestCase
{
    public function test_nomes_comprovados_do_exemplo_publicado_resolvem(): void
    {
        $map = new SerproPowerNames;

        // Os dois nomes do exemplo de `OBTERPROCURACAO41` publicado pelo
        // SERPRO em 10/04/2026 — a página listada no `_provenance` da fixture.
        $this->assertSame(['00006'], $map->familiesFor('Caixa Postal - Mensagens'));
        $this->assertSame(['00050'], $map->familiesFor('Caixa Postal - Termo de Opção pelo Domicílio Tributário Eletrônico'));
    }

    public function test_nome_nao_documentado_nao_autoriza(): void
    {
        $map = new SerproPowerNames;

        $this->assertSame([], $map->familiesFor('Outro sistema não documentado'));
        $this->assertSame([], $map->familiesFor(''));
    }

    public function test_o_nome_e_normalizado_mas_nao_adivinhado(): void
    {
        $map = new SerproPowerNames;

        // Espaço duplo e bordas não mudam o nome; substring e fuzzy, mudam —
        // e não autorizam.
        $this->assertSame(['00006'], $map->familiesFor('  Caixa Postal -   Mensagens '));
        $this->assertSame([], $map->familiesFor('Caixa Postal'));
        $this->assertSame([], $map->familiesFor('caixa postal - mensagens'));
    }
}
