<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('client_certificates', function (Blueprint $table): void {
            $table->text('password_encrypted')->nullable()->after('sha256');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('client_certificates', function (Blueprint $table): void {
            $table->dropColumn('password_encrypted');
        });
    }
};
