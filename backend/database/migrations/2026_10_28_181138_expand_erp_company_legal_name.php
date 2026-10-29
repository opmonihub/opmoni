<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_companies', function (Blueprint $table): void {
            $table->string('razao_social', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Compatible application recovery retains longer names and the additive schema.
    }
};
