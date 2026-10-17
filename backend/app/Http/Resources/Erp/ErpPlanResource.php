<?php

namespace App\Http\Resources\Erp;

use App\Models\Erp\ErpPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ErpPlan */
class ErpPlanResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $this->loadMissing('modules');

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'quotas' => $this->quotas,
            'modules' => $this->modules->map(fn ($module): array => [
                'module_key' => $module->module_key,
                'quota' => $module->quota,
            ])->values()->all(),
        ];
    }
}
