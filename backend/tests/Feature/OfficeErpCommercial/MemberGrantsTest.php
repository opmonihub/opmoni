<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Erp\Commercial\OfficeErpCompanyMembers;
use App\Erp\ErpCompanyMemberGrants;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpEstablishment;
use Illuminate\Validation\ValidationException;
use Tests\OfficeErpCommercialTestCase;

class MemberGrantsTest extends OfficeErpCommercialTestCase
{
    public function test_viewer_cannot_receive_a_write_permission(): void
    {
        $this->expectException(ValidationException::class);

        app(ErpCompanyMemberGrants::class)->normalize('viewer', ['erp.contacts.store'], 'all', []);
    }

    public function test_explicit_wildcard_is_not_a_catalog_permission(): void
    {
        $this->expectException(ValidationException::class);

        app(ErpCompanyMemberGrants::class)->normalize('manager', ['*'], 'all', []);
    }

    public function test_manager_can_receive_supported_permissions_and_selected_scope(): void
    {
        $grants = app(ErpCompanyMemberGrants::class)->normalize('manager', ['erp.read'], 'selected', [7, 3]);

        self::assertSame(['erp.read'], $grants['action_permissions']);
        self::assertSame('selected', $grants['establishment_scope']);
        self::assertSame([3, 7], $grants['establishment_ids']);
    }

    public function test_selected_scope_requires_an_explicit_list_and_rejects_foreign_ids(): void
    {
        $grants = app(ErpCompanyMemberGrants::class);
        try {
            app(OfficeErpCompanyMembers::class)->validateInvitation([
                'name' => 'New User', 'email' => 'new@example.test', 'role' => 'viewer',
                'establishment_scope' => 'selected',
            ]);
            self::fail('A selected scope must include the explicit establishment list.');
        } catch (ValidationException) {
            self::assertTrue(true);
        }
        self::assertSame([], $grants->normalize('operator', null, 'selected', [])['establishment_ids']);

        $company = ErpCompany::factory()->create();
        $foreignCompany = ErpCompany::factory()->create();
        $foreign = ErpEstablishment::factory()->create(['erp_company_id' => $foreignCompany->id]);
        try {
            $grants->assertEstablishments($company->id, [$foreign->id]);
            self::fail('A foreign establishment must be rejected.');
        } catch (ValidationException) {
            self::assertTrue(true);
        }
    }
}
