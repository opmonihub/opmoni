<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTableInutils extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('inutils', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('business_id')->nullable(false);
            $table->bigInteger('nNFIni')->nullable(false);
            $table->bigInteger('nNFFin')->nullable(false);
            $table->integer('serie')->nullable(false);
            $table->integer('tpAmb')->nullable(false);
            $table->integer('modelo')->nullable(false);
            $table->string('status',20)->nullable(false);
            $table->string('xJust',90)->nullable(false);
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
        Schema::dropIfExists('inutils');
    }
}
