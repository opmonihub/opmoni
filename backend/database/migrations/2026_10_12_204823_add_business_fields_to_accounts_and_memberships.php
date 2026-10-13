<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->json('settings')->nullable();
        });

        Schema::table('account_memberships', function (Blueprint $table): void {
            $table->foreignId('inviter_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('account_memberships', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('inviter_id');
        });

        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn('settings');
        });
    }
};
