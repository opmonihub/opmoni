<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_channels', function (Blueprint $table): void {
            // Null preserves the conservative timestamp fallback for legacy mirrors.
            $table->unsignedBigInteger('last_projected_effect_id')->nullable();
            $table->unsignedBigInteger('last_projected_credential_effect_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_channels', function (Blueprint $table): void {
            $table->dropColumn(['last_projected_effect_id', 'last_projected_credential_effect_id']);
        });
    }
};
