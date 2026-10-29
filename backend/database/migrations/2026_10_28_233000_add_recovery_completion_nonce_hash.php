<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('office_erp_access_deliveries', function (Blueprint $table): void {
            $table->string('password_recovery_completion_nonce_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('office_erp_access_deliveries', function (Blueprint $table): void {
            $table->dropColumn('password_recovery_completion_nonce_hash');
        });
    }
};
