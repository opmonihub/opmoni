<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateManifestosTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('manifestos', function (Blueprint $table) {
            $table->increments('id');

            $table->integer('business_id')->unsigned();
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');

            $table->integer('location_id')->unsigned()->nullable();
            $table->foreign('location_id')->references('id')
            ->on('business_locations')->onDelete('cascade');

            $table->string('chave', 44);
            $table->string('nome', 100);
            $table->string('documento', 20);
            $table->decimal('valor', 10, 2);
            $table->string('num_prot', 20);
            $table->string('data_emissao', 25);
            $table->integer('sequencia_evento');
            $table->boolean('fatura_salva');
            $table->integer('tipo');
            $table->integer('nsu');
            //1 => ciencia, 2 => confirmação, 3 => desconhecimento, 4 => operação não realizada

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('manifestos');
    }
}
