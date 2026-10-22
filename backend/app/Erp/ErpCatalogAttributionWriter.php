<?php

namespace App\Erp;

use App\Models\Erp\ErpBrand;
use App\Models\Erp\ErpCarrier;
use App\Models\Erp\ErpCatalogAttribution;
use App\Models\Erp\ErpCatalogImport;
use App\Models\Erp\ErpCategory;
use App\Models\Erp\ErpContact;
use App\Models\Erp\ErpCustomerGroup;
use App\Models\Erp\ErpDiscountRule;
use App\Models\Erp\ErpDriver;
use App\Models\Erp\ErpOperationNature;
use App\Models\Erp\ErpPriceGroup;
use App\Models\Erp\ErpProduct;
use App\Models\Erp\ErpProductVariant;
use App\Models\Erp\ErpTaxRate;
use App\Models\Erp\ErpUnit;
use App\Models\Erp\ErpVehicle;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;

class ErpCatalogAttributionWriter
{
    /** @var array<class-string<Model>, string> */
    private const RESOURCE_TYPES = [
        ErpContact::class => 'contact',
        ErpProduct::class => 'product',
        ErpProductVariant::class => 'product_variant',
        ErpUnit::class => 'unit',
        ErpCategory::class => 'category',
        ErpBrand::class => 'brand',
        ErpPriceGroup::class => 'price_group',
        ErpCustomerGroup::class => 'customer_group',
        ErpDiscountRule::class => 'discount_rule',
        ErpTaxRate::class => 'tax_rate',
        ErpOperationNature::class => 'operation_nature',
        ErpCarrier::class => 'carrier',
        ErpVehicle::class => 'vehicle',
        ErpDriver::class => 'driver',
    ];

    public function record(Request $request, CurrentCompany $context, Model $resource, string $action, ?ErpCatalogImport $batch = null): void
    {
        $boundary = $request->attributes->get(ErpCatalogWriteService::class);
        if (! is_array($boundary)
            || ($boundary['context'] ?? null) !== $context
            || CurrentCompany::fromRequest($request) !== $context
            || ($boundary['connection'] ?? null) !== DB::connection()
            || ($boundary['level'] ?? 0) < 1
            || $boundary['level'] > DB::transactionLevel()
            || $resource->getConnection() !== DB::connection()) {
            throw new LogicException('Catalog attribution requires the protected catalog transaction and context.');
        }
        $context->assertCanMutate();
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->exists || ! User::query()->whereKey($actor->id)->exists()) {
            throw new LogicException('Catalog attribution requires a persisted authenticated actor.');
        }

        $type = self::RESOURCE_TYPES[$resource::class] ?? null;
        if ($type === null || ! $resource->exists || $resource->getKey() === null
            || ! in_array($action, ['created', 'updated', 'archived', 'import_created'], true)) {
            throw new LogicException('Unsupported catalog resource or action.');
        }
        $owned = $resource->newQuery()->whereKey($resource->getKey());
        if ($resource instanceof ErpProductVariant) {
            $owned->whereHas('product', fn ($query) => $query->where('erp_company_id', $context->company->id));
        } else {
            $owned->where('erp_company_id', $context->company->id);
        }
        if (! $owned->exists()) {
            throw new LogicException('Catalog resource does not belong to the current Company.');
        }

        $membershipId = $officeId = $delegationId = null;
        if ($context->accessKind === 'direct' && $context->membership?->exists
            && (int) $context->membership->erp_company_id === (int) $context->company->id
            && (int) $context->membership->user_id === (int) $actor->id) {
            $membershipId = $context->membership->id;
        } elseif ($context->accessKind === 'delegated' && $context->delegation?->exists
            && $context->delegation->isActive()
            && (int) $context->delegation->erp_company_id === (int) $context->company->id
            && $context->delegatedOfficeId !== null
            && (int) $context->delegation->account_id === $context->delegatedOfficeId) {
            $officeId = $context->delegatedOfficeId;
            $delegationId = $context->delegation->id;
        } else {
            throw new LogicException('Catalog attribution requires actual Company authority.');
        }

        if (($action === 'import_created') !== ($batch !== null)) {
            throw new LogicException('Imported resources require their confirmation batch.');
        }
        if ($batch !== null) {
            $kind = match ($resource::class) {
                ErpProduct::class, ErpProductVariant::class => 'products',
                ErpContact::class => 'contacts',
                default => null,
            };
            if ($kind === null || ! $batch->exists || ! ErpCatalogImport::query()
                ->whereKey($batch->getKey())
                ->where('erp_company_id', $context->company->id)
                ->where('actor_user_id', $actor->id)
                ->where('kind', $kind)
                ->whereNull('completed_at')
                ->exists()) {
                throw new LogicException('Import batch does not bind the current Company, actor and resource.');
            }
        }

        if (in_array($action, ['created', 'import_created'], true)) {
            if (! $resource->wasRecentlyCreated) {
                throw new LogicException('Creation attribution requires a newly persisted resource.');
            }
        } elseif (array_diff(array_keys($resource->getChanges()), ['created_at', 'updated_at']) === []) {
            return;
        } elseif ($action === 'archived' && (! $resource->wasChanged('status') || $resource->status !== 'archived')) {
            throw new LogicException('Archive attribution requires an actual archive transition.');
        }

        $event = ErpCatalogAttribution::query()->create([
            'erp_company_id' => $context->company->id,
            'actor_user_id' => $actor->id,
            'access_kind' => $context->accessKind,
            'erp_company_membership_id' => $membershipId,
            'account_id' => $officeId,
            'erp_office_delegation_id' => $delegationId,
            'action' => $action,
            'resource_type' => $type,
            'resource_id' => $resource->getKey(),
            'batch_id' => $batch?->getKey(),
            'recorded_at' => now(),
        ]);
        if (! $event->exists) {
            throw new LogicException('Catalog attribution was not persisted.');
        }
    }
}
