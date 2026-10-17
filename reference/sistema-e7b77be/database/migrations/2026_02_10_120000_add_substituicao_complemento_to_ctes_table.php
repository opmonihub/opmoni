<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSubstituicaoComplementoToCtesTable extends Migration
{
    public function up()
    {
        Schema::table('ctes', function (Blueprint $table) {
            $table->integer('tp_cte')->default(0)->after('tipo_servico');
            $table->string('chave_original', 44)->nullable()->after('tp_cte');
            $table->string('chave_complementada', 44)->nullable()->after('chave_original');
            $table->boolean('ind_altera_toma')->nullable()->after('chave_complementada');
            $table->integer('cte_original_id')->unsigned()->nullable()->after('ind_altera_toma');
        });
    }

    public function down()
    {
        Schema::table('ctes', function (Blueprint $table) {
            $table->dropColumn(['tp_cte', 'chave_original', 'chave_complementada', 'ind_altera_toma', 'cte_original_id']);
        });
    }
}
