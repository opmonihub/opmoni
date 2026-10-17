<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTransactionPaymentsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('transaction_payments', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('transaction_id')->unsigned();
            $table->foreign('transaction_id')->references('id')->on('transactions')->onDelete('cascade');
            $table->decimal('amount', 22, 4)->default(0);
            $table->enum('method', ['cash', 'card', 'debit', 'cheque', 'bank_transfer', 'other', 'boleto', 'pix', 
                'vale_alimentacao', 'vale_refeicao']);

            // alter table transaction_payments MODIFY COLUMN method enum('cash', 'card', 'debit', 'cheque', 'bank_transfer', 'other', 'boleto', 'pix', 'vale_alimentacao', 'vale_refeicao');

            $table->string('card_transaction_number')->nullable();
            $table->string('card_number')->nullable();
            $table->enum('card_type', ['visa', 'master'])->nullable();
            $table->string('card_holder_name')->nullable();
            $table->string('card_month')->nullable();
            $table->string('card_year')->nullable();
            $table->string('card_security', 5)->nullable(); // alterado bandeira do cartao
            $table->date('vencimento')->nullable();

            $table->string('cheque_number')->nullable();

            $table->string('bank_account_number')->nullable();

            $table->string('note')->nullable();


            
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
        Schema::dropIfExists('transaction_payments');
    }
}
