<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->string('contact_person')->nullable();
            $table->string('whatsapp', 30)->nullable();
        });
        Schema::table('office_erp_contracts', function (Blueprint $table): void {
            $table->date('planned_start_on')->nullable();
        });
        Schema::table('office_erp_contract_revisions', function (Blueprint $table): void {
            $table->timestamp('superseded_at')->nullable();
        });
        Schema::create('office_erp_company_registrations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('client_id');
            $table->foreignId('erp_company_id')->constrained('erp_companies')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('registered_at');
            $table->timestamps();
            $table->foreign(['account_id', 'client_id'], 'office_erp_registration_client_fk')
                ->references(['account_id', 'id'])->on('clients')->restrictOnDelete();
            $table->unique(['account_id', 'client_id'], 'office_erp_registration_client_unique');
            $table->unique(['account_id', 'erp_company_id'], 'office_erp_registration_company_unique');
            $table->unique(['account_id', 'erp_company_id', 'id'], 'office_erp_registration_scope_unique');
            $table->index(['account_id', 'registered_at', 'erp_company_id'], 'office_erp_registration_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('office_erp_company_registrations');
        Schema::table('office_erp_contract_revisions', fn (Blueprint $table) => $table->dropColumn('superseded_at'));
        Schema::table('office_erp_contracts', fn (Blueprint $table) => $table->dropColumn('planned_start_on'));
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn(['contact_person', 'whatsapp']));
    }
};
