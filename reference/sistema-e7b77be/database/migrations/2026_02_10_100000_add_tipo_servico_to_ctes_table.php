<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddTipoServicoToCtesTable extends Migration
{
    public function up()
    {
        Schema::table('ctes', function (Blueprint $table) {
            $table->string('tipo_servico', 1)->default('0')->after('tomador');
            $table->unsignedBigInteger('tomador_outros_id')->nullable()->after('tipo_servico');
        });
    }

    public function down()
    {
        Schema::table('ctes', function (Blueprint $table) {
            $table->dropColumn(['tipo_servico', 'tomador_outros_id']);
        });
    }
}
