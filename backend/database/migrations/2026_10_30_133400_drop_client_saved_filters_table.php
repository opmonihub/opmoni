<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Filtros salvos da carteira saem de cena: o atalho não entrou no uso
     * e a busca com o painel de filtros cobre o mesmo caminho.
     *
     * O `down()` recria a tabela de `2026_10_12_204834` para um rollback
     * devolver o esquema que o código antigo esperava.
     */
    public function up(): void
    {
        Schema::dropIfExists('client_saved_filters');
    }

    public function down(): void
    {
        Schema::create('client_saved_filters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('q')->nullable();
            $table->json('filters');
            $table->timestamps();

            $table->unique(['account_id', 'user_id', 'name']);
            $table->index(['account_id', 'user_id']);
        });
    }
};
