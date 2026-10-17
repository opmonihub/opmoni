<?php

namespace App\Http\Resources\Erp;

use App\Models\Erp\ErpSubscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ErpSubscription */
class ErpSubscriptionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $this->loadMissing(['plan.modules', 'company']);

        return [
            'id' => $this->id,
            'status' => $this->status,
            'company' => [
                'id' => $this->company->id,
                'name' => $this->company->name,
                'status' => $this->company->status,
            ],
            'plan' => [
                'id' => $this->plan->id,
                'slug' => $this->plan->slug,
                'name' => $this->plan->name,
                'modules' => $this->plan->modules->map(fn ($module): array => [
                    'module_key' => $module->module_key,
                    'quota' => $module->quota,
                ])->values()->all(),
            ],
        ];
    }
}
