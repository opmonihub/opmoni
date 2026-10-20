<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_products', function (Blueprint $table): void {
            $table->string('barcode_type', 10)->default('C128');
        });

        Schema::table('erp_product_variants', function (Blueprint $table): void {
            $table->string('barcode_type', 10)->nullable();
        });

        Schema::create('erp_catalog_print_layouts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('description', 500)->nullable();
            $table->decimal('label_width_mm', 8, 4);
            $table->decimal('label_height_mm', 8, 4);
            $table->decimal('paper_width_mm', 8, 4);
            $table->decimal('paper_height_mm', 8, 4)->nullable();
            $table->decimal('top_margin_mm', 8, 4)->default('0.0000');
            $table->decimal('left_margin_mm', 8, 4)->default('0.0000');
            $table->decimal('row_spacing_mm', 8, 4)->default('0.0000');
            $table->decimal('column_spacing_mm', 8, 4)->default('0.0000');
            $table->unsignedSmallInteger('columns_per_row')->default(1);
            $table->unsignedSmallInteger('labels_per_sheet')->nullable();
            $table->boolean('is_continuous')->default(false);
            $table->boolean('is_default')->default(false);
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->index(['erp_company_id', 'status']);
            $table->index(['erp_company_id', 'is_default']);
        });

        Schema::create('erp_catalog_printer_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('capability_profile', 16);
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->index(['erp_company_id', 'status']);
        });
    }

    public function down(): void
    {
        if ((Schema::hasTable('erp_catalog_print_layouts') && DB::table('erp_catalog_print_layouts')->exists())
            || (Schema::hasTable('erp_catalog_printer_profiles') && DB::table('erp_catalog_printer_profiles')->exists())) {
            throw new RuntimeException('Cannot remove accepted ERP catalog print configuration.');
        }

        if (Schema::hasTable('erp_products') && DB::table('erp_products')->where('barcode_type', '!=', 'C128')->exists()) {
            throw new RuntimeException('Cannot remove non-default product barcode symbology.');
        }

        if (Schema::hasTable('erp_product_variants') && DB::table('erp_product_variants')->whereNotNull('barcode_type')->exists()) {
            throw new RuntimeException('Cannot remove explicit variant barcode symbology.');
        }

        Schema::dropIfExists('erp_catalog_printer_profiles');
        Schema::dropIfExists('erp_catalog_print_layouts');

        Schema::table('erp_product_variants', function (Blueprint $table): void {
            $table->dropColumn('barcode_type');
        });

        Schema::table('erp_products', function (Blueprint $table): void {
            $table->dropColumn('barcode_type');
        });
    }
};
