<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['erp_obligations', 'erp_payments', 'erp_stock_movements'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->dropUnique($tableName.'_source_operation_unique');
                $table->unique(['erp_company_id', 'source_type', 'source_id', 'source_operation_key'], $tableName.'_source_operation_unique');
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Company-scoped ERP operation keys cannot be narrowed safely: different companies may reuse the same key.');
    }
};
