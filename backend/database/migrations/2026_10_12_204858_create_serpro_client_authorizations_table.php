<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A autorização **observada** no provedor, por cliente e por família de
     * serviço — e nunca estabelecida por este sistema.
     *
     * `family` é o código de família que a consulta `OBTERPROCURACAO41`
     * devolve por nome de sistema (a tabela nome→código é do
     * `SerproPowerNames`), e a unicidade por `(account_id, client_id,
     * family)` é o que torna o refresh idempotente: a segunda leitura do
     * mesmo par atualiza a linha em vez de duplicar a outorga.
     *
     * `code` guarda o código de procuração coberto por aquela família — o
     * mesmo valor de `family` na prática, porque a família **é** o código no
     * catálogo do SERPRO; a coluna existe para que o código digitado pelo
     * Membro em `client_ecac_powers_of_attorney.serpro_code` possa ser
     * confrontado com o que o provedor disse sem perder nenhum dos dois.
     *
     * O `00146` cobre PGDASD **e** DEFIS com uma outorga só: as duas
     * famílias de serviço não são autorizáveis de forma independente, e a
     * unicidade por família-de-código (e não por serviço) é o que impede o
     * produto de cobrar do escritório uma procuração que o SERPRO não emite.
     *
     * `verified_at` é quando esta linha foi vista pela última vez no
     * provedor, e é distinto de `updated_at`: um registro reescrito com o
     * mesmo estado renova a verificação, que é a informação que "o dado
     * está velho" precisa.
     */
    public function up(): void
    {
        Schema::create('serpro_client_authorizations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('family', 48);
            $table->string('code', 16);
            $table->string('state', 24);
            $table->date('expires_on')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'client_id', 'family']);
            $table->index(['account_id', 'family']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('serpro_client_authorizations');
    }
};
