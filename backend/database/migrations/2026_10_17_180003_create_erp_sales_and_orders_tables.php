<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_invoice_schemes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->cascadeOnDelete();
            $table->string('name');
            $table->string('prefix', 16)->nullable();
            $table->unsignedInteger('start_number')->default(1);
            $table->unsignedInteger('invoice_count')->default(0);
            $table->unsignedInteger('total_digits')->default(4);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['erp_company_id', 'is_default']);
        });

        Schema::create('erp_invoice_layouts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->cascadeOnDelete();
            $table->string('name');
            $table->json('layout_settings')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('erp_quotes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->cascadeOnDelete();
            $table->foreignId('erp_establishment_id')->constrained('erp_establishments')->cascadeOnDelete();
            $table->unsignedBigInteger('erp_contact_id')->nullable();
            $table->string('quote_no', 64);
            $table->string('status', 16)->default('draft');
            $table->decimal('total_amount', 22, 4)->default(0);
            $table->string('currency', 3)->default('BRL');
            $table->json('party_snapshot')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['erp_company_id', 'erp_establishment_id', 'quote_no'], 'erp_quotes_number_unique');
        });

        Schema::create('erp_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->cascadeOnDelete();
            $table->foreignId('erp_establishment_id')->constrained('erp_establishments')->cascadeOnDelete();
            $table->unsignedBigInteger('erp_quote_id')->nullable();
            $table->unsignedBigInteger('erp_contact_id')->nullable();
            $table->string('order_no', 64);
            $table->string('status', 16)->default('confirmed');
            $table->decimal('total_amount', 22, 4)->default(0);
            $table->string('currency', 3)->default('BRL');
            $table->json('party_snapshot')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['erp_company_id', 'erp_establishment_id', 'order_no'], 'erp_orders_number_unique');
        });

        Schema::create('erp_sales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->cascadeOnDelete();
            $table->foreignId('erp_establishment_id')->constrained('erp_establishments')->cascadeOnDelete();
            $table->unsignedBigInteger('erp_order_id')->nullable();
            $table->unsignedBigInteger('erp_contact_id')->nullable();
            $table->foreignId('erp_invoice_scheme_id')->nullable()->constrained('erp_invoice_schemes')->nullOnDelete();
            $table->foreignId('erp_invoice_layout_id')->nullable()->constrained('erp_invoice_layouts')->nullOnDelete();
            $table->string('invoice_no', 64);
            $table->string('status', 16)->default('draft');
            $table->string('fiscal_status', 16)->default('none');
            $table->decimal('subtotal_amount', 22, 4)->default(0);
            $table->decimal('discount_amount', 22, 4)->default(0);
            $table->decimal('tax_amount', 22, 4)->default(0);
            $table->decimal('total_amount', 22, 4)->default(0);
            $table->string('currency', 3)->default('BRL');
            $table->string('source_operation_key', 128)->nullable();
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('party_snapshot')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['erp_company_id', 'status']);
            $table->unique(['erp_company_id', 'erp_establishment_id', 'invoice_no'], 'erp_sales_invoice_unique');
            $table->unique(['erp_company_id', 'source_operation_key'], 'erp_sales_source_operation_unique');
        });

        Schema::create('erp_sale_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_sale_id')->constrained('erp_sales')->cascadeOnDelete();
            $table->unsignedBigInteger('erp_product_variant_id')->nullable();
            $table->decimal('quantity', 22, 4);
            $table->decimal('unit_price', 22, 4);
            $table->decimal('discount_amount', 22, 4)->default(0);
            $table->decimal('tax_amount', 22, 4)->default(0);
            $table->decimal('line_total', 22, 4);
            $table->json('product_snapshot')->nullable();
            $table->timestamps();

            $table->index('erp_sale_id');
        });

        Schema::create('erp_sale_returns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_sale_id')->constrained('erp_sales')->restrictOnDelete();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->cascadeOnDelete();
            $table->foreignId('erp_establishment_id')->constrained('erp_establishments')->cascadeOnDelete();
            $table->string('status', 16)->default('draft');
            $table->decimal('total_amount', 22, 4)->default(0);
            $table->string('source_operation_key', 128)->nullable();
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->unique(['erp_sale_id', 'source_operation_key'], 'erp_sale_returns_operation_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_sale_returns');
        Schema::dropIfExists('erp_sale_lines');
        Schema::dropIfExists('erp_sales');
        Schema::dropIfExists('erp_orders');
        Schema::dropIfExists('erp_quotes');
        Schema::dropIfExists('erp_invoice_layouts');
        Schema::dropIfExists('erp_invoice_schemes');
    }
};
