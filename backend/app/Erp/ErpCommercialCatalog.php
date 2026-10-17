<?php

namespace App\Erp;

use App\Models\Erp\ErpPlan;
use App\Models\Erp\ErpPlanModule;
use Illuminate\Support\Facades\DB;

class ErpCommercialCatalog
{
    public function advisory(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['erp-commercial-catalog']);
        }
    }

    public function ensureBasicPlan(): ErpPlan
    {
        $slug = (string) config('erp.basic_plan_slug', 'basic');

        return DB::transaction(function () use ($slug): ErpPlan {
            $this->advisory();
            $plan = ErpPlan::query()->where('slug', $slug)->first();
            if ($plan === null) {
                $plan = ErpPlan::query()->create([
                    'slug' => $slug,
                    'name' => 'Básico ERP',
                    'quotas' => null,
                ]);
                foreach (ErpCoreModules::BASIC as $moduleKey) {
                    ErpPlanModule::query()->create([
                        'erp_plan_id' => $plan->id,
                        'module_key' => $moduleKey,
                        'quota' => null,
                    ]);
                }
            }

            return $plan->load('modules');
        });
    }
}
