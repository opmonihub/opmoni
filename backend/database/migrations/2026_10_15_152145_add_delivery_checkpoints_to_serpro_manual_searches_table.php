<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('serpro_manual_searches', function (Blueprint $table): void {
            $table->json('completed_units')->nullable();
            $table->string('current_unit')->nullable();
            $table->timestamp('attempted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('serpro_manual_searches', function (Blueprint $table): void {
            $table->dropColumn(['completed_units', 'current_unit', 'attempted_at']);
        });
    }
};
