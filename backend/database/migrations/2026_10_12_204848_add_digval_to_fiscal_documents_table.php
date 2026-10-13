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
            // SHA-1 em base64: 28 caracteres, e `varchar` em vez de `char`
            // porque o `char` do Postgres preenche com espaço à direita — e um
            // digest com espaço sobrando nunca bateria com o outro, o que
            // produciria divergência onde não há.
            $table->string('digval', 28)->nullable()->after('sha256');

            // `true` confere, `false` diverge e `null` é "a outra etapa ainda não
            // chegou". Sem o terceiro estado, todo documento de etapa única —
            // evento, ou nota de uma captura que começou no meio da fila — seria
            // marcado como corrompido.
            $table->boolean('digval_confere')->nullable()->after('digval');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fiscal_documents', function (Blueprint $table): void {
            $table->dropColumn(['digval', 'digval_confere']);
        });
    }
};
