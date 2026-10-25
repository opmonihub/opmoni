<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Erp\Commercial\OfficeErpOperations;
use App\Erp\Commercial\OfficeErpRecords;
use App\Models\AccountMembership;
use App\Models\Erp\Commercial\OfficeErpAuditEvent;
use App\Models\Erp\Commercial\OfficeErpCommercialOperation;
use App\Models\Erp\Commercial\OfficeErpSalesPlan;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\OfficeErpCommercialTestCase;

final class CommercialOperationsTest extends OfficeErpCommercialTestCase
{
    public function test_equal_repeat_has_one_effect_and_returns_the_original_outcome(): void
    {
        $context = $this->officeContext();
        $operations = new OfficeErpOperations;
        $effectCount = 0;
        $authorizationCount = 0;
        $auditWriteTransactionLevel = 0;
        $operationPayload = ['name' => 'Básico', 'options' => ['a' => 2, 'z' => 1]];
        DB::listen(static function (QueryExecuted $query) use (&$auditWriteTransactionLevel): void {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'insert') && str_contains(strtolower($query->sql), 'office_erp_audit_events')) {
                $auditWriteTransactionLevel = DB::transactionLevel();
            }
        });
        $authorize = static function (string $action, array $payload) use (&$authorizationCount): void {
            self::assertGreaterThan(0, DB::transactionLevel());
            if ($authorizationCount === 0) {
                self::assertSame(0, OfficeErpCommercialOperation::query()->count(), 'Authorization and business locks must precede journal identity acquisition.');
            }
            $authorizationCount++;
        };
        $effect = static function () use (&$effectCount, $context): array {
            $effectCount++;
            self::assertGreaterThan(0, DB::transactionLevel());
            $operation = OfficeErpRecords::query($context, OfficeErpCommercialOperation::class)
                ->where('idempotency_key', 'plan-create-once')
                ->first();
            self::assertNotNull($operation, 'The operation identity must exist before the domain effect starts.');
            self::assertNull($operation->outcome);

            return ['subject_type' => OfficeErpSalesPlan::class, 'subject_id' => 42, 'result' => 'created', 'owner_token' => 'must-not-persist'];
        };

        $first = $operations->execute($context, 'plan-create-once', 'plan.create', ['name' => 'Básico', 'options' => ['z' => 1, 'a' => 2]], $authorize, $effect);
        $second = $operations->execute($context, 'plan-create-once', 'plan.create', ['options' => ['a' => 2, 'z' => 1], 'name' => 'Básico'], $authorize, $effect);

        self::assertSame($first, $second);
        self::assertSame(1, $effectCount);
        self::assertSame(2, $authorizationCount);
        self::assertSame(1, OfficeErpCommercialOperation::query()->count());
        $operation = OfficeErpCommercialOperation::query()->firstOrFail();
        self::assertSame(1, OfficeErpAuditEvent::query()->count());
        $audit = OfficeErpAuditEvent::query()->firstOrFail();
        self::assertSame($context->actorKind, $audit->actor_kind);
        self::assertSame($context->actorIdentity, $audit->actor_identity);
        self::assertSame($context->actor?->getKey(), $audit->actor_user_id);
        self::assertSame($operation->getKey(), $audit->operation_id);
        self::assertGreaterThan(0, $auditWriteTransactionLevel);
        self::assertArrayNotHasKey('owner_token', $first);
        self::assertArrayNotHasKey('owner_token', $audit->metadata['outcome']);
        self::assertSame($operationPayload, $operation->payload);
        self::assertSame($operationPayload, $audit->metadata['payload']);
    }

    public function test_reusing_a_key_with_a_different_action_or_payload_conflicts(): void
    {
        $context = $this->officeContext();
        $operations = new OfficeErpOperations;
        $authorize = static function (string $action, array $payload): void {};
        $effect = static fn (): array => ['resource_id' => 42];

        $operations->execute($context, 'plan-create-once', 'plan.create', ['name' => 'Básico'], $authorize, $effect);

        $this->assertConflict(fn () => $operations->execute($context, 'plan-create-once', 'plan.update', ['name' => 'Básico'], $authorize, $effect));
        $this->assertConflict(fn () => $operations->execute($context, 'plan-create-once', 'plan.create', ['name' => 'Essencial'], $authorize, $effect));
    }

    public function test_failed_effect_rolls_back_its_operation_row(): void
    {
        $context = $this->officeContext();
        $operations = new OfficeErpOperations;
        $authorize = static function (string $action, array $payload): void {};

        try {
            $operations->execute(
                $context,
                'plan-create-fails',
                'plan.create',
                ['name' => 'Básico'],
                $authorize,
                static function (): array {
                    throw new \RuntimeException('domain effect failed');
                },
            );
            self::fail('The effect exception should be rethrown.');
        } catch (\RuntimeException $exception) {
            self::assertSame('domain effect failed', $exception->getMessage());
        }

        self::assertSame(0, OfficeErpCommercialOperation::query()->count());
        self::assertSame(0, OfficeErpAuditEvent::query()->count());
    }

    public function test_audit_failure_rolls_back_operation_and_domain_change_atomically(): void
    {
        $context = $this->officeContext();
        $operations = new OfficeErpOperations;
        $authorize = static function (string $action, array $payload): void {};
        OfficeErpAuditEvent::creating(static function (): never {
            throw new \RuntimeException('audit write failed');
        });

        try {
            try {
                $operations->execute(
                    $context,
                    'plan-create-audit-fails',
                    'plan.create',
                    ['name' => 'Básico'],
                    $authorize,
                    static function () use ($context): array {
                        OfficeErpRecords::persist($context, new OfficeErpSalesPlan([
                            'name' => 'Básico',
                            'status' => 'active',
                        ]));

                        return ['result' => 'created'];
                    },
                );
                self::fail('The audit failure should abort the operation transaction.');
            } catch (\RuntimeException $exception) {
                self::assertSame('audit write failed', $exception->getMessage());
            }
        } finally {
            OfficeErpAuditEvent::flushEventListeners();
        }

        self::assertSame(0, OfficeErpCommercialOperation::query()->count());
        self::assertSame(0, OfficeErpAuditEvent::query()->count());
        self::assertSame(0, OfficeErpSalesPlan::query()->count());
    }

    public function test_domain_unique_constraint_failure_is_not_recovered_as_an_operation_replay(): void
    {
        $context = $this->officeContext();
        $operations = new OfficeErpOperations;

        try {
            $operations->execute(
                $context,
                'plan-create-domain-unique-fails',
                'plan.create',
                ['name' => 'Básico'],
                static function (string $action, array $payload): void {},
                static function () use ($context): array {
                    AccountMembership::factory()->create([
                        'account_id' => $context->accountId,
                        'user_id' => $context->actor?->getKey(),
                    ]);

                    return ['result' => 'created'];
                },
            );
            self::fail('The unrelated domain uniqueness failure must be rethrown.');
        } catch (UniqueConstraintViolationException) {
            self::assertSame(0, OfficeErpCommercialOperation::query()->count());
            self::assertSame(0, OfficeErpAuditEvent::query()->count());
        }
    }

    public function test_list_order_is_part_of_the_canonical_operation_payload(): void
    {
        $context = $this->officeContext();
        $operations = new OfficeErpOperations;
        $authorize = static function (string $action, array $payload): void {};
        $effect = static fn (): array => ['result' => 'created'];

        $operations->execute($context, 'plan-create-list-order', 'plan.create', ['modules' => ['erp.sales', 'erp.finance']], $authorize, $effect);

        $this->assertConflict(fn () => $operations->execute($context, 'plan-create-list-order', 'plan.create', ['modules' => ['erp.finance', 'erp.sales']], $authorize, $effect));
    }

    public function test_foreign_actor_cannot_lookup_an_operation(): void
    {
        $context = $this->officeContext();
        $foreignActorContext = $this->officeContextFor($context->account);
        $operations = new OfficeErpOperations;
        $operations->execute($context, 'plan-create-foreign', 'plan.create', ['name' => 'Básico'], static function (string $action, array $payload): void {}, static fn (): array => ['result' => 'created']);
        $authorizationCalled = false;

        try {
            $operations->lookup($foreignActorContext, 'plan-create-foreign', static function (string $action, array $payload) use (&$authorizationCalled): void {
                $authorizationCalled = true;
            });
            self::fail('A different verified actor must not retrieve another actor’s operation.');
        } catch (HttpException $exception) {
            self::assertSame(404, $exception->getStatusCode());
        }

        self::assertFalse($authorizationCalled);
    }

    public function test_lookup_rechecks_authority_before_disclosing_a_committed_outcome(): void
    {
        $context = $this->officeContext();
        $operations = new OfficeErpOperations;
        $allowed = static function (string $action, array $payload): void {};
        $original = $operations->execute($context, 'plan-create-once', 'plan.create', ['name' => 'Básico'], $allowed, static fn (): array => ['resource_id' => 42]);
        $lookup = $operations->lookup($context, 'plan-create-once', static function (string $action, array $payload): void {
            self::assertSame('plan.create', $action);
            self::assertSame(['name' => 'Básico'], $payload);
            self::assertGreaterThan(0, DB::transactionLevel());
        });
        self::assertSame($original, $lookup);

        $this->expectException(AuthorizationException::class);
        $operations->lookup($context, 'plan-create-once', static function (string $action, array $payload): void {
            throw new AuthorizationException;
        });
    }

    public function test_secret_fields_are_rejected_before_they_can_be_hashed_or_stored(): void
    {
        $context = $this->officeContext();
        $operations = new OfficeErpOperations;

        $this->expectException(ValidationException::class);
        $operations->execute(
            $context,
            'plan-create-with-token',
            'plan.create',
            ['name' => 'Básico', 'owner_token' => 'never-store-this'],
            static function (string $action, array $payload): void {},
            static fn (): array => ['resource_id' => 42],
        );
    }

    private function assertConflict(\Closure $operation): void
    {
        try {
            $operation();
            self::fail('A reused operation key with a different action or payload must conflict.');
        } catch (HttpException $exception) {
            self::assertSame(409, $exception->getStatusCode());
        }
    }
}
