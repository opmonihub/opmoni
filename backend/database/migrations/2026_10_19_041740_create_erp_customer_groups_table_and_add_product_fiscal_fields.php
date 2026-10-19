<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_customer_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_company_id')->constrained('erp_companies')->cascadeOnDelete();
            $table->string('name');
            $table->decimal('percentage_adjustment', 10, 4)->default('0');
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->index(['erp_company_id', 'status']);
            $table->unique(['erp_company_id', 'id'], 'erp_customer_groups_company_id_id_unique');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX erp_customer_groups_name_active ON erp_customer_groups (erp_company_id, lower(name)) WHERE status = 'active'");
        }

        Schema::table('erp_contacts', function (Blueprint $table): void {
            $table->unsignedBigInteger('erp_customer_group_id')->nullable();
            $table->index(['erp_company_id', 'erp_customer_group_id'], 'erp_contacts_customer_group_company_idx');
            $table->foreign(['erp_company_id', 'erp_customer_group_id'], 'erp_contacts_customer_group_company_fk')
                ->references(['erp_company_id', 'id'])
                ->on('erp_customer_groups')
                ->restrictOnDelete();
        });

        Schema::table('erp_products', function (Blueprint $table): void {
            $table->string('ncm', 8)->nullable();
            $table->string('cest', 7)->nullable();
            $table->string('origin_code', 1)->nullable();
            $table->string('icms_cst', 2)->nullable();
            $table->string('icms_csosn', 3)->nullable();
            $table->string('pis_cst', 2)->nullable();
            $table->string('cofins_cst', 2)->nullable();
            $table->string('ipi_cst', 2)->nullable();
            $table->decimal('icms_rate', 7, 4)->nullable();
            $table->decimal('pis_rate', 7, 4)->nullable();
            $table->decimal('cofins_rate', 7, 4)->nullable();
            $table->decimal('ipi_rate', 7, 4)->nullable();
            $table->decimal('simple_national_credit_rate', 7, 4)->nullable();
            $table->string('ibs_cbs_cst', 3)->nullable();
            $table->string('ibs_cbs_classification_code', 6)->nullable();
        });
    }

    public function down(): void
    {
        $hasCustomerGroups = DB::table('erp_customer_groups')->exists();
        $hasAssignedContacts = DB::table('erp_contacts')->whereNotNull('erp_customer_group_id')->exists();
        $hasFiscalProductData = DB::table('erp_products')
            ->where(function (Builder $query): void {
                foreach ([
                    'ncm',
                    'cest',
                    'origin_code',
                    'icms_cst',
                    'icms_csosn',
                    'pis_cst',
                    'cofins_cst',
                    'ipi_cst',
                    'icms_rate',
                    'pis_rate',
                    'cofins_rate',
                    'ipi_rate',
                    'simple_national_credit_rate',
                    'ibs_cbs_cst',
                    'ibs_cbs_classification_code',
                ] as $column) {
                    $query->orWhereNotNull($column);
                }
            })
            ->exists();

        if ($hasCustomerGroups || $hasAssignedContacts || $hasFiscalProductData) {
            throw new RuntimeException('Cannot roll back typed ERP catalog data. Restore a compatible application version instead.');
        }

        Schema::table('erp_products', function (Blueprint $table): void {
            $table->dropColumn([
                'ncm',
                'cest',
                'origin_code',
                'icms_cst',
                'icms_csosn',
                'pis_cst',
                'cofins_cst',
                'ipi_cst',
                'icms_rate',
                'pis_rate',
                'cofins_rate',
                'ipi_rate',
                'simple_national_credit_rate',
                'ibs_cbs_cst',
                'ibs_cbs_classification_code',
            ]);
        });

        Schema::table('erp_contacts', function (Blueprint $table): void {
            $table->dropForeign('erp_contacts_customer_group_company_fk');
            $table->dropIndex('erp_contacts_customer_group_company_idx');
            $table->dropColumn('erp_customer_group_id');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS erp_customer_groups_name_active');
        }

        Schema::dropIfExists('erp_customer_groups');
    }
};
