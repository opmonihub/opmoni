<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePaymentPlansTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('payment_plans', function (Blueprint $table) {
            $table->increments('id');

            $table->string('payerFirstName', 30);
            $table->string('payerLastName', 30);
            $table->string('payerEmail', 80);
            $table->string('docNumber', 20);

            $table->string('transacao_id', 30);
            $table->string('status', 30);
            $table->decimal('valor', 10, 2);
            $table->enum('forma_pagamento', ['pix', 'cartao', 'boleto']);
            $table->text('qr_code_base64');
            $table->text('qr_code');
            $table->string('link_boleto', 255);
            $table->string('numero_cartao', 40);

            $table->integer('package_id');
            $table->integer('business_id');

            // alter table payment_plans add column package_id integer;
            // alter table payment_plans add column business_id integer;

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
        Schema::dropIfExists('payment_plans');
    }
}
