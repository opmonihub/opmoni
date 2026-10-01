<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table): void {
            // O número e a série vêm do XML (`ide/nNF`, `ide/serie` na NF-e;
            // `ide/serie` no CT-e quando existir). Nullable sem default: o
            // documento antigo não tem número a inventar, e mostra traço.
            $table->string('numero', 20)->nullable()->after('chave_acesso');
            $table->string('serie', 10)->nullable()->after('numero');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table): void {
            $table->dropColumn(['numero', 'serie']);
        });
    }
};
