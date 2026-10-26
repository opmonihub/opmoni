<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_subscriptions', function (Blueprint $table): void {
            $table->unique(['id', 'erp_company_id'], 'erp_subscriptions_company_pair_unique');
        });

        Schema::create('office_erp_trial_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->unique()->constrained()->cascadeOnDelete();
            $table->uuid('public_code')->unique();
            $table->foreignId('erp_plan_id')->constrained('erp_plans')->restrictOnDelete();
            $table->boolean('enabled')->default(false);
            $table->unsignedBigInteger('revision')->default(1);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('erp_subscription_trials', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('erp_subscription_id');
            $table->unsignedBigInteger('erp_company_id');
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->string('cnpj', 14)->unique('erp_subscription_trials_cnpj_unique');
            $table->timestamp('started_at');
            $table->timestamp('ends_at');
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->unsignedBigInteger('link_revision');
            $table->timestamps();

            $table->foreign(['erp_subscription_id', 'erp_company_id'], 'erp_subscription_trials_subscription_company_fk')
                ->references(['id', 'erp_company_id'])->on('erp_subscriptions')->restrictOnDelete();
            $table->foreign('erp_company_id', 'erp_subscription_trials_company_fk')
                ->references('id')->on('erp_companies')->restrictOnDelete();
            $table->unique('erp_subscription_id', 'erp_subscription_trials_subscription_unique');
            $table->unique('erp_company_id', 'erp_subscription_trials_company_unique');
            $table->index(['account_id', 'ends_at'], 'erp_subscription_trials_account_expiry_index');
        });

        $this->trialConstraints(true);
    }

    public function down(): void
    {
        $this->trialConstraints(false);
        Schema::dropIfExists('erp_subscription_trials');
        Schema::dropIfExists('office_erp_trial_links');
        Schema::table('erp_subscriptions', function (Blueprint $table): void {
            $table->dropUnique('erp_subscriptions_company_pair_unique');
        });
    }

    private function trialConstraints(bool $create): void
    {
        $driver = DB::getDriverName();
        $condition = 'ends_at > started_at AND link_revision > 0';
        $immutable = [
            'id', 'erp_subscription_id', 'erp_company_id', 'account_id', 'cnpj', 'started_at', 'ends_at',
            'link_revision', 'created_at',
        ];

        if ($driver === 'pgsql') {
            if ($create) {
                DB::statement("ALTER TABLE erp_subscription_trials ADD CONSTRAINT erp_subscription_trials_period_valid CHECK ({$condition})");
                $comparisons = array_map(static fn (string $column): string => "NEW.{$column} IS NOT DISTINCT FROM OLD.{$column}", $immutable);
                DB::unprepared('CREATE FUNCTION erp_subscription_trials_immutable() RETURNS trigger AS $$ BEGIN IF NOT ('.implode(' AND ', $comparisons).') OR (OLD.converted_at IS NOT NULL AND NEW.converted_at IS DISTINCT FROM OLD.converted_at) OR (OLD.expired_at IS NOT NULL AND NEW.expired_at IS DISTINCT FROM OLD.expired_at) THEN RAISE EXCEPTION \'erp_subscription_trials_immutable\'; END IF; RETURN NEW; END; $$ LANGUAGE plpgsql');
                DB::unprepared('CREATE TRIGGER erp_subscription_trials_immutable_update BEFORE UPDATE ON erp_subscription_trials FOR EACH ROW EXECUTE FUNCTION erp_subscription_trials_immutable()');
                DB::unprepared("CREATE FUNCTION erp_subscription_trials_retain() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'erp_subscription_trials_retain'; END; $$ LANGUAGE plpgsql");
                DB::unprepared('CREATE TRIGGER erp_subscription_trials_retain_delete BEFORE DELETE ON erp_subscription_trials FOR EACH ROW EXECUTE FUNCTION erp_subscription_trials_retain()');
            } else {
                DB::unprepared('DROP TRIGGER IF EXISTS erp_subscription_trials_retain_delete ON erp_subscription_trials');
                DB::unprepared('DROP FUNCTION IF EXISTS erp_subscription_trials_retain()');
                DB::unprepared('DROP TRIGGER IF EXISTS erp_subscription_trials_immutable_update ON erp_subscription_trials');
                DB::unprepared('DROP FUNCTION IF EXISTS erp_subscription_trials_immutable()');
                DB::statement('ALTER TABLE erp_subscription_trials DROP CONSTRAINT erp_subscription_trials_period_valid');
            }

            return;
        }

        if ($driver === 'sqlite') {
            $period = 'NEW.ends_at > NEW.started_at AND NEW.link_revision > 0';
            foreach (['INSERT', 'UPDATE'] as $event) {
                $name = 'erp_subscription_trials_period_valid_'.strtolower($event);
                DB::unprepared($create
                    ? "CREATE TRIGGER {$name} BEFORE {$event} ON erp_subscription_trials WHEN NOT ({$period}) BEGIN SELECT RAISE(ABORT, 'erp_subscription_trials_period_valid'); END"
                    : "DROP TRIGGER IF EXISTS {$name}");
            }
            $comparisons = array_map(static fn (string $column): string => "NEW.{$column} IS OLD.{$column}", $immutable);
            $markers = '(OLD.converted_at IS NULL OR NEW.converted_at IS OLD.converted_at) AND (OLD.expired_at IS NULL OR NEW.expired_at IS OLD.expired_at)';
            DB::unprepared($create
                ? 'CREATE TRIGGER erp_subscription_trials_immutable_update BEFORE UPDATE ON erp_subscription_trials WHEN NOT ('.implode(' AND ', [...$comparisons, $markers]).") BEGIN SELECT RAISE(ABORT, 'erp_subscription_trials_immutable'); END"
                : 'DROP TRIGGER IF EXISTS erp_subscription_trials_immutable_update');
            DB::unprepared($create
                ? "CREATE TRIGGER erp_subscription_trials_retain_delete BEFORE DELETE ON erp_subscription_trials BEGIN SELECT RAISE(ABORT, 'erp_subscription_trials_retain'); END"
                : 'DROP TRIGGER IF EXISTS erp_subscription_trials_retain_delete');
        }
    }
};
