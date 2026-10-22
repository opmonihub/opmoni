<?php

namespace App\Models\Erp;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class ErpCatalogAttribution extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'erp_company_id', 'actor_user_id', 'access_kind', 'erp_company_membership_id',
        'account_id', 'erp_office_delegation_id', 'action', 'resource_type',
        'resource_id', 'batch_id', 'recorded_at',
    ];

    protected function casts(): array
    {
        return ['recorded_at' => 'immutable_datetime'];
    }

    protected function performUpdate(Builder $query): never
    {
        throw new LogicException('Catalog attributions are append-only.');
    }

    protected function performDeleteOnModel(): never
    {
        throw new LogicException('Catalog attributions are append-only.');
    }

    protected function incrementOrDecrement(mixed $column, mixed $amount, mixed $extra, mixed $method): never
    {
        throw new LogicException('Catalog attributions are append-only.');
    }

    protected function incrementOrDecrementEach(array $columns, array $extra, string $method): never
    {
        throw new LogicException('Catalog attributions are append-only.');
    }
}
