<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A procuração e-CAC digitada sai de cena: a autorização derivada do
     * provedor (`serpro_client_authorizations`) é a única fonte, e a tabela
     * que guardava o dado de mão não tem quem a alimente.
     *
     * O `down()` recria o que as duas migrations originais construíram —
     * a estrutura base de `2026_09_22_100003` mais `serpro_code` e
     * `integration_state` de `2026_09_28_120000` — para que um rollback
     * devolva o esquema que o código antigo esperava.
     */
    public function up(): void
    {
        Schema::dropIfExists('client_ecac_powers_of_attorney');
    }

    public function down(): void
    {
        Schema::create('client_ecac_powers_of_attorney', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->unique()->constrained()->cascadeOnDelete();
            $table->date('starts_at');
            $table->date('expires_at');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->string('serpro_code', 32)->nullable();
            $table->string('integration_state', 24)->nullable();
        });
    }
};
