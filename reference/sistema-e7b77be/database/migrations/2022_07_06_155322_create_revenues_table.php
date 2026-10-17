<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRevenuesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('revenues', function (Blueprint $table) {
            $table->increments('id');

            $table->string('referencia')->nullable();
            $table->string('observacao')->nullable();
            $table->date('vencimento');
            $table->date('recebimento');
            $table->integer('business_id')->unsigned();
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');

            $table->decimal('valor_total', 22, 4)->default(0);
            $table->decimal('valor_recebido', 22, 4)->default(0);
            $table->boolean('status')->default(0);
            $table->integer('created_by')->unsigned();
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');

            $table->integer('expense_category_id')->nullable()->unsigned();
            $table->foreign('expense_category_id')->references('id')->on('expense_categories')->onDelete('cascade');

            $table->string('tipo_pagamento', 20);

            $table->integer('location_id')->unsigned();
            $table->foreign('location_id')->references('id')->on('business_locations');

            $table->integer('contact_id')->unsigned();
            $table->foreign('contact_id')->references('id')->on('contacts')->onDelete('cascade');

            $table->string('document')->nullable();
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
        Schema::dropIfExists('revenues');
    }
}
