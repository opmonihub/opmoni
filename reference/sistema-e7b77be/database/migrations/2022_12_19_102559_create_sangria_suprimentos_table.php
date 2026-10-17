<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSangriaSuprimentosTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('sangria_suprimentos', function (Blueprint $table) {
            $table->increments('id');

            $table->integer('cash_id')->unsigned();
            $table->foreign('cash_id')->references('id')->on('cash_registers')->onDelete('cascade');

            $table->enum('type', ['sangria', 'suprimento']);
            $table->decimal('value', 10, 2);
            $table->string('note', 200);
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
        Schema::dropIfExists('sangria_suprimentos');
    }
}
