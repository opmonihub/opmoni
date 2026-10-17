<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCteDocumentosAnterioresTable extends Migration
{
    public function up()
    {
        Schema::create('cte_documentos_anteriores', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('cte_id')->unsigned();
            $table->string('chave_cte', 44);
            $table->string('cnpj_emitente', 14)->nullable();
            $table->string('ie_emitente', 20)->nullable();
            $table->string('uf_emitente', 2)->nullable();
            $table->string('nome_emitente')->nullable();
            $table->timestamps();
            
            $table->foreign('cte_id')->references('id')->on('ctes')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('cte_documentos_anteriores');
    }
}
