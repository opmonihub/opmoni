<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O escopo da execução: a lista de obrigações que ela cobre. `null` é
     * "todas as sincronizáveis" — o comportamento de toda execução que já
     * existe, e o que o botão manual continua pedindo; a lista só é preenchida
     * pela rotina agendada, que dispara uma run por documento no dia dele.
     */
    public function up(): void
    {
        Schema::table('serpro_sync_runs', function (Blueprint $table): void {
            $table->json('obligations')->nullable()->after('trigger');
        });
    }

    public function down(): void
    {
        Schema::table('serpro_sync_runs', function (Blueprint $table): void {
            $table->dropColumn('obligations');
        });
    }
};
