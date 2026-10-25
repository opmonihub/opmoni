<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Erp\Commercial\OfficeErpCommercialOperation;
use App\Erp\Commercial\OfficeErpOperations;
use Illuminate\Auth\Access\AuthorizationException;
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
        $authorize = static function (string $action, array $payload) use (&$authorizationCount): void {
            self::assertGreaterThan(0, DB::transactionLevel());
            $authorizationCount++;
        };
        $effect = static function () use (&$effectCount): array {
            $effectCount++;

            return ['resource_id' => 42, 'result' => 'created'];
        };

        $first = $operations->execute($context, 'plan-create-once', 'plan.create', ['name' => 'Básico', 'options' => ['z' => 1, 'a' => 2]], $authorize, $effect);
        $second = $operations->execute($context, 'plan-create-once', 'plan.create', ['options' => ['a' => 2, 'z' => 1], 'name' => 'Básico'], $authorize, $effect);

        self::assertSame($first, $second);
        self::assertSame(1, $effectCount);
        self::assertSame(2, $authorizationCount);
        self::assertSame(1, OfficeErpCommercialOperation::query()->count());
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
    }

    public function test_lookup_rechecks_authority_before_disclosing_a_committed_outcome(): void
    {
        $context = $this->officeContext();
        $operations = new OfficeErpOperations;
        $allowed = static function (string $action, array $payload): void {};
        $operations->execute($context, 'plan-create-once', 'plan.create', ['name' => 'Básico'], $allowed, static fn (): array => ['resource_id' => 42]);

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
