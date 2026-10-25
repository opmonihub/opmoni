<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('office_erp_receipt_proofs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('receipt_id')->nullable();
            $table->string('original_name', 191);
            $table->string('mime_type', 32);
            $table->unsignedBigInteger('byte_length');
            $table->char('sha256', 64);
            $table->string('path', 512)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'id'], 'office_erp_receipt_proofs_account_id_id_unique');
            $table->foreign(['account_id', 'receipt_id'], 'office_erp_receipt_proofs_receipt_account_fk')
                ->references(['account_id', 'id'])->on('office_erp_receipts')->restrictOnDelete();
            $table->index(['account_id', 'receipt_id', 'expires_at'], 'office_erp_receipt_proofs_cleanup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('office_erp_receipt_proofs');
    }
};
