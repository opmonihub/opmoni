<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A listagem filtra por conta e modelo e ordena por `captured_at`. Sem este
     * índice, o `EXPLAIN` no Postgres percorria `(client_id, model, nsu)` inteiro
     * e filtrava `account_id` linha a linha, porque `model` não é a coluna líder.
     */
    public function up(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table): void {
            $table->index(['account_id', 'model', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table): void {
            $table->dropIndex(['account_id', 'model', 'captured_at']);
        });
    }
};
