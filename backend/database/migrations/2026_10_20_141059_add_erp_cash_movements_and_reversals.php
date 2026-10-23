<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_cash_sessions', function (Blueprint $table): void {
            $table->string('currency', 3)->nullable();
            $table->unique(['id', 'erp_company_id', 'erp_establishment_id', 'currency', 'erp_cash_register_id'], 'erp_cash_sessions_ledger_identity');
        });
        Schema::table('erp_cash_registers', function (Blueprint $table): void {
            $table->unique(['id', 'erp_company_id', 'erp_establishment_id'], 'erp_cash_registers_ledger_identity');
        });
        Schema::table('erp_payments', function (Blueprint $table): void {
            $table->string('direction', 3)->nullable();
            $table->foreignId('erp_cash_session_id')->nullable()->constrained('erp_cash_sessions')->restrictOnDelete();
            $table->string('reason', 255)->nullable();
            $table->unsignedSmallInteger('ledger_version')->nullable();
            $table->unique(['id', 'erp_company_id'], 'erp_payments_ledger_identity');
        });
        Schema::table('erp_payment_allocations', function (Blueprint $table): void {
            $table->string('reason', 255)->nullable();
            $table->index('reversal_of_id', 'erp_allocations_reversal_index');
        });
        Schema::table('erp_obligations', function (Blueprint $table): void {
            $table->foreignId('return_of_id')->nullable()->constrained('erp_obligations')->restrictOnDelete();
            $table->index(['erp_company_id', 'return_of_id'], 'erp_obligations_return_index');
        });
        Schema::create('erp_cash_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->restrictOnDelete();
            $table->unsignedBigInteger('erp_establishment_id');
            $table->unsignedBigInteger('erp_cash_register_id');
            $table->unsignedBigInteger('erp_cash_session_id');
            $table->string('currency', 3);
            $table->string('direction', 3);
            $table->string('kind', 16);
            $table->decimal('amount', 22, 4);
            $table->string('reason', 255);
            $table->unsignedBigInteger('erp_payment_id')->nullable()->unique();
            $table->unsignedBigInteger('reversal_of_id')->nullable()->unique();
            $table->string('source_operation_key', 128);
            $table->foreignId('posted_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at');
            $table->timestamps();
            $table->foreign(['erp_cash_session_id', 'erp_company_id', 'erp_establishment_id', 'currency', 'erp_cash_register_id'], 'erp_cash_movements_session_scope')->references(['id', 'erp_company_id', 'erp_establishment_id', 'currency', 'erp_cash_register_id'])->on('erp_cash_sessions')->restrictOnDelete();
            $table->foreign(['erp_cash_register_id', 'erp_company_id', 'erp_establishment_id'], 'erp_cash_movements_register_scope')->references(['id', 'erp_company_id', 'erp_establishment_id'])->on('erp_cash_registers')->restrictOnDelete();
            $table->foreign(['erp_payment_id', 'erp_company_id'], 'erp_cash_movements_payment_scope')->references(['id', 'erp_company_id'])->on('erp_payments')->restrictOnDelete();
            $table->unique(['id', 'erp_company_id', 'currency'], 'erp_cash_movements_reversal_identity');
            $table->foreign(['reversal_of_id', 'erp_company_id', 'currency'], 'erp_cash_movements_reversal_scope')->references(['id', 'erp_company_id', 'currency'])->on('erp_cash_movements')->restrictOnDelete();
            $table->unique(['erp_company_id', 'source_operation_key'], 'erp_cash_movements_operation_unique');
            $table->index(['erp_company_id', 'erp_cash_session_id', 'posted_at'], 'erp_cash_movements_session_index');
        });
        DB::statement('CREATE UNIQUE INDEX erp_payments_one_ledger_reversal ON erp_payments (reversal_of_id) WHERE reversal_of_id IS NOT NULL AND ledger_version = 1');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE erp_cash_movements ADD CONSTRAINT erp_cash_movements_values CHECK (amount > 0 AND direction IN ('in', 'out') AND kind IN ('deposit', 'withdrawal', 'payment', 'reversal'))");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Financial ledger history is retained; deploy a compatible application instead.');
    }
};
