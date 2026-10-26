<?php

namespace App\Erp;

use App\Enums\Erp\ErpSubscriptionStatus;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpSubscription;
use App\Models\User;
use App\Support\AccessTransaction;
use Illuminate\Support\Facades\DB;

class ErpCompanyProvisioning
{
    public function __construct(
        private ErpCommercialCatalog $catalog,
        private ErpCompanyInitializer $initializer,
    ) {}

    /**
     * @param  array{name: string, cnpj: string, establishment_name?: string|null}  $companyData
     */
    public function createCompanyForOwner(User $owner, array $companyData): ErpCompany
    {
        return DB::transaction(function () use ($owner, $companyData): ErpCompany {
            AccessTransaction::boundLockWaits();
            $company = $this->createUnclaimedCompany($companyData);
            $company->memberships()->create([
                'user_id' => $owner->id,
                'role' => 'owner',
            ]);
            $company->forceFill(['owner_setup_status' => 'claimed'])->save();

            return $company->refresh()->load(['subscription.plan.modules']);
        });
    }

    /**
     * Provision an operating Company without inventing or granting a human identity.
     *
     * @param  array{name: string, cnpj: string, establishment_name?: string|null}  $companyData
     */
    public function createUnclaimedCompany(array $companyData): ErpCompany
    {
        return DB::transaction(function () use ($companyData): ErpCompany {
            AccessTransaction::boundLockWaits();
            $plan = $this->catalog->ensureBasicPlan();
            $company = ErpCompany::query()->create([
                'name' => $companyData['name'],
                'cnpj' => $companyData['cnpj'],
                'status' => 'active',
                'owner_setup_status' => 'unclaimed',
            ]);
            $company->establishments()->create([
                'name' => $companyData['establishment_name'] ?? 'Matriz',
                'status' => 'active',
            ]);
            ErpSubscription::query()->create([
                'erp_company_id' => $company->id,
                'erp_plan_id' => $plan->id,
                'status' => ErpSubscriptionStatus::Pending->value,
            ]);

            $this->initializer->initialize($company);

            return $company->refresh()->load(['subscription.plan.modules']);
        });
    }
}
