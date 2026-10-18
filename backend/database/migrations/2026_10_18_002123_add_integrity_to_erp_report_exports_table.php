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
        Schema::table('erp_report_exports', function (Blueprint $table): void {
            $table->string('content_sha256', 64)->nullable();
            $table->unsignedBigInteger('content_bytes')->nullable();
            $table->string('failure_code', 64)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('erp_report_exports', function (Blueprint $table): void {
            $table->dropColumn(['content_sha256', 'content_bytes', 'failure_code']);
        });
    }
};
