<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX owner_activations_current_account_unique ON owner_activations (account_id) WHERE consumed_at IS NULL AND revoked_at IS NULL');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE owner_activations ADD CONSTRAINT owner_activations_email_normalized CHECK (email = lower(btrim(email)))');
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS owner_activations_current_account_unique');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE owner_activations DROP CONSTRAINT IF EXISTS owner_activations_email_normalized');
        }
    }
};
