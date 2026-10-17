<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->cascadeOnDelete();
            $table->string('name');
            $table->string('trade_name')->nullable();
            $table->string('tax_id', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->boolean('is_customer')->default(false);
            $table->boolean('is_supplier')->default(false);
            $table->json('address')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->index(['erp_company_id', 'status']);
            $table->index(['erp_company_id', 'name']);
        });

        Schema::create('erp_units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->cascadeOnDelete();
            $table->string('short_name', 16);
            $table->string('actual_name', 64);
            $table->boolean('allow_decimal')->default(true);
            $table->foreignId('base_unit_id')->nullable()->constrained('erp_units')->nullOnDelete();
            $table->decimal('conversion_factor', 20, 8)->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['erp_company_id', 'short_name']);
        });

        Schema::create('erp_brands', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->cascadeOnDelete();
            $table->string('name');
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->index(['erp_company_id', 'status']);
        });

        Schema::create('erp_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('erp_categories')->nullOnDelete();
            $table->string('name');
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->index(['erp_company_id', 'status']);
        });

        Schema::create('erp_price_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->decimal('percentage_adjustment', 10, 4)->default('0');
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->index(['erp_company_id', 'status']);
        });

        Schema::create('erp_discount_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->cascadeOnDelete();
            $table->string('name');
            $table->string('kind', 16);
            $table->decimal('value', 20, 4);
            $table->string('coupon_code', 64)->nullable();
            $table->json('conditions')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->index(['erp_company_id', 'status']);
        });

        Schema::create('erp_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->cascadeOnDelete();
            $table->string('sku', 64)->nullable();
            $table->string('name');
            $table->string('kind', 16)->default('goods');
            $table->boolean('track_stock')->default(true);
            $table->foreignId('erp_unit_id')->constrained('erp_units');
            $table->foreignId('erp_brand_id')->nullable()->constrained('erp_brands')->nullOnDelete();
            $table->foreignId('erp_category_id')->nullable()->constrained('erp_categories')->nullOnDelete();
            $table->decimal('sale_price', 20, 4)->default('0');
            $table->unsignedSmallInteger('warranty_months')->nullable();
            $table->string('warranty_description')->nullable();
            $table->string('barcode', 64)->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->index(['erp_company_id', 'status']);
            $table->index(['erp_company_id', 'sku']);
        });

        Schema::create('erp_product_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_product_id')->constrained('erp_products')->cascadeOnDelete();
            $table->string('sku', 64)->nullable();
            $table->string('name');
            $table->decimal('sale_price', 20, 4)->nullable();
            $table->string('barcode', 64)->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->index(['erp_product_id', 'status']);
        });

        Schema::create('erp_tax_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->cascadeOnDelete();
            $table->foreignId('erp_establishment_id')->nullable()->constrained('erp_establishments')->nullOnDelete();
            $table->string('name');
            $table->decimal('rate', 10, 4)->default('0');
            $table->string('tax_kind', 32)->default('icms');
            $table->json('metadata')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->index(['erp_company_id', 'status']);
        });

        Schema::create('erp_operation_natures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->cascadeOnDelete();
            $table->foreignId('erp_establishment_id')->nullable()->constrained('erp_establishments')->nullOnDelete();
            $table->string('cfop', 8);
            $table->string('description');
            $table->string('movement', 8)->default('out');
            $table->json('fiscal_metadata')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->index(['erp_company_id', 'status']);
        });

        Schema::create('erp_carriers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->cascadeOnDelete();
            $table->string('legal_name');
            $table->string('tax_id', 20)->nullable();
            $table->string('ie', 20)->nullable();
            $table->json('address')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->index(['erp_company_id', 'status']);
        });

        Schema::create('erp_vehicles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->cascadeOnDelete();
            $table->foreignId('erp_carrier_id')->nullable()->constrained('erp_carriers')->nullOnDelete();
            $table->string('vehicle_type', 32)->nullable();
            $table->string('plate', 16);
            $table->string('uf', 2)->nullable();
            $table->string('color', 32)->nullable();
            $table->string('model', 64)->nullable();
            $table->string('brand', 64)->nullable();
            $table->string('wheel_type', 32)->nullable();
            $table->string('body_type', 32)->nullable();
            $table->string('owner_type', 32)->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->index(['erp_company_id', 'status']);
        });

        Schema::create('erp_drivers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->cascadeOnDelete();
            $table->string('name');
            $table->string('tax_id', 20)->nullable();
            $table->string('license_number', 32)->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->index(['erp_company_id', 'status']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX erp_brands_name_active ON erp_brands (erp_company_id, lower(name)) WHERE status = 'active'");
            DB::statement("CREATE UNIQUE INDEX erp_categories_name_active ON erp_categories (erp_company_id, lower(name)) WHERE status = 'active'");
            DB::statement("CREATE UNIQUE INDEX erp_price_groups_name_active ON erp_price_groups (erp_company_id, lower(name)) WHERE status = 'active'");
            DB::statement("CREATE UNIQUE INDEX erp_discount_coupon_active ON erp_discount_rules (erp_company_id, lower(coupon_code)) WHERE status = 'active' AND coupon_code IS NOT NULL");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS erp_discount_coupon_active');
            DB::statement('DROP INDEX IF EXISTS erp_price_groups_name_active');
            DB::statement('DROP INDEX IF EXISTS erp_categories_name_active');
            DB::statement('DROP INDEX IF EXISTS erp_brands_name_active');
        }

        Schema::dropIfExists('erp_drivers');
        Schema::dropIfExists('erp_vehicles');
        Schema::dropIfExists('erp_carriers');
        Schema::dropIfExists('erp_operation_natures');
        Schema::dropIfExists('erp_tax_rates');
        Schema::dropIfExists('erp_product_variants');
        Schema::dropIfExists('erp_products');
        Schema::dropIfExists('erp_discount_rules');
        Schema::dropIfExists('erp_price_groups');
        Schema::dropIfExists('erp_categories');
        Schema::dropIfExists('erp_brands');
        Schema::dropIfExists('erp_units');
        Schema::dropIfExists('erp_contacts');
    }
};
