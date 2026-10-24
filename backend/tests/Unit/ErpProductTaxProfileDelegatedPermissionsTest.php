<?php

namespace Tests\Unit;

use App\Erp\ErpCompanyPermissions;
use App\Models\Erp\ErpOfficeDelegation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ErpProductTaxProfileDelegatedPermissionsTest extends TestCase
{
    private const ACTIONS = [
        'erp.product-tax-profiles.index' => false,
        'erp.product-tax-profiles.store' => true,
        'erp.product-tax-profiles.update' => true,
        'erp.product-tax-profiles.destroy' => true,
    ];

    /** @return iterable<string, array{?array}> */
    public static function historicalAllowlists(): iterable
    {
        yield 'null defaults to erp.read' => [null];
        yield 'empty defaults to erp.read' => [[]];
        yield 'global read' => [['erp.read']];
        yield 'global write' => [['erp.write']];
        yield 'global wildcard' => [['*']];
        yield 'family wildcard' => [['erp.product-tax-profiles.*']];
        yield 'family read' => [['erp.product-tax-profiles.read']];
        yield 'family write' => [['erp.product-tax-profiles.write']];
    }

    #[DataProvider('historicalAllowlists')]
    public function test_historical_broad_delegations_do_not_acquire_any_new_profile_action(?array $stored): void
    {
        $allowlist = (new ErpOfficeDelegation(['permission_allowlist' => $stored]))->effectivePermissions();
        if ($stored === null || $stored === []) {
            $this->assertSame(['erp.read'], $allowlist);
        }
        foreach (self::ACTIONS as $permission => $write) {
            foreach (['admin', 'operador', 'user'] as $role) {
                $this->assertFalse(ErpCompanyPermissions::delegatedAllows($permission, $write, $allowlist, $role), $permission.' must require its exact grant for '.$role);
                $this->assertNotContains($permission, ErpCompanyPermissions::delegatedEffective($allowlist, $role));
            }
        }
    }

    /** @return iterable<string, array{string, bool}> */
    public static function profileActions(): iterable
    {
        foreach (self::ACTIONS as $permission => $write) {
            yield $permission => [$permission, $write];
        }
    }

    #[DataProvider('profileActions')]
    public function test_exact_profile_grant_preserves_role_write_and_effective_permission_boundaries(string $permission, bool $write): void
    {
        $allowlist = ['*', 'erp.read', 'erp.write', 'erp.product-tax-profiles.*', $permission];
        foreach (['admin', 'operador'] as $role) {
            $this->assertTrue(ErpCompanyPermissions::delegatedAllows($permission, $write, $allowlist, $role));
            $this->assertSame([$permission], array_values(array_intersect(array_keys(self::ACTIONS), ErpCompanyPermissions::delegatedEffective($allowlist, $role))));
        }
        $this->assertSame(! $write, ErpCompanyPermissions::delegatedAllows($permission, $write, [$permission], 'user'));
        $this->assertFalse(ErpCompanyPermissions::delegatedAllows($permission, ! $write, [$permission], 'admin'));
        foreach ([null, 'owner', 'viewer', 'manager'] as $role) {
            $this->assertFalse(ErpCompanyPermissions::delegatedAllows($permission, $write, [$permission], $role));
        }
    }

    public function test_existing_catalog_families_keep_their_broad_delegation_contract(): void
    {
        foreach (['erp.products', 'erp.brands', 'erp.operation-natures'] as $family) {
            foreach (['*', 'erp.read', $family.'.*', $family.'.read'] as $grant) {
                $this->assertTrue(ErpCompanyPermissions::delegatedAllows($family.'.index', false, [$grant], 'admin'));
                $this->assertContains($family.'.index', ErpCompanyPermissions::delegatedEffective([$grant], 'admin'));
            }
            foreach (['*', 'erp.write', $family.'.*', $family.'.write'] as $grant) {
                $this->assertTrue(ErpCompanyPermissions::delegatedAllows($family.'.store', true, [$grant], 'operador'));
                $this->assertContains($family.'.store', ErpCompanyPermissions::delegatedEffective([$grant], 'operador'));
                $this->assertFalse(ErpCompanyPermissions::delegatedAllows($family.'.store', true, [$grant], 'user'));
            }
        }
    }
}
