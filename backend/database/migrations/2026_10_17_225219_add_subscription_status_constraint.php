<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $invalid = DB::table('subscriptions')->whereNotIn('status', ['active', 'past_due', 'canceled'])->orderBy('id')->limit(20)->pluck('id');
        if ($invalid->isNotEmpty()) {
            throw new RuntimeException('Invalid legacy subscription statuses; resolve before retrying migration. Subscription IDs: '.$invalid->implode(', '));
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_status_valid CHECK (status IN ('active', 'past_due', 'canceled'))");
        } elseif (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $event) {
                $trigger = 'subscriptions_status_valid_'.strtolower($event);
                DB::unprepared("CREATE TRIGGER {$trigger} BEFORE {$event} ON subscriptions WHEN NEW.status NOT IN ('active', 'past_due', 'canceled') BEGIN SELECT RAISE(ABORT, 'subscriptions_status_valid'); END");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE subscriptions DROP CONSTRAINT IF EXISTS subscriptions_status_valid');
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS subscriptions_status_valid_insert');
            DB::statement('DROP TRIGGER IF EXISTS subscriptions_status_valid_update');
        }
    }
};
