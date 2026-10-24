<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_product_tax_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->restrictOnDelete();
            $table->string('name');
            $table->string('status', 16)->default('active');
            $table->unsignedBigInteger('current_revision')->nullable();
            $table->timestamps();
            $table->unique(['erp_company_id', 'id'], 'erp_tax_profile_company_identity');
            $table->index(['erp_company_id', 'status']);
        });
        Schema::create('erp_product_tax_profile_revisions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('erp_company_id');
            $table->unsignedBigInteger('erp_product_tax_profile_id');
            $table->unsignedBigInteger('revision');
            $table->string('name');
            $table->string('status', 16);
            $table->json('fields');
            $table->json('applicability');
            $table->unsignedBigInteger('actor_user_id');
            $table->string('access_kind', 16);
            $table->unsignedBigInteger('erp_company_membership_id')->nullable();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->unsignedBigInteger('erp_office_delegation_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['erp_company_id', 'erp_product_tax_profile_id', 'revision'], 'erp_tax_revision_company_identity');
            $table->foreign(['erp_company_id', 'erp_product_tax_profile_id'], 'erp_tax_revision_profile_company_fk')->references(['erp_company_id', 'id'])->on('erp_product_tax_profiles')->restrictOnDelete();
        });
        Schema::table('erp_product_tax_profiles', function (Blueprint $table): void {
            $table->foreign(['erp_company_id', 'id', 'current_revision'], 'erp_tax_profile_current_revision_fk')
                ->references(['erp_company_id', 'erp_product_tax_profile_id', 'revision'])->on('erp_product_tax_profile_revisions')->restrictOnDelete();
        });
        Schema::create('erp_company_product_tax_defaults', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->unique()->constrained('erp_companies')->restrictOnDelete();
            $table->unsignedBigInteger('erp_product_tax_profile_id')->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestamps();
            $table->foreign(['erp_company_id', 'erp_product_tax_profile_id'], 'erp_tax_default_profile_company_fk')->references(['erp_company_id', 'id'])->on('erp_product_tax_profiles')->restrictOnDelete();
        });
        Schema::table('erp_products', function (Blueprint $table): void {
            $table->unsignedBigInteger('erp_product_tax_profile_id')->nullable();
            $table->json('tax_field_intents')->nullable();
            $table->foreign(['erp_company_id', 'erp_product_tax_profile_id'], 'erp_product_tax_profile_company_fk')->references(['erp_company_id', 'id'])->on('erp_product_tax_profiles')->restrictOnDelete();
        });
        if (DB::getDriverName() === 'sqlite') {
            foreach (['UPDATE', 'DELETE'] as $event) {
                $name = 'erp_tax_revision_immutable_'.strtolower($event);
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON erp_product_tax_profile_revisions BEGIN SELECT RAISE(ABORT, 'Product tax revisions are immutable'); END");
            }
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE FUNCTION erp_tax_revision_immutable() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Product tax revisions are immutable'; END; $$");
            DB::unprepared('CREATE TRIGGER erp_tax_revision_immutable BEFORE UPDATE OR DELETE ON erp_product_tax_profile_revisions FOR EACH ROW EXECUTE FUNCTION erp_tax_revision_immutable()');
        }
    }

    public function down(): void
    {
        if (DB::table('erp_product_tax_profiles')->exists()
            || DB::table('erp_company_product_tax_defaults')->exists()
            || DB::table('erp_products')->whereNotNull('tax_field_intents')->exists()) {
            throw new RuntimeException('Cannot remove accepted product tax configuration or revision history.');
        }
        Schema::table('erp_products', function (Blueprint $table): void {
            $table->dropForeign(DB::getDriverName() === 'sqlite' ? ['erp_company_id', 'erp_product_tax_profile_id'] : 'erp_product_tax_profile_company_fk');
            $table->dropColumn(['erp_product_tax_profile_id', 'tax_field_intents']);
        });
        Schema::dropIfExists('erp_company_product_tax_defaults');
        Schema::table('erp_product_tax_profiles', fn (Blueprint $table) => $table->dropForeign(DB::getDriverName() === 'sqlite' ? ['erp_company_id', 'id', 'current_revision'] : 'erp_tax_profile_current_revision_fk'));
        Schema::dropIfExists('erp_product_tax_profile_revisions');
        Schema::dropIfExists('erp_product_tax_profiles');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS erp_tax_revision_immutable()');
        }
    }
};
