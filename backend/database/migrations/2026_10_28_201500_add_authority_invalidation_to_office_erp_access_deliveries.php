<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('office_erp_access_deliveries', function (Blueprint $table): void {
            $table->timestamp('authority_invalidated_at')->nullable();
            $table->index(['recipient_user_id', 'purpose', 'authority_invalidated_at'], 'office_erp_delivery_authority_index');
            $table->index(['expires_at', 'authority_invalidated_at'], 'office_erp_delivery_expiry_index');
        });
    }

    public function down(): void
    {
        Schema::table('office_erp_access_deliveries', function (Blueprint $table): void {
            $table->dropIndex('office_erp_delivery_authority_index');
            $table->dropIndex('office_erp_delivery_expiry_index');
            $table->dropColumn('authority_invalidated_at');
        });
    }
};
