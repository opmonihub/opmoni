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

    public function test_nomes_da_tabela_servicos_x_procuracoes_resolvem_para_as_familias_do_catalogo(): void
    {
        $map = new SerproPowerNames;

        // Os nomes são os da coluna "Nome do Serviço (procuração eCAC)" da
        // página "Serviços x Procurações" do apicenter, lida em 2026-10-03.
        $this->assertSame(['00060'], $map->familiesFor('Simples Nacional - Opção pelo Regime de Apuração de Receitas'));
        $this->assertSame(['00146'], $map->familiesFor('PGDAS-D - a partir de 01/2018'));
        $this->assertSame(['00103'], $map->familiesFor('Acessar o sistema DCTFWeb'));
        $this->assertSame(['00002'], $map->familiesFor('Situação Fiscal do Contribuinte'));
        $this->assertSame(['00004'], $map->familiesFor('Pagamentos - Comprovante de Arrecadação'));
    }

    public function test_os_nomes_do_parcelamento_cobrem_os_codigos_que_a_tabela_lista(): void
    {
        $map = new SerproPowerNames;

        // PERT-SN e RELP têm um nome só para os dois códigos que a tabela
        // lista — o valor é a lista dos dois, que é a conjunção que o
        // catálogo exige para as obrigações de parcelamento.
        $this->assertSame(['00076'], $map->familiesFor('Parcelamento de Débitos do Simples Nacional'));
        $this->assertSame(['00188'], $map->familiesFor('Solicitar, acompanhar e emitir DAS de parcelamento'));
        $this->assertSame(['00125'], $map->familiesFor('Parcelamento Especial Simples Nacional'));
        $this->assertSame(['00149', '10011'], $map->familiesFor('Programa Especial Regularização Tributária - PERT-SN'));
        $this->assertSame(['00210', '10036'], $map->familiesFor('Parcelar dívidas do SN pela LC 193/2022 (RELP)'));
    }

    public function test_o_codigo_na_frente_do_nome_nao_e_o_nome(): void
    {
        $map = new SerproPowerNames;

        // A tabela lista "00076 - Parcelamento de Débitos do Simples
        // Nacional", mas o código é rótulo da tabela, não parte do nome que
        // o provedor manda: o match é de nome inteiro, e a forma com prefixo
        // não autoriza nada.
        $this->assertSame([], $map->familiesFor('00076 - Parcelamento de Débitos do Simples Nacional'));
    }

    public function test_o_nome_todos_observado_no_provedor_cobre_as_familias_ja_comprovadas(): void
    {
        $map = new SerproPowerNames;

        // Resposta real de OBTERPROCURACAO41 em 2026-10-04: sistemas ["TODOS"].
        // O nome é inteiro — "todos os sistemas" não é prefixo de outro — e
        // a lista é a das famílias que a tabela já comprova, com 00146 no meio.
        $this->assertSame([
            '00006',
            '00050',
            '00060',
            '00146',
            '00103',
            '00002',
            '00004',
            '00076',
            '00188',
            '00125',
            '00149',
            '10011',
            '00210',
            '10036',
        ], $map->familiesFor('TODOS'));
        $this->assertSame([], $map->familiesFor('TODOS os sistemas'));
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
