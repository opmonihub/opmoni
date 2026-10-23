<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_commercial_number_sequences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->restrictOnDelete();
            $table->foreignId('erp_establishment_id')->constrained('erp_establishments')->restrictOnDelete();
            $table->string('document_type', 16);
            $table->unsignedBigInteger('next_number')->default(1);
            $table->unique(['erp_company_id', 'erp_establishment_id', 'document_type'], 'erp_commercial_number_scope_unique');
        });
        foreach (['quotes', 'orders', 'sales'] as $kind) {
            Schema::table('erp_'.$kind, function (Blueprint $table) use ($kind): void {
                $table->unsignedInteger('draft_revision')->nullable();
                $table->json('commercial_snapshot')->nullable();
                $table->json('draft_input')->nullable();
                $table->unsignedBigInteger('erp_price_group_id')->nullable();
                $table->unsignedBigInteger('erp_operation_nature_id')->nullable();
                $table->unique(['id', 'erp_company_id'], 'erp_'.$kind.'_commercial_company_identity');
                if ($kind !== 'sales') {
                    $table->decimal('subtotal_amount', 22, 4)->nullable();
                    $table->decimal('discount_amount', 22, 4)->nullable();
                    $table->decimal('tax_amount', 22, 4)->nullable();
                }
            });
        }
        foreach (['quote', 'order'] as $kind) {
            Schema::create('erp_'.$kind.'_lines', function (Blueprint $table) use ($kind): void {
                $table->id();
                $table->unsignedBigInteger('erp_company_id');
                $table->foreignId('erp_'.$kind.'_id')->constrained('erp_'.$kind.'s')->restrictOnDelete();
                $table->unsignedBigInteger('erp_product_variant_id')->nullable();
                $table->decimal('quantity', 22, 4);
                $table->decimal('unit_price', 22, 4);
                $table->decimal('unit_conversion_factor', 22, 8);
                $table->decimal('discount_amount', 22, 4);
                $table->decimal('tax_amount', 22, 4);
                $table->decimal('line_total', 22, 4);
                $table->json('product_snapshot');
                $table->timestamps();
                $table->foreign(['erp_'.$kind.'_id', 'erp_company_id'], 'erp_'.$kind.'_line_company_fk')->references(['id', 'erp_company_id'])->on('erp_'.$kind.'s')->restrictOnDelete();
            });
        }
        foreach (['quote', 'order', 'sale'] as $kind) {
            Schema::table('erp_'.$kind.'_lines', function (Blueprint $table) use ($kind): void {
                $table->unsignedInteger('ordinal')->nullable();
                if ($kind === 'sale') {
                    $table->unsignedBigInteger('erp_company_id')->nullable();
                }
                $table->unique(['id', 'erp_'.$kind.'_id', 'erp_company_id'], 'erp_'.$kind.'_line_identity');
                $table->unsignedBigInteger('erp_unit_id')->nullable();
                $table->decimal('gross_amount', 22, 4)->nullable();
                $table->decimal('base_quantity', 22, 4)->nullable();
                if ($kind !== 'quote') {
                    $table->unsignedBigInteger('source_quote_line_id')->nullable();
                    $table->unsignedBigInteger('source_quote_document_id')->nullable();
                    $table->foreign(['source_quote_line_id', 'source_quote_document_id', 'erp_company_id'], 'erp_'.$kind.'_quote_lineage_fk')->references(['id', 'erp_quote_id', 'erp_company_id'])->on('erp_quote_lines')->restrictOnDelete();
                }
                if ($kind === 'sale') {
                    $table->unsignedBigInteger('source_order_line_id')->nullable();
                    $table->unsignedBigInteger('source_order_document_id')->nullable();
                    $table->foreign(['source_order_line_id', 'source_order_document_id', 'erp_company_id'], 'erp_sale_order_lineage_fk')->references(['id', 'erp_order_id', 'erp_company_id'])->on('erp_order_lines')->restrictOnDelete();
                }
            });
        }
        Schema::table('erp_orders', fn (Blueprint $table) => $table->unsignedBigInteger('conversion_source_id')->nullable()->unique('erp_orders_one_quote_conversion'));
        Schema::table('erp_sales', fn (Blueprint $table) => $table->unsignedBigInteger('conversion_source_id')->nullable()->unique('erp_sales_one_order_conversion'));
        Schema::table('erp_orders', fn (Blueprint $table) => $table->unique(['id', 'erp_quote_id', 'erp_company_id'], 'erp_order_quote_parent_identity'));
        Schema::table('erp_order_lines', function (Blueprint $table): void {
            $table->foreign(['erp_order_id', 'source_quote_document_id', 'erp_company_id'], 'erp_order_quote_parent_fk')->references(['id', 'erp_quote_id', 'erp_company_id'])->on('erp_orders')->restrictOnDelete();
        });
        Schema::table('erp_sales', fn (Blueprint $table) => $table->unique(['id', 'erp_order_id', 'erp_company_id'], 'erp_sale_order_parent_identity'));
        Schema::table('erp_sale_lines', function (Blueprint $table): void {
            $table->foreign(['erp_sale_id', 'source_order_document_id', 'erp_company_id'], 'erp_sale_order_parent_fk')->references(['id', 'erp_order_id', 'erp_company_id'])->on('erp_sales')->restrictOnDelete();
            $table->foreign(['erp_sale_id', 'erp_company_id'], 'erp_sale_line_company_fk')->references(['id', 'erp_company_id'])->on('erp_sales')->restrictOnDelete();
        });
        Schema::table('erp_purchases', fn (Blueprint $table) => $table->unique(['id', 'erp_company_id'], 'erp_purchase_company_identity'));
        Schema::table('erp_purchase_lines', fn (Blueprint $table) => $table->unique(['id', 'erp_purchase_id'], 'erp_purchase_line_source_identity'));
        Schema::table('erp_sale_lines', fn (Blueprint $table) => $table->unique(['id', 'erp_sale_id'], 'erp_sale_line_source_identity'));
        foreach (['sale', 'purchase'] as $kind) {
            Schema::table('erp_'.$kind.'_returns', function (Blueprint $table) use ($kind): void {
                $table->unique(['id', 'erp_'.$kind.'_id', 'erp_company_id'], 'erp_'.$kind.'_return_source_identity');
                $table->text('reason')->nullable();
                $table->string('currency', 3)->nullable();
                $table->decimal('subtotal_amount', 22, 4)->nullable();
                $table->decimal('discount_amount', 22, 4)->nullable();
                $table->decimal('tax_amount', 22, 4)->nullable();
                $table->json('commercial_snapshot')->nullable();
            });
            Schema::create('erp_'.$kind.'_return_lines', function (Blueprint $table) use ($kind): void {
                $table->id();
                $table->foreignId('erp_'.$kind.'_return_id')->constrained('erp_'.$kind.'_returns')->restrictOnDelete();
                $table->unsignedBigInteger('source_line_id');
                $table->unsignedBigInteger('source_document_id');
                $table->unsignedBigInteger('erp_company_id');
                $table->foreign(['erp_'.$kind.'_return_id', 'source_document_id', 'erp_company_id'], 'erp_'.$kind.'_return_parent_identity_fk')->references(['id', 'erp_'.$kind.'_id', 'erp_company_id'])->on('erp_'.$kind.'_returns')->restrictOnDelete();
                $table->foreign(['source_line_id', 'source_document_id'], 'erp_'.$kind.'_return_line_source_fk')->references(['id', 'erp_'.$kind.'_id'])->on('erp_'.$kind.'_lines')->restrictOnDelete();
                $table->foreign(['source_document_id', 'erp_company_id'], 'erp_'.$kind.'_return_source_company_fk')->references(['id', 'erp_company_id'])->on('erp_'.$kind.'s')->restrictOnDelete();
                $table->decimal('quantity', 22, 4);
                $table->decimal('base_quantity', 22, 4);
                $table->decimal('unit_conversion_factor', 22, 8);
                $table->decimal('subtotal_amount', 22, 4);
                $table->decimal('discount_amount', 22, 4);
                $table->decimal('tax_amount', 22, 4);
                $table->decimal('total_amount', 22, 4);
                $table->json('source_snapshot');
                $table->timestamps();
                $table->unique(['erp_'.$kind.'_return_id', 'source_line_id'], 'erp_'.$kind.'_return_line_unique');
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Commercial history requires a compatible application rollback.');
    }
};
