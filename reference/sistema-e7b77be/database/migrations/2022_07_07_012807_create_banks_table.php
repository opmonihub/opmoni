<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBanksTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('banks', function (Blueprint $table) {
            $table->increments('id');

            $table->integer('business_id')->unsigned();
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');

            $table->string('banco', 30);
            $table->string('agencia', 10);
            $table->string('conta', 15);
            $table->string('titular', 45);

            $table->boolean('padrao')->default(0);

            $table->string('cnpj', 18);
            $table->string('endereco', 50);
            $table->string('cep', 9);
            $table->string('bairro', 30);
            $table->integer('cidade_id')->unsigned();
            $table->foreign('cidade_id')->references('id')->on('cities')->onDelete('cascade');

            $table->string('carteira', 10)->default('');
            $table->string('convenio', 20)->default('');
            $table->decimal('juros', 10, 2)->default(0);
            $table->decimal('multa', 10, 2)->default(0);
            $table->integer('juros_apos')->default(0);
            $table->string('tipo', 7);
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
        Schema::dropIfExists('banks');
    }
}
