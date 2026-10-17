<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTransactionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('business_id')->unsigned();
            $table->foreign('business_id')->references('id')->on('business')->onDelete('cascade');
            $table->enum('type', ['purchase', 'sell']);
            $table->enum('status', ['received', 'pending', 'ordered', 'draft', 'final']);
            $table->enum('payment_status', ['paid', 'due']);
            $table->integer('contact_id')->unsigned();
            $table->foreign('contact_id')->references('id')->on('contacts')->onDelete('cascade');
            $table->string('invoice_no')->nullable();
            $table->string('ref_no')->nullable();
            $table->dateTime('transaction_date');
            $table->decimal('total_before_tax', 22, 4)->default(0)->comment('Total before the purchase/invoice tax, this includeds the indivisual product tax');
            $table->integer('tax_id')->unsigned()->nullable();
            $table->foreign('tax_id')->references('id')->on('tax_rates')->onDelete('cascade');
            $table->decimal('tax_amount', 22, 4)->default(0);
            $table->enum('discount_type', ['fixed', 'percentage'])->nullable();
            $table->decimal('discount_amount', 22, 4)->default(0);
            $table->string('shipping_details')->nullable();
            $table->decimal('shipping_charges', 22, 4)->default(0);
            $table->text('additional_notes')->nullable();
            $table->text('staff_note')->nullable();
            $table->decimal('final_total', 22, 4)->default(0);
            $table->integer('created_by')->unsigned();
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
            $table->timestamps();

            //Indexing
            $table->index('business_id');
            $table->index('type');
            $table->index('contact_id');
            $table->index('transaction_date');
            $table->index('created_by');

            $table->integer('natureza_id')->nullable()->unsigned();
            $table->foreign('natureza_id')->references('id')->on('natureza_operacaos');

            $table->string('placa', 9)->default('');
            $table->string('uf', 2)->default('');
            $table->decimal('valor_frete', 10, 2)->default(0);
            $table->integer('tipo')->default(0);
            $table->integer('qtd_volumes')->default(0);
            $table->string('numeracao_volumes', 20)->default('');
            $table->string('especie', 20)->default('');
            $table->decimal('peso_liquido',8, 3)->default(0);
            $table->decimal('peso_bruto',8, 3)->default(0);

            $table->integer('numero_nfe')->default(0);
            $table->integer('numero_nfce')->default(0);
            $table->integer('numero_nfe_entrada')->default(0);

            $table->string('chave',48)->default('');
            $table->string('chave_entrada',48)->default('');
            $table->integer('sequencia_cce')->default(0);
            $table->string('cpf_nota', 15)->default('');
            $table->decimal('troco', 10, 2)->default(0);
            $table->decimal('valor_recebido', 10, 2)->default(0);

            $table->integer('transportadora_id')->nullable()->unsigned();
            $table->foreign('transportadora_id')->references('id')->on('transportadoras')
            ->onDelete('cascade');

            $table->string('estado', 20)->default('NOVO');
            $table->string('recibo', 20)->nullable();
            $table->string('referencia_nfe', 50)->default('');

            $table->integer('pedido_ecommerce_id')->default(0);

            $table->boolean('contigencia')->default(0);
            $table->boolean('reenvio_contigencia')->default(0);

            // alter table transactions add column recibo varchar(30) default null;
            // alter table transactions add column contigencia boolean default null;
            // alter table transactions add column reenvio_contigencia boolean default null;
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('transactions');
    }
}
