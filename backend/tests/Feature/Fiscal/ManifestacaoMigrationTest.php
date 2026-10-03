<?php

namespace Tests\Feature\Fiscal;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ManifestacaoMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_tabela_de_manifestacao_tem_as_colunas_e_a_unicidade_esperadas(): void
    {
        $this->assertTrue(Schema::hasTable('fiscal_manifestations'));

        $this->assertTrue(Schema::hasColumns('fiscal_manifestations', [
            'id',
            'account_id',
            'client_id',
            'chave_acesso',
            'event_type',
            'event_seq',
            'requested_by',
            'outcome',
            'requested_at',
            'sent_at',
            'resulted_at',
            'created_at',
            'updated_at',
        ]));

        $indexes = collect(Schema::getIndexes('fiscal_manifestations'))
            ->filter(fn (array $index): bool => $index['unique'] ?? false)
            ->map(fn (array $index): array => $index['columns'])
            ->values()
            ->all();

        $this->assertContains(
            ['account_id', 'client_id', 'chave_acesso', 'event_type', 'event_seq'],
            $indexes,
        );
    }
}
