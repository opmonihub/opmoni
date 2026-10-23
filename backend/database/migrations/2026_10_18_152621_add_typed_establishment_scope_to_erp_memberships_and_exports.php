<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('erp_company_memberships', function (Blueprint $table): void {
            $table->boolean('access_all_establishments')->default(true);
            $table->unique(['id', 'erp_company_id'], 'erp_memberships_id_company_unique');
        });

        Schema::table('erp_establishments', function (Blueprint $table): void {
            $table->unique(['id', 'erp_company_id'], 'erp_establishments_id_company_unique');
        });

        Schema::create('erp_company_membership_establishments', function (Blueprint $table): void {
            $table->foreignId('erp_company_membership_id');
            $table->foreignId('erp_establishment_id');
            $table->foreignId('erp_company_id');
            $table->primary(['erp_company_membership_id', 'erp_establishment_id'], 'erp_membership_establishments_primary');
            $table->foreign(['erp_company_membership_id', 'erp_company_id'], 'erp_membership_establishments_membership_company_foreign')
                ->references(['id', 'erp_company_id'])->on('erp_company_memberships')->cascadeOnDelete();
            $table->foreign(['erp_establishment_id', 'erp_company_id'], 'erp_membership_establishments_establishment_company_foreign')
                ->references(['id', 'erp_company_id'])->on('erp_establishments')->cascadeOnDelete();
            $table->index('erp_company_id', 'erp_membership_establishments_company_index');
        });

        Schema::table('erp_report_exports', function (Blueprint $table): void {
            $table->string('authorized_establishment_scope', 16)->nullable();
            $table->json('authorized_establishment_ids')->nullable();
            $table->boolean('authorized_companywide_finance')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->assertNoScopedAuthority();

        Schema::table('erp_report_exports', function (Blueprint $table): void {
            $table->dropColumn(['authorized_establishment_scope', 'authorized_establishment_ids']);
            $table->dropColumn('authorized_companywide_finance');
        });

        Schema::dropIfExists('erp_company_membership_establishments');

        Schema::table('erp_establishments', function (Blueprint $table): void {
            $table->dropUnique('erp_establishments_id_company_unique');
        });

        Schema::table('erp_company_memberships', function (Blueprint $table): void {
            $table->dropUnique('erp_memberships_id_company_unique');
            $table->dropColumn('access_all_establishments');
        });
    }

    private function assertNoScopedAuthority(): void
    {
        $hasSelectedMembership = DB::table('erp_company_memberships')
            ->where('access_all_establishments', false)
            ->exists();
        $hasMembershipEstablishments = DB::table('erp_company_membership_establishments')->exists();

        if ($hasSelectedMembership || $hasMembershipEstablishments) {
            throw new RuntimeException('Cannot roll back establishment scope while selected membership scopes are stored.');
        }

        $hasScopedExportAuthority = DB::table('erp_report_exports')
            ->where(function ($query): void {
                $query->whereNotNull('authorized_establishment_ids')
                    ->orWhere('authorized_companywide_finance', false)
                    ->orWhere(function ($query): void {
                        $query->whereNotNull('authorized_establishment_scope')
                            ->where('authorized_establishment_scope', '!=', 'all');
                    })
                    ->orWhere(function ($query): void {
                        $query->where('authorized_establishment_scope', 'all')
                            ->whereNull('authorized_companywide_finance');
                    });
            })
            ->exists();

        if ($hasScopedExportAuthority) {
            throw new RuntimeException('Cannot roll back establishment scope while scoped report export authority is stored.');
        }
    }
};
