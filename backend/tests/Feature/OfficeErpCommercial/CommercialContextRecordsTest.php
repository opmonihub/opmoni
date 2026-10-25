<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Erp\Commercial\OfficeErpExecutionContext;
use App\Erp\Commercial\OfficeErpRecords;
use App\Models\Account;
use App\Models\User;
use App\Tenant\CurrentTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\OfficeErpCommercialTestCase;

class CommercialContextRecordsTest extends OfficeErpCommercialTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('office_erp_context_record_fixtures', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('related_id')->nullable();
            $table->string('label');
            $table->timestamps();
        });
    }

    public function test_office_admin_context_requires_real_admin_membership(): void
    {
        $account = Account::factory()->create(['status' => 'active']);
        $actor = User::factory()->create();

        $this->expectException(HttpException::class);

        OfficeErpExecutionContext::officeAdmin($account, $actor, 'commercial.plan.create');
    }

    public function test_owner_identity_is_stable_across_identity_creation_and_system_context_binds_resource(): void
    {
        $account = Account::factory()->create(['status' => 'active']);
        $invitationIdentity = 'invitation:'.str_repeat('a', 40);
        $anonymousOwner = OfficeErpExecutionContext::owner(
            $account,
            null,
            'commercial.owner.accept',
            $invitationIdentity,
        );
        $owner = User::factory()->create();
        $authenticatedOwner = OfficeErpExecutionContext::owner(
            $account,
            $owner,
            'commercial.owner.accept',
            $invitationIdentity,
        );
        $system = OfficeErpExecutionContext::system($account, 'contract:42:billing-period:3');
        $otherSystemResource = OfficeErpExecutionContext::system($account, 'contract:43:billing-period:3');

        $this->assertSame('owner', $anonymousOwner->actorKind);
        $this->assertSame($anonymousOwner->actorIdentity, $authenticatedOwner->actorIdentity);
        $this->assertStringNotContainsString($invitationIdentity, $authenticatedOwner->actorIdentity);
        $this->assertNotSame($anonymousOwner->actorIdentity, OfficeErpExecutionContext::owner(
            $account,
            null,
            'commercial.owner.accept',
            'another:'.str_repeat('c', 40),
        )->actorIdentity);
        $this->assertSame('system', $system->actorKind);
        $this->assertNotSame($system->actorIdentity, $otherSystemResource->actorIdentity);
    }

    public function test_context_runner_restores_previous_tenant_after_exception_for_owner_and_system(): void
    {
        $account = Account::factory()->create(['status' => 'active']);
        $tenant = app(CurrentTenant::class);
        $tenant->accountId = 987;
        $contexts = [
            OfficeErpExecutionContext::owner($account, null, 'commercial.owner.accept', 'invitation:'.str_repeat('b', 40)),
            OfficeErpExecutionContext::system($account, 'contract:51:renewal'),
        ];

        foreach ($contexts as $context) {
            try {
                $context->run(function () use ($context, $tenant): void {
                    $this->assertSame($context->accountId, $tenant->accountId);
                    throw new \RuntimeException('fixture failure');
                });
                $this->fail('The fixture exception should escape the context runner.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('fixture failure', $exception->getMessage());
            }

            $this->assertSame(987, $tenant->accountId);
        }
    }

    public function test_system_context_rejects_inactive_account(): void
    {
        $account = Account::factory()->create(['status' => 'suspended']);

        $this->expectException(HttpException::class);

        OfficeErpExecutionContext::system($account, 'contract:9:renewal');
    }

    public function test_direct_owner_context_is_not_verified_without_invitation_factory(): void
    {
        $account = Account::factory()->create(['status' => 'active']);
        $context = new OfficeErpExecutionContext($account, null, 'owner', 'commercial.owner.accept');

        $this->expectException(HttpException::class);

        $context->assertVerified();
    }

    public function test_verified_actor_identity_cannot_be_reassigned(): void
    {
        $context = OfficeErpExecutionContext::system(
            Account::factory()->create(['status' => 'active']),
            'contract:73:renewal',
        );

        $this->expectException(\LogicException::class);

        $context->actorIdentity = 'system:another-resource';
    }

    public function test_scoped_repository_denies_cross_account_relationship_resolution(): void
    {
        $context = $this->officeContext();
        $foreignAccount = Account::factory()->create(['status' => 'active']);
        $foreign = OfficeErpContextRecordFixture::query()->create([
            'account_id' => $foreignAccount->id,
            'label' => 'foreign',
        ]);
        $relatedRecord = OfficeErpContextRecordFixture::query()->create([
            'account_id' => $context->accountId,
            'related_id' => $foreign->getKey(),
            'label' => 'owned reference',
        ]);

        $created = OfficeErpRecords::persist($context, new OfficeErpContextRecordFixture(['label' => 'owned']));
        $ownedReference = OfficeErpRecords::query($context, OfficeErpContextRecordFixture::class)
            ->whereKey($relatedRecord->getKey())
            ->firstOrFail();
        $resolvedForeignReference = OfficeErpRecords::query($context, OfficeErpContextRecordFixture::class)
            ->whereKey($ownedReference->related_id)
            ->first();

        $this->assertSame($context->accountId, (int) $created->account_id);
        $this->assertNull($resolvedForeignReference);
        $this->assertSame(1, OfficeErpRecords::query($context, OfficeErpContextRecordFixture::class)
            ->where('label', 'owned')
            ->count());
    }

    public function test_scoped_repository_rejects_cross_account_persistence(): void
    {
        $context = $this->officeContext();
        $foreignAccount = Account::factory()->create(['status' => 'active']);
        $record = new OfficeErpContextRecordFixture([
            'account_id' => $foreignAccount->id,
            'label' => 'mismatch',
        ]);

        try {
            OfficeErpRecords::persist($context, $record);
            $this->fail('A record from another Account must be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $this->assertDatabaseMissing('office_erp_context_record_fixtures', ['label' => 'mismatch']);
    }
}

class OfficeErpContextRecordFixture extends Model
{
    protected $table = 'office_erp_context_record_fixtures';

    protected $guarded = [];
}
