<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_financial_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->cascadeOnDelete();
            $table->foreignId('erp_establishment_id')->nullable()->constrained('erp_establishments')->nullOnDelete();
            $table->string('name');
            $table->string('account_type', 32)->default('saving_current');
            $table->string('currency', 3)->default('BRL');
            $table->string('status', 16)->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['erp_company_id', 'status']);
        });

        Schema::create('erp_obligations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->cascadeOnDelete();
            $table->foreignId('erp_establishment_id')->nullable()->constrained('erp_establishments')->nullOnDelete();
            $table->unsignedBigInteger('erp_contact_id')->nullable();
            $table->foreignId('erp_financial_account_id')->nullable()->constrained('erp_financial_accounts')->nullOnDelete();
            $table->string('direction', 16);
            $table->string('category', 32);
            $table->decimal('amount', 22, 4);
            $table->decimal('open_amount', 22, 4);
            $table->string('currency', 3)->default('BRL');
            $table->date('due_on')->nullable();
            $table->string('status', 16)->default('open');
            $table->string('source_type', 64)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_operation_key', 128)->nullable();
            $table->unsignedBigInteger('reversal_of_id')->nullable();
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('party_snapshot')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['erp_company_id', 'status']);
            $table->index(['source_type', 'source_id']);
            $table->unique(['source_type', 'source_id', 'source_operation_key'], 'erp_obligations_source_operation_unique');
        });

        Schema::create('erp_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->cascadeOnDelete();
            $table->foreignId('erp_establishment_id')->nullable()->constrained('erp_establishments')->nullOnDelete();
            $table->foreignId('erp_financial_account_id')->nullable()->constrained('erp_financial_accounts')->nullOnDelete();
            $table->decimal('amount', 22, 4);
            $table->string('currency', 3)->default('BRL');
            $table->string('method', 32);
            $table->string('status', 16)->default('posted');
            $table->string('source_type', 64)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_operation_key', 128)->nullable();
            $table->unsignedBigInteger('reversal_of_id')->nullable();
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['erp_company_id', 'status']);
            $table->unique(['source_type', 'source_id', 'source_operation_key'], 'erp_payments_source_operation_unique');
        });

        Schema::create('erp_payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_payment_id')->constrained('erp_payments')->cascadeOnDelete();
            $table->foreignId('erp_obligation_id')->constrained('erp_obligations')->restrictOnDelete();
            $table->decimal('amount', 22, 4);
            $table->string('source_operation_key', 128)->nullable();
            $table->unsignedBigInteger('reversal_of_id')->nullable();
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->unique(['erp_payment_id', 'erp_obligation_id', 'source_operation_key'], 'erp_payment_allocations_operation_unique');
        });

        Schema::create('erp_cash_registers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->cascadeOnDelete();
            $table->foreignId('erp_establishment_id')->constrained('erp_establishments')->cascadeOnDelete();
            $table->string('name');
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->index(['erp_company_id', 'erp_establishment_id']);
        });

        Schema::create('erp_cash_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_cash_register_id')->constrained('erp_cash_registers')->cascadeOnDelete();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->cascadeOnDelete();
            $table->foreignId('erp_establishment_id')->constrained('erp_establishments')->cascadeOnDelete();
            $table->foreignId('operator_user_id')->constrained('users')->restrictOnDelete();
            $table->decimal('opening_amount', 22, 4)->default(0);
            $table->decimal('expected_closing_amount', 22, 4)->nullable();
            $table->decimal('declared_closing_amount', 22, 4)->nullable();
            $table->decimal('closing_difference', 22, 4)->nullable();
            $table->string('status', 16)->default('open');
            $table->string('close_operation_key', 128)->nullable();
            $table->foreignId('opened_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['erp_cash_register_id', 'status']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX erp_cash_sessions_one_open_per_register ON erp_cash_sessions (erp_cash_register_id) WHERE status = 'open'");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS erp_cash_sessions_one_open_per_register');
        }

        Schema::dropIfExists('erp_cash_sessions');
        Schema::dropIfExists('erp_cash_registers');
        Schema::dropIfExists('erp_payment_allocations');
        Schema::dropIfExists('erp_payments');
        Schema::dropIfExists('erp_obligations');
        Schema::dropIfExists('erp_financial_accounts');
    }
};
