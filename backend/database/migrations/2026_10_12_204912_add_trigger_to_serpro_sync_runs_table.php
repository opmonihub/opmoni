<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O que disparou cada execução. Aditivo e `manual` por padrão: toda linha
     * existente nasceu do botão do operador — a execução agendada ainda não
     * existia. O índice existe para a cota mensal, que conta por conta e mês
     * só as `manual`.
     */
    public function up(): void
    {
        Schema::table('serpro_sync_runs', function (Blueprint $table): void {
            $table->string('trigger', 20)->default('manual')->after('state');
            $table->index(['account_id', 'trigger', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('serpro_sync_runs', function (Blueprint $table): void {
            $table->dropIndex(['account_id', 'trigger', 'created_at']);
            $table->dropColumn('trigger');
        });
    }
};
