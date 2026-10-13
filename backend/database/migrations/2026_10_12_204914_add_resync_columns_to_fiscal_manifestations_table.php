<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O rastreio da ressincronização por chave: quantas `consChNFe` esta
     * manifestação já tentou (`resync_attempts`) e quando o XML completo entrou
     * (`xml_recovered_at`). As duas são o que torna a rotina segura a repetição —
     * a tentativa é contada porque a consulta gasta a vaga do teto horário, e
     * o recuperado marca a chave que já não precisa de outra.
     */
    public function up(): void
    {
        Schema::table('fiscal_manifestations', function (Blueprint $table): void {
            $table->unsignedTinyInteger('resync_attempts')->default(0)->after('resulted_at');
            $table->timestamp('xml_recovered_at')->nullable()->after('resync_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_manifestations', function (Blueprint $table): void {
            $table->dropColumn(['resync_attempts', 'xml_recovered_at']);
        });
    }
};
