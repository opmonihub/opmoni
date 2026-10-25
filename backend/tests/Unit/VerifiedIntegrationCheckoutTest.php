<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\VerifiedIntegrationCheckout;

final class VerifiedIntegrationCheckoutTest extends TestCase
{
    private const HOST_ROOT = '/home/obsidian/dev/opmoni/.worktrees/office-erp-p22-concurrency';

    private const SOURCE_ROOT = '/home/obsidian/dev/opmoni/.worktrees/office-erp-commercial-design';

    public function test_it_accepts_the_exact_expected_host_worktree(): void
    {
        $this->assertTrue(VerifiedIntegrationCheckout::allows(
            self::HOST_ROOT,
            self::HOST_ROOT,
            null,
            null,
            null,
        ));
    }

    public function test_it_rejects_a_wrong_expected_host_root(): void
    {
        $this->assertFalse(VerifiedIntegrationCheckout::allows(
            self::HOST_ROOT,
            self::SOURCE_ROOT,
            null,
            null,
            null,
        ));
    }

    public function test_it_rejects_an_arbitrary_checkout_even_when_expected_matches(): void
    {
        $arbitraryCheckout = '/tmp/untrusted-checkout';
        $sourceIdentity = VerifiedIntegrationCheckout::statIdentityFor(self::SOURCE_ROOT);

        $this->assertFalse(VerifiedIntegrationCheckout::allows(
            $arbitraryCheckout,
            $arbitraryCheckout,
            self::SOURCE_ROOT,
            $sourceIdentity,
            $sourceIdentity,
        ));
    }

    public function test_it_rejects_the_container_alias_without_exact_source_metadata(): void
    {
        $verifiedIdentity = VerifiedIntegrationCheckout::statIdentityFor(self::SOURCE_ROOT);
        self::assertNotNull($verifiedIdentity);

        foreach ([
            [null, $verifiedIdentity],
            ['/home/obsidian/dev/opmoni', $verifiedIdentity],
            [self::SOURCE_ROOT, null],
            [self::SOURCE_ROOT, ''],
            [self::SOURCE_ROOT, 'not-a-device-inode'],
            [self::SOURCE_ROOT, $verifiedIdentity.'0'],
        ] as [$sourceCheckout, $sourceIdentity]) {
            $this->assertFalse(VerifiedIntegrationCheckout::allows(
                '/workspace',
                '/workspace',
                $sourceCheckout,
                $sourceIdentity,
                $verifiedIdentity,
            ));
        }

        $this->assertFalse(VerifiedIntegrationCheckout::allows(
            '/workspace',
            '/tmp/another-checkout',
            self::SOURCE_ROOT,
            $verifiedIdentity,
            $verifiedIdentity,
        ));
    }

    public function test_it_accepts_the_verified_container_alias_identity_pair(): void
    {
        $verifiedIdentity = VerifiedIntegrationCheckout::statIdentityFor(self::SOURCE_ROOT);
        self::assertNotNull($verifiedIdentity);

        $this->assertTrue(VerifiedIntegrationCheckout::allows(
            '/workspace',
            '/workspace',
            self::SOURCE_ROOT,
            $verifiedIdentity,
            $verifiedIdentity,
        ));
    }
}
