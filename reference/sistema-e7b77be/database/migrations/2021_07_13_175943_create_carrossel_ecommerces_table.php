<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateCarrosselEcommercesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('carrossel_ecommerces', function (Blueprint $table) {
            $table->increments('id');

            $table->integer('business_id')->unsigned();
            $table->foreign('business_id')->references('id')
            ->on('business')->onDelete('cascade');

            $table->string('titulo', 30);
            $table->string('descricao', 200);
            $table->string('link_acao', 200);
            $table->string('nome_botao', 20);
            $table->string('img', 40);
            $table->string('cor_fundo', 7)->default('#000');

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
        Schema::dropIfExists('carrossel_ecommerces');
    }
}
