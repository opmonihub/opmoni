<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_access_logs', function (Blueprint $table): void {
            $table->dropForeign(['account_id']);
        });
        Schema::table('support_access_logs', function (Blueprint $table): void {
            $table->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('support_access_logs', function (Blueprint $table): void {
            $table->dropForeign(['account_id']);
        });
        Schema::table('support_access_logs', function (Blueprint $table): void {
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
        });
    }
};
