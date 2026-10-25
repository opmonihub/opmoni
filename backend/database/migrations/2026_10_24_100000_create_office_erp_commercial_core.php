<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->unique(['account_id', 'id'], 'clients_account_id_id_unique');
        });

        Schema::create('office_erp_sales_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamp('archived_at')->nullable();
            $table->unsignedBigInteger('current_revision_id')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'id'], 'office_erp_sales_plans_account_id_id_unique');
            $table->index(['account_id', 'status']);
        });

        Schema::create('office_erp_sales_plan_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('sales_plan_id');
            $table->unsignedInteger('revision_number');
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price', 20, 2);
            $table->char('currency', 3)->default('BRL');
            $table->unsignedSmallInteger('interval_months');
            $table->unsignedSmallInteger('due_in_days')->default(0);
            $table->json('module_keys');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->foreign(['account_id', 'sales_plan_id'], 'office_erp_plan_revisions_plan_account_fk')
                ->references(['account_id', 'id'])->on('office_erp_sales_plans')->restrictOnDelete();
            $table->unique(['account_id', 'sales_plan_id', 'id'], 'office_erp_plan_revisions_account_plan_id_unique');
            $table->unique(['sales_plan_id', 'revision_number'], 'office_erp_plan_revisions_number_unique');
        });

        Schema::table('office_erp_sales_plans', function (Blueprint $table): void {
            $table->foreign(['account_id', 'id', 'current_revision_id'], 'office_erp_plans_current_revision_fk')
                ->references(['account_id', 'sales_plan_id', 'id'])->on('office_erp_sales_plan_revisions')->restrictOnDelete();
        });

        Schema::create('office_erp_contracts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('sales_plan_id')->nullable();
            $table->unsignedBigInteger('erp_company_id')->nullable();
            $table->string('agreement_status', 20)->default('draft');
            $table->unsignedBigInteger('current_revision_id')->nullable();
            $table->unsignedBigInteger('pending_revision_id')->nullable();
            $table->date('service_anchor_on')->nullable();
            $table->string('billing_timezone', 64)->default('America/Sao_Paulo');
            $table->unsignedInteger('next_period_index')->nullable();
            $table->unsignedInteger('next_period_month_offset')->nullable();
            $table->date('cancel_effective_on')->nullable();
            $table->boolean('manual_hold')->default(false);
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('effective_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->foreign(['account_id', 'client_id'], 'office_erp_contract_client_account_fk')
                ->references(['account_id', 'id'])->on('clients')->restrictOnDelete();
            $table->foreign(['account_id', 'sales_plan_id'], 'office_erp_contract_sales_plan_account_fk')
                ->references(['account_id', 'id'])->on('office_erp_sales_plans')->restrictOnDelete();
            $table->foreign('erp_company_id')->references('id')->on('erp_companies')->restrictOnDelete();
            $table->unique(['account_id', 'id'], 'office_erp_contracts_account_id_id_unique');
            $table->unique(['account_id', 'id', 'erp_company_id'], 'office_erp_contracts_company_scope_unique');
            $table->unique(['erp_company_id', 'id'], 'office_erp_contracts_company_id_unique');
            $table->index(['account_id', 'agreement_status']);
        });

        Schema::create('office_erp_contract_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('contract_id');
            $table->unsignedInteger('revision_number');
            $table->decimal('price', 20, 2);
            $table->char('currency', 3)->default('BRL');
            $table->unsignedSmallInteger('interval_months');
            $table->unsignedSmallInteger('due_in_days')->default(0);
            $table->json('module_keys');
            $table->date('service_period_start')->nullable();
            $table->date('service_period_end')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['account_id', 'contract_id'], 'office_erp_contract_revisions_contract_fk')
                ->references(['account_id', 'id'])->on('office_erp_contracts')->restrictOnDelete();
            $table->unique(['account_id', 'contract_id', 'id'], 'office_erp_contract_revisions_scope_unique');
            $table->unique(['contract_id', 'revision_number'], 'office_erp_contract_revisions_number_unique');
        });

        Schema::table('office_erp_contracts', function (Blueprint $table): void {
            $table->foreign(['account_id', 'id', 'current_revision_id'], 'office_erp_contract_current_revision_fk')
                ->references(['account_id', 'contract_id', 'id'])->on('office_erp_contract_revisions')->restrictOnDelete();
            $table->foreign(['account_id', 'id', 'pending_revision_id'], 'office_erp_contract_pending_revision_fk')
                ->references(['account_id', 'contract_id', 'id'])->on('office_erp_contract_revisions')->restrictOnDelete();
        });

        Schema::create('office_erp_licence_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('contract_id');
            $table->unsignedBigInteger('erp_company_id')->nullable();
            $table->string('state', 16);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->foreign(['account_id', 'contract_id'], 'office_erp_allocations_contract_fk')
                ->references(['account_id', 'id'])->on('office_erp_contracts')->restrictOnDelete();
            $table->foreign('erp_company_id')->references('id')->on('erp_companies')->restrictOnDelete();
            $table->index(['account_id', 'state', 'expires_at']);
        });

        Schema::create('office_erp_commercial_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('actor_kind', 20);
            $table->string('actor_identity', 191);
            $table->string('idempotency_key', 191);
            $table->string('action', 100);
            $table->char('payload_hash', 64);
            $table->json('outcome')->nullable();
            $table->timestamps();

            $table->unique(
                ['account_id', 'actor_kind', 'actor_identity', 'idempotency_key'],
                'office_erp_operations_identity_unique',
            );
            $table->unique(['account_id', 'id'], 'office_erp_operations_account_id_id_unique');
        });

        Schema::create('office_erp_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('operation_id')->nullable();
            $table->string('actor_kind', 20);
            $table->string('actor_identity', 191);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 100);
            $table->string('subject_type', 191)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign(['account_id', 'operation_id'], 'office_erp_audit_operation_account_fk')
                ->references(['account_id', 'id'])->on('office_erp_commercial_operations')->restrictOnDelete();
            $table->index(['account_id', 'created_at']);
        });

        Schema::table('erp_subscriptions', function (Blueprint $table): void {
            $table->unsignedBigInteger('office_erp_contract_id')->nullable();
            $table->foreign(['erp_company_id', 'office_erp_contract_id'], 'erp_subscriptions_office_contract_company_fk')
                ->references(['erp_company_id', 'id'])->on('office_erp_contracts')->restrictOnDelete();
            $table->index('office_erp_contract_id');
        });

        DB::statement("CREATE UNIQUE INDEX office_erp_allocations_one_current_per_contract ON office_erp_licence_allocations (contract_id) WHERE state IN ('reserved', 'occupied')");
        DB::statement("CREATE UNIQUE INDEX office_erp_allocations_one_current_per_company ON office_erp_licence_allocations (erp_company_id) WHERE erp_company_id IS NOT NULL AND state IN ('reserved', 'occupied')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS office_erp_allocations_one_current_per_company');
        DB::statement('DROP INDEX IF EXISTS office_erp_allocations_one_current_per_contract');

        Schema::table('erp_subscriptions', function (Blueprint $table): void {
            $table->dropForeign('erp_subscriptions_office_contract_company_fk');
            $table->dropIndex('erp_subscriptions_office_erp_contract_id_index');
            $table->dropColumn('office_erp_contract_id');
        });

        Schema::dropIfExists('office_erp_audit_events');
        Schema::dropIfExists('office_erp_commercial_operations');
        Schema::dropIfExists('office_erp_licence_allocations');

        Schema::table('office_erp_contracts', function (Blueprint $table): void {
            $table->dropForeign('office_erp_contract_current_revision_fk');
            $table->dropForeign('office_erp_contract_pending_revision_fk');
        });

        Schema::dropIfExists('office_erp_contract_revisions');
        Schema::dropIfExists('office_erp_contracts');

        Schema::table('office_erp_sales_plans', function (Blueprint $table): void {
            $table->dropForeign('office_erp_plans_current_revision_fk');
        });

        Schema::dropIfExists('office_erp_sales_plan_revisions');
        Schema::dropIfExists('office_erp_sales_plans');

        Schema::table('clients', function (Blueprint $table): void {
            $table->dropUnique('clients_account_id_id_unique');
        });
    }
};
