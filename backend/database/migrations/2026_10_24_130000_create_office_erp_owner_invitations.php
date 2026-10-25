<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('office_erp_owner_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('contract_id');
            $table->string('owner_name', 255);
            $table->string('owner_email', 255);
            $table->json('seller_snapshot');
            $table->char('token_hash', 64)->unique('office_erp_owner_invitation_token_hash_unique');
            $table->string('status', 16)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['account_id', 'contract_id'], 'office_erp_owner_invitation_contract_fk')
                ->references(['account_id', 'id'])->on('office_erp_contracts')->restrictOnDelete();
            $table->unique(['account_id', 'id'], 'office_erp_owner_invitations_account_id_id_unique');
            $table->index(['account_id', 'status', 'expires_at'], 'office_erp_owner_invitation_status_expiry_index');
            $table->index(['account_id', 'contract_id', 'status', 'id'], 'office_erp_owner_invitation_contract_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('office_erp_owner_invitations');
    }
};
