<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('office_erp_access_deliveries', function (Blueprint $table): void {
            $table->timestamp('password_recovered_at')->nullable();
            $table->unsignedBigInteger('password_recovered_auth_revision')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('office_erp_access_deliveries', function (Blueprint $table): void {
            $table->dropColumn(['password_recovered_at', 'password_recovered_auth_revision']);
        });
    }
};
