<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_companies', function (Blueprint $table): void {
            $table->string('razao_social', 120)->nullable()->after('name');
            $table->string('ie', 20)->nullable()->after('cnpj');
            $table->json('operating_settings')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('erp_companies', function (Blueprint $table): void {
            $table->dropColumn(['razao_social', 'ie', 'operating_settings']);
        });
    }
};
