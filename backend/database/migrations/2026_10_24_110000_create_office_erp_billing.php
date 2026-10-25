<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('office_erp_invoice_counters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
            $table->unique(['account_id', 'year'], 'office_erp_invoice_counters_account_year_unique');
        });

        Schema::create('office_erp_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('contract_id');
            $table->unsignedBigInteger('contract_revision_id');
            $table->string('kind', 16)->default('manual');
            $table->string('document_status', 16)->default('draft');
            $table->string('number', 32)->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->date('period_start');
            $table->date('period_end');
            $table->date('due_on');
            $table->char('currency', 3)->default('BRL');
            $table->json('seller_snapshot');
            $table->json('bill_to_snapshot');
            $table->json('items_snapshot');
            $table->decimal('original_total', 20, 2);
            $table->timestamps();

            $table->foreign(['account_id', 'contract_id'], 'office_erp_invoices_contract_account_fk')
                ->references(['account_id', 'id'])->on('office_erp_contracts')->restrictOnDelete();
            $table->foreign(['account_id', 'contract_id', 'contract_revision_id'], 'office_erp_invoices_revision_account_fk')
                ->references(['account_id', 'contract_id', 'id'])->on('office_erp_contract_revisions')->restrictOnDelete();
            $table->unique(['account_id', 'id'], 'office_erp_invoices_account_id_id_unique');
            $table->unique(['account_id', 'number'], 'office_erp_invoices_account_number_unique');
            $table->index(['account_id', 'document_status', 'due_on']);
        });

        DB::statement("CREATE UNIQUE INDEX office_erp_invoices_recurring_period_unique ON office_erp_invoices (account_id, contract_id, period_start, period_end) WHERE kind = 'recurring'");

        Schema::create('office_erp_invoice_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('invoice_id');
            $table->string('direction', 8);
            $table->decimal('amount', 20, 2);
            $table->text('reason');
            $table->unsignedBigInteger('correction_of_id')->nullable();
            $table->unsignedBigInteger('operation_id')->nullable();
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->useCurrent();
            $table->timestamps();

            $table->foreign(['account_id', 'invoice_id'], 'office_erp_adjustments_invoice_account_fk')
                ->references(['account_id', 'id'])->on('office_erp_invoices')->restrictOnDelete();
            $table->unique(['account_id', 'id'], 'office_erp_adjustments_account_id_id_unique');
            $table->foreign(['account_id', 'correction_of_id'], 'office_erp_adjustments_correction_account_fk')
                ->references(['account_id', 'id'])->on('office_erp_invoice_adjustments')->restrictOnDelete();
            $table->foreign(['account_id', 'operation_id'], 'office_erp_adjustments_operation_account_fk')
                ->references(['account_id', 'id'])->on('office_erp_commercial_operations')->restrictOnDelete();
            $table->index(['account_id', 'invoice_id', 'posted_at']);
        });

        Schema::create('office_erp_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 20, 2);
            $table->date('received_on');
            $table->string('reference', 191)->nullable();
            $table->string('proof_path')->nullable();
            $table->string('method', 24)->default('manual');
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('operation_id')->nullable();
            $table->timestamps();

            $table->foreign(['account_id', 'operation_id'], 'office_erp_receipts_operation_account_fk')
                ->references(['account_id', 'id'])->on('office_erp_commercial_operations')->restrictOnDelete();
            $table->unique(['account_id', 'id'], 'office_erp_receipts_account_id_id_unique');
            $table->index(['account_id', 'received_on']);
        });

        Schema::create('office_erp_receipt_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('receipt_id');
            $table->unsignedBigInteger('invoice_id');
            $table->decimal('amount', 20, 2);
            $table->unsignedBigInteger('operation_id')->nullable();
            $table->timestamps();

            $table->foreign(['account_id', 'receipt_id'], 'office_erp_allocations_receipt_account_fk')
                ->references(['account_id', 'id'])->on('office_erp_receipts')->restrictOnDelete();
            $table->foreign(['account_id', 'invoice_id'], 'office_erp_allocations_invoice_account_fk')
                ->references(['account_id', 'id'])->on('office_erp_invoices')->restrictOnDelete();
            $table->foreign(['account_id', 'operation_id'], 'office_erp_allocations_operation_account_fk')
                ->references(['account_id', 'id'])->on('office_erp_commercial_operations')->restrictOnDelete();
            $table->unique(['account_id', 'receipt_id', 'invoice_id'], 'office_erp_allocations_receipt_invoice_unique');
            $table->index(['account_id', 'invoice_id']);
        });

        Schema::create('office_erp_receipt_reversals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('receipt_id');
            $table->text('reason');
            $table->foreignId('reversed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('operation_id')->nullable();
            $table->timestamp('reversed_at')->useCurrent();
            $table->timestamps();

            $table->foreign(['account_id', 'receipt_id'], 'office_erp_reversals_receipt_account_fk')
                ->references(['account_id', 'id'])->on('office_erp_receipts')->restrictOnDelete();
            $table->foreign(['account_id', 'operation_id'], 'office_erp_reversals_operation_account_fk')
                ->references(['account_id', 'id'])->on('office_erp_commercial_operations')->restrictOnDelete();
            $table->unique('receipt_id', 'office_erp_receipt_reversals_receipt_unique');
        });

        Schema::create('office_erp_commercial_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('delinquency_policy', 16)->default('manual');
            $table->unsignedSmallInteger('grace_days')->default(0);
            $table->string('billing_timezone', 64)->default('America/Sao_Paulo');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('office_erp_commercial_settings');
        Schema::dropIfExists('office_erp_receipt_reversals');
        Schema::dropIfExists('office_erp_receipt_allocations');
        Schema::dropIfExists('office_erp_receipts');
        Schema::dropIfExists('office_erp_invoice_adjustments');
        DB::statement('DROP INDEX IF EXISTS office_erp_invoices_recurring_period_unique');
        Schema::dropIfExists('office_erp_invoices');
        Schema::dropIfExists('office_erp_invoice_counters');
    }
};
