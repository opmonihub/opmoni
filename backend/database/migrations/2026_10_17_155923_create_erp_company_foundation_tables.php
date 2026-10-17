<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_companies', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('cnpj', 14);
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique('cnpj');
        });

        Schema::create('erp_establishments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->cascadeOnDelete();
            $table->string('name');
            $table->string('cnpj', 14)->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->index(['erp_company_id', 'status']);
        });

        Schema::create('erp_company_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16);
            $table->json('action_permissions')->nullable();
            $table->timestamps();

            $table->unique(['erp_company_id', 'user_id']);
            $table->index(['user_id', 'erp_company_id']);
        });

        Schema::create('erp_office_delegations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('authorized_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->json('permission_allowlist');
            $table->string('status', 16)->default('active');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['erp_company_id', 'status']);
            $table->index(['account_id', 'status']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX erp_office_delegations_one_active ON erp_office_delegations (erp_company_id) WHERE status = 'active'");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS erp_office_delegations_one_active');
        }

        Schema::dropIfExists('erp_office_delegations');
        Schema::dropIfExists('erp_company_memberships');
        Schema::dropIfExists('erp_establishments');
        Schema::dropIfExists('erp_companies');
    }
};
