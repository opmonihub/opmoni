<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateContigenciasTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('contigencias', function (Blueprint $table) {
            $table->increments('id');

            $table->integer('business_id')->unsigned();
            $table->foreign('business_id')->references('id')
            ->on('business')->onDelete('cascade');

            $table->boolean('status');
            $table->enum('tipo', ['SVCAN', 'SVCRS', 'OFFLINE']);
            $table->string('motivo', 255);
            $table->text('status_retorno');
            $table->enum('documento', ['NFe', 'NFCe', 'CTe', 'MDFe']);
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
        Schema::dropIfExists('contigencias');
    }
}
