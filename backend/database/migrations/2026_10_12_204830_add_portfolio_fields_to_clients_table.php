<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->string('person_type')->nullable()->after('account_id');
            $table->string('tax_id', 14)->nullable()->after('person_type');
            $table->string('trade_name')->nullable()->after('name');
            $table->string('status')->default('active')->after('trade_name');
            $table->string('tax_regime')->nullable()->after('status');
            $table->string('registration_status')->nullable();
            $table->date('registration_status_date')->nullable();
            $table->date('opened_at')->nullable();
            $table->string('company_size')->nullable();
            $table->string('legal_nature')->nullable();
            $table->string('primary_activity_code', 16)->nullable();
            $table->text('primary_activity_description')->nullable();
            $table->string('street_type', 40)->nullable();
            $table->string('street')->nullable();
            $table->string('address_number', 30)->nullable();
            $table->string('address_complement')->nullable();
            $table->string('district')->nullable();
            $table->string('postal_code', 8)->nullable();
            $table->string('city')->nullable();
            $table->string('state', 2)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamp('looked_up_at')->nullable();
            $table->softDeletes();
            $table->unique(['account_id', 'tax_id']);
            $table->index(['account_id', 'status']);
            $table->index(['account_id', 'tax_regime']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropUnique('clients_account_id_tax_id_unique');
            $table->dropIndex('clients_account_id_status_index');
            $table->dropIndex('clients_account_id_tax_regime_index');
            $table->dropColumn([
                'person_type',
                'tax_id',
                'trade_name',
                'status',
                'tax_regime',
                'registration_status',
                'registration_status_date',
                'opened_at',
                'company_size',
                'legal_nature',
                'primary_activity_code',
                'primary_activity_description',
                'street_type',
                'street',
                'address_number',
                'address_complement',
                'district',
                'postal_code',
                'city',
                'state',
                'email',
                'phone',
                'source_updated_at',
                'looked_up_at',
                'deleted_at',
            ]);
        });
    }
};
