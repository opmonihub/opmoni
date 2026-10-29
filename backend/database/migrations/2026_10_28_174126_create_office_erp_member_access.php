<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('access_auth_revision')->default(0);
        });
        Schema::create('erp_company_member_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->restrictOnDelete();
            $table->unsignedBigInteger('registration_id');
            $table->enum('purpose', ['member_access'])->default('member_access');
            $table->string('name');
            $table->string('email');
            $table->enum('role', ['manager', 'operator', 'viewer']);
            $table->enum('permissions_mode', ['defaults', 'explicit'])->default('defaults');
            $table->json('permissions');
            $table->enum('establishment_scope', ['all', 'selected'])->default('all');
            $table->json('establishment_ids');
            $table->char('token_hash', 64)->unique('erp_member_invitation_hash_unique');
            $table->enum('status', ['pending', 'accepted', 'revoked', 'expired'])->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->foreign(['account_id', 'erp_company_id', 'registration_id'], 'erp_member_invitation_registration_fk')
                ->references(['account_id', 'erp_company_id', 'id'])->on('office_erp_company_registrations')->restrictOnDelete();
            $table->unique(['account_id', 'erp_company_id', 'id'], 'erp_member_invitation_scope_unique');
            $table->index(['account_id', 'erp_company_id', 'status', 'expires_at'], 'erp_member_invitation_status_index');
        });
        DB::statement("CREATE UNIQUE INDEX erp_member_invitation_pending_email_unique ON erp_company_member_invitations (erp_company_id, LOWER(TRIM(email))) WHERE status = 'pending'");
        Schema::create('office_erp_access_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->restrictOnDelete();
            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('operation_id');
            $table->enum('purpose', ['member_invitation', 'password_recovery']);
            $table->unsignedBigInteger('member_invitation_id')->nullable();
            $table->foreignId('membership_id')->nullable()->constrained('erp_company_memberships')->nullOnDelete();
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recipient_email');
            $table->unsignedBigInteger('access_auth_revision')->nullable();
            $table->enum('state', ['prepared', 'sending', 'transport_accepted', 'failed', 'unknown', 'expired'])->default('prepared');
            $table->text('payload')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->foreign(['account_id', 'erp_company_id', 'registration_id'], 'office_erp_delivery_registration_fk')
                ->references(['account_id', 'erp_company_id', 'id'])->on('office_erp_company_registrations')->restrictOnDelete();
            $table->foreign(['account_id', 'operation_id'], 'office_erp_delivery_operation_fk')
                ->references(['account_id', 'id'])->on('office_erp_commercial_operations')->restrictOnDelete();
            $table->foreign(['account_id', 'erp_company_id', 'member_invitation_id'], 'office_erp_delivery_invitation_fk')
                ->references(['account_id', 'erp_company_id', 'id'])->on('erp_company_member_invitations')->restrictOnDelete();
            $table->unique('operation_id', 'office_erp_delivery_operation_unique');
            $table->index(['state', 'expires_at'], 'office_erp_delivery_dispatch_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('office_erp_access_deliveries');
        Schema::dropIfExists('erp_company_member_invitations');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('access_auth_revision'));
    }
};
