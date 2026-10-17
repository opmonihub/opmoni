<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRemessaBoletosTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('remessa_boletos', function (Blueprint $table) {
            $table->increments('id');

            $table->integer('remessa_id')->unsigned();
            $table->foreign('remessa_id')->references('id')->on('remessas')->onDelete('cascade');

            $table->integer('boleto_id')->unsigned();
            $table->foreign('boleto_id')->references('id')->on('boletos')->onDelete('cascade');

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
        Schema::dropIfExists('remessa_boletos');
    }
}
