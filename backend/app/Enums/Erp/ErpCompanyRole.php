<?php

namespace App\Enums\Erp;

enum ErpCompanyRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Operator = 'operator';
    case Viewer = 'viewer';

    /** @return list<string> */
    public function defaultPermissions(): array
    {
        return match ($this) {
            self::Owner, self::Manager => ['*'],
            self::Operator => ['erp.read'],
            self::Viewer => ['erp.read'],
        };
    }

    public function canManageMemberships(): bool
    {
        return $this === self::Owner;
    }

    public function canManageDelegation(): bool
    {
        return $this === self::Owner;
    }

    public function canMutate(): bool
    {
        return $this !== self::Viewer;
    }
}
