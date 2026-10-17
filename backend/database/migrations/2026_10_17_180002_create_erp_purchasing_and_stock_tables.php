<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_purchases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->cascadeOnDelete();
            $table->foreignId('erp_establishment_id')->constrained('erp_establishments')->cascadeOnDelete();
            $table->unsignedBigInteger('erp_contact_id')->nullable();
            $table->string('document_no', 64)->nullable();
            $table->string('status', 16)->default('draft');
            $table->decimal('total_amount', 22, 4)->default(0);
            $table->string('currency', 3)->default('BRL');
            $table->string('source_operation_key', 128)->nullable();
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('supplier_snapshot')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['erp_company_id', 'status']);
            $table->unique(['erp_company_id', 'erp_establishment_id', 'document_no'], 'erp_purchases_document_unique');
            $table->unique(['erp_company_id', 'source_operation_key'], 'erp_purchases_source_operation_unique');
        });

        Schema::create('erp_purchase_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_purchase_id')->constrained('erp_purchases')->cascadeOnDelete();
            $table->unsignedBigInteger('erp_product_variant_id')->nullable();
            $table->decimal('quantity', 22, 4);
            $table->decimal('unit_cost', 22, 4);
            $table->decimal('line_total', 22, 4);
            $table->decimal('unit_conversion_factor', 22, 8)->default(1);
            $table->json('product_snapshot')->nullable();
            $table->timestamps();

            $table->index('erp_purchase_id');
        });

        Schema::create('erp_purchase_returns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_purchase_id')->constrained('erp_purchases')->restrictOnDelete();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->cascadeOnDelete();
            $table->foreignId('erp_establishment_id')->constrained('erp_establishments')->cascadeOnDelete();
            $table->string('status', 16)->default('draft');
            $table->decimal('total_amount', 22, 4)->default(0);
            $table->string('source_operation_key', 128)->nullable();
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->unique(['erp_purchase_id', 'source_operation_key'], 'erp_purchase_returns_operation_unique');
        });

        Schema::create('erp_stock_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->cascadeOnDelete();
            $table->foreignId('erp_establishment_id')->constrained('erp_establishments')->cascadeOnDelete();
            $table->unsignedBigInteger('erp_product_variant_id');
            $table->decimal('quantity_on_hand', 22, 4)->default(0);
            $table->decimal('average_cost', 22, 4)->nullable();
            $table->timestamps();

            $table->unique(['erp_establishment_id', 'erp_product_variant_id'], 'erp_stock_balances_variant_unique');
        });

        Schema::create('erp_stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->cascadeOnDelete();
            $table->foreignId('erp_establishment_id')->constrained('erp_establishments')->cascadeOnDelete();
            $table->unsignedBigInteger('erp_product_variant_id');
            $table->string('direction', 8);
            $table->decimal('quantity', 22, 4);
            $table->decimal('unit_cost', 22, 4)->nullable();
            $table->string('valuation_method', 8)->default('fifo');
            $table->string('source_type', 64);
            $table->unsignedBigInteger('source_id');
            $table->string('source_operation_key', 128);
            $table->unsignedBigInteger('reversal_of_id')->nullable();
            $table->json('allocation_snapshot')->nullable();
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['erp_establishment_id', 'erp_product_variant_id']);
            $table->unique(['source_type', 'source_id', 'source_operation_key'], 'erp_stock_movements_source_operation_unique');
        });

        Schema::create('erp_stock_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->cascadeOnDelete();
            $table->foreignId('erp_establishment_id')->constrained('erp_establishments')->cascadeOnDelete();
            $table->unsignedBigInteger('erp_product_variant_id');
            $table->decimal('quantity_delta', 22, 4);
            $table->string('reason', 255);
            $table->string('status', 16)->default('posted');
            $table->string('source_operation_key', 128)->nullable();
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->unique(['erp_company_id', 'source_operation_key'], 'erp_stock_adjustments_operation_unique');
        });

        Schema::create('erp_stock_transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->cascadeOnDelete();
            $table->foreignId('from_establishment_id')->constrained('erp_establishments')->restrictOnDelete();
            $table->foreignId('to_establishment_id')->constrained('erp_establishments')->restrictOnDelete();
            $table->unsignedBigInteger('erp_product_variant_id');
            $table->decimal('quantity', 22, 4);
            $table->string('status', 16)->default('posted');
            $table->string('source_operation_key', 128)->nullable();
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->unique(['erp_company_id', 'source_operation_key'], 'erp_stock_transfers_operation_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_stock_transfers');
        Schema::dropIfExists('erp_stock_adjustments');
        Schema::dropIfExists('erp_stock_movements');
        Schema::dropIfExists('erp_stock_balances');
        Schema::dropIfExists('erp_purchase_returns');
        Schema::dropIfExists('erp_purchase_lines');
        Schema::dropIfExists('erp_purchases');
    }
};
