<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_report_exports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('report_type', 32);
            $table->json('filters');
            $table->string('status', 16)->default('pending');
            $table->string('storage_path')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['erp_company_id', 'report_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_report_exports');
    }
};
