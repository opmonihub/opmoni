<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_companies', function (Blueprint $table): void {
            $table->string('owner_setup_status', 16)->default('claimed');
        });

        Schema::table('office_erp_contracts', function (Blueprint $table): void {
            $table->string('provisioning_source', 24)->default('legacy_invitation');
            $table->foreignId('erp_plan_id')->nullable()->constrained('erp_plans')->restrictOnDelete();
            $table->foreignId('authorized_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('authorized_at')->nullable();
        });

        Schema::table('office_erp_owner_invitations', function (Blueprint $table): void {
            $table->string('purpose', 32)->default('commercial_acceptance');
        });

        $this->subscriptionStatusConstraint(true);
    }

    public function down(): void
    {
        $this->subscriptionStatusConstraint(false);

        Schema::table('office_erp_owner_invitations', function (Blueprint $table): void {
            $table->dropColumn('purpose');
        });

        Schema::table('office_erp_contracts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('authorized_by_user_id');
            $table->dropConstrainedForeignId('erp_plan_id');
            $table->dropColumn(['provisioning_source', 'authorized_at']);
        });

        Schema::table('erp_companies', function (Blueprint $table): void {
            $table->dropColumn('owner_setup_status');
        });
    }

    private function subscriptionStatusConstraint(bool $create): void
    {
        $name = 'erp_subscriptions_status_valid';
        $condition = "status IN ('pending', 'active', 'past_due', 'suspended', 'canceled', 'trialing', 'trial_expired')";

        if (DB::getDriverName() === 'pgsql') {
            DB::statement($create
                ? "ALTER TABLE erp_subscriptions ADD CONSTRAINT {$name} CHECK ({$condition})"
                : "ALTER TABLE erp_subscriptions DROP CONSTRAINT {$name}");

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            $expression = str_replace('status', 'NEW.status', $condition);
            foreach (['INSERT', 'UPDATE'] as $event) {
                $trigger = $name.'_'.strtolower($event);
                DB::unprepared($create
                    ? "CREATE TRIGGER {$trigger} BEFORE {$event} ON erp_subscriptions WHEN NOT ({$expression}) BEGIN SELECT RAISE(ABORT, '{$name}'); END"
                    : "DROP TRIGGER IF EXISTS {$trigger}");
            }
        }
    }
};
