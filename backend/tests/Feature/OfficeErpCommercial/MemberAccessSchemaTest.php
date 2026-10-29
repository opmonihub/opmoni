<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Models\Erp\Commercial\OfficeErpAccessDelivery;
use App\Models\Erp\Commercial\OfficeErpCompanyRegistration;
use App\Models\Erp\Commercial\OfficeErpMemberInvitation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\OfficeErpCommercialTestCase;

class MemberAccessSchemaTest extends OfficeErpCommercialTestCase
{
    public function test_identity_revision_defaults_to_zero_without_being_publicly_mass_assignable(): void
    {
        $user = User::factory()->create();
        self::assertSame(0, $user->fresh()->access_auth_revision);
        self::assertFalse($user->isFillable('access_auth_revision'));
    }

    public function test_member_invitation_stores_a_hash_and_delivery_encrypts_and_hides_the_bearer(): void
    {
        $invitation = OfficeErpMemberInvitation::factory()->create()->refresh();
        $token = str_repeat('a', 64);
        $delivery = OfficeErpAccessDelivery::factory()->forInvitation($invitation)->create([
            'payload' => ['token' => $token],
        ]);
        $stored = DB::table('office_erp_access_deliveries')->where('id', $delivery->id)->value('payload');
        self::assertStringNotContainsString($token, $stored);
        self::assertSame(['token' => $token], $delivery->fresh()->payload);
        self::assertArrayNotHasKey('payload', $delivery->fresh()->toArray());
        self::assertArrayNotHasKey('token_hash', $invitation->toArray());
        self::assertSame(64, strlen($invitation->token_hash));
        self::assertSame('member_access', $invitation->purpose);
        self::assertSame('pending', $invitation->status);
        $this->assertDatabaseCount('erp_company_memberships', 0);
    }

    public function test_pending_normalized_email_is_unique_within_a_company(): void
    {
        $first = OfficeErpMemberInvitation::factory()->create(['email' => ' Recipient@Example.test ']);
        self::assertSame('recipient@example.test', $first->email);
        $this->expectException(QueryException::class);
        OfficeErpMemberInvitation::factory()->create([
            'account_id' => $first->account_id, 'erp_company_id' => $first->erp_company_id,
            'registration_id' => $first->registration_id, 'email' => 'RECIPIENT@example.test',
        ]);
    }

    public function test_replacement_preserves_a_revoked_invitation_and_uses_a_new_hash(): void
    {
        $first = OfficeErpMemberInvitation::factory()->create();
        $first->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save();
        $replacement = OfficeErpMemberInvitation::factory()->create([
            'account_id' => $first->account_id, 'erp_company_id' => $first->erp_company_id,
            'registration_id' => $first->registration_id, 'email' => $first->email,
        ]);
        self::assertNotSame($first->token_hash, $replacement->token_hash);
        $this->assertDatabaseCount('erp_company_member_invitations', 2);
    }

    public function test_invitation_rejects_a_foreign_registration(): void
    {
        $own = OfficeErpCompanyRegistration::factory()->create();
        $foreign = OfficeErpCompanyRegistration::factory()->create();
        self::assertTrue(Schema::hasTable('erp_company_member_invitations'));
        $this->expectException(QueryException::class);
        OfficeErpMemberInvitation::factory()->create([
            'account_id' => $own->account_id, 'erp_company_id' => $own->erp_company_id,
            'registration_id' => $foreign->id,
        ]);
    }

    public function test_delivery_rejects_an_invitation_from_another_company(): void
    {
        $first = OfficeErpMemberInvitation::factory()->create();
        $foreign = OfficeErpMemberInvitation::factory()->create();
        self::assertTrue(Schema::hasTable('office_erp_access_deliveries'));
        $this->expectException(QueryException::class);
        OfficeErpAccessDelivery::factory()->forInvitation($first)->create(['member_invitation_id' => $foreign->id]);
    }
}
