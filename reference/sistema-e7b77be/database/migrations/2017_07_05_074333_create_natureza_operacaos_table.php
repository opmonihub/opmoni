<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateNaturezaOperacaosTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('natureza_operacaos', function (Blueprint $table) {
            $table->increments('id');
            $table->string('natureza', 80);
            $table->string('cfop_entrada_estadual', 5)->default("");
            $table->string('cfop_entrada_inter_estadual', 5)->default("");
            $table->string('cfop_saida_estadual', 5)->default("");
            $table->string('cfop_saida_inter_estadual', 5)->default("");

            $table->integer('business_id')->unsigned();
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');

            $table->integer('finNFe')->default(1);
            $table->integer('tipo')->default(1);
            $table->boolean('sobrescreve_cfop')->default(0);
            $table->boolean('bonificacao')->default(0);

            // alter table natureza_operacaos add column bonificacao boolean default 0;
            
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
        Schema::dropIfExists('natureza_operacaos');
    }
}
