<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->boolean('is_super_admin')->default(false));
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX users_normalized_email_unique ON users (lower(btrim(email)))');
            DB::statement('ALTER TABLE users ADD CONSTRAINT users_email_normalized CHECK (email = lower(btrim(email)))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS users_normalized_email_unique');
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_email_normalized');
        } Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_super_admin'));
    }
};
