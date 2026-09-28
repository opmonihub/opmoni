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
        Schema::table('serpro_connections', function (Blueprint $table): void {
            /*
             * Coluna constante com índice único: toda linha vale 1, então a
             * segunda linha colide. É a forma de o banco — e não uma convenção
             * da aplicação — garantir "uma única credencial da plataforma".
             *
             * Um `lockForUpdate()` não serve aqui: sobre resultado vazio ele não
             * trava nada, e duas primeiras gravações simultâneas leriam ambas
             * "não há credencial" e criariam duas linhas. O índice vale para
             * qualquer processo e qualquer entrada, inclusive uma restauração
             * ou um `insert` manual que nunca passe pelo gerenciador.
             */
            $table->unsignedTinyInteger('singleton')->default(1);
            $table->unique('singleton', 'serpro_connections_singleton_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('serpro_connections', function (Blueprint $table): void {
            $table->dropUnique('serpro_connections_singleton_unique');
        });

        Schema::table('serpro_connections', function (Blueprint $table): void {
            $table->dropColumn('singleton');
        });
    }
};
