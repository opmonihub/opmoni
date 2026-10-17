<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->json('quotas')->nullable();
            $table->timestamps();
        });

        Schema::create('erp_plan_modules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_plan_id')->constrained('erp_plans')->cascadeOnDelete();
            $table->string('module_key', 64);
            $table->unsignedInteger('quota')->nullable();
            $table->timestamps();

            $table->unique(['erp_plan_id', 'module_key']);
        });

        Schema::create('erp_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->unique()->constrained('erp_companies')->cascadeOnDelete();
            $table->foreignId('erp_plan_id')->constrained('erp_plans')->restrictOnDelete();
            $table->string('status', 16);
            $table->timestamps();

            $table->index(['status', 'erp_plan_id']);
        });

        Schema::create('erp_commercial_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('super_admin_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('erp_company_id')->nullable()->constrained('erp_companies')->nullOnDelete();
            $table->string('action', 64);
            $table->json('metadata')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_commercial_audit_logs');
        Schema::dropIfExists('erp_subscriptions');
        Schema::dropIfExists('erp_plan_modules');
        Schema::dropIfExists('erp_plans');
    }
};
