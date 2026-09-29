<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A procuração e-CAC que o Membro registra ganha os dois metadados que o
     * SERPRO devolve: o código da procuração emitida no e-CAC e o estado de
     * integração observado no provedor.
     *
     * As duas são nullable porque a procuração que já existia antes da
     * integração não tem código nenhum — e o registro sem código não é um
     * defeito, é o estado normal de quem nunca passou pelo e-CAC. O estado
     * só tem valor quando há código, e quem o escreve é a consulta ao
     * provedor ou o `saving` do model ao ver o código mudar: `pending` é o
     * intervalo entre "o Membro digitou" e "o SERPRO confirmou".
     */
    public function up(): void
    {
        Schema::table('client_ecac_powers_of_attorney', function (Blueprint $table): void {
            $table->string('serpro_code', 32)->nullable()->after('expires_at');
            $table->string('integration_state', 24)->nullable()->after('serpro_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('client_ecac_powers_of_attorney', function (Blueprint $table): void {
            $table->dropColumn(['serpro_code', 'integration_state']);
        });
    }
};
