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
        $actualCheckoutIdentity = self::actualCheckoutIdentity();

        $this->assertFalse(VerifiedIntegrationCheckout::allows(
            $arbitraryCheckout,
            $arbitraryCheckout,
            self::SOURCE_ROOT,
            $actualCheckoutIdentity,
            $actualCheckoutIdentity,
        ));
    }

    public function test_it_rejects_the_container_alias_without_exact_source_metadata(): void
    {
        $actualCheckoutIdentity = self::actualCheckoutIdentity();

        foreach ([
            [null, $actualCheckoutIdentity],
            ['/home/obsidian/dev/opmoni', $actualCheckoutIdentity],
            [self::SOURCE_ROOT, null],
            [self::SOURCE_ROOT, ''],
            [self::SOURCE_ROOT, 'not-a-device-inode'],
            [self::SOURCE_ROOT, $actualCheckoutIdentity.'0'],
        ] as [$sourceCheckout, $sourceIdentity]) {
            $this->assertFalse(VerifiedIntegrationCheckout::allows(
                '/workspace',
                '/workspace',
                $sourceCheckout,
                $sourceIdentity,
                $actualCheckoutIdentity,
            ));
        }

        $this->assertFalse(VerifiedIntegrationCheckout::allows(
            '/workspace',
            '/tmp/another-checkout',
            self::SOURCE_ROOT,
            $actualCheckoutIdentity,
            $actualCheckoutIdentity,
        ));
    }

    public function test_it_accepts_the_container_alias_when_stat_identity_matches(): void
    {
        $actualCheckoutIdentity = self::actualCheckoutIdentity();

        $this->assertTrue(VerifiedIntegrationCheckout::allows(
            '/workspace',
            '/workspace',
            self::SOURCE_ROOT,
            $actualCheckoutIdentity,
            $actualCheckoutIdentity,
        ));
    }

    public function test_it_accepts_the_client_access_container_alias_only_with_exact_source_identity(): void
    {
        $source = '/home/obsidian/dev/opmoni/.worktrees/manage-office-erp-client-access';
        $identity = self::actualCheckoutIdentity();
        self::assertTrue(VerifiedIntegrationCheckout::allows('/workspace', '/workspace', $source, $identity, $identity));
        self::assertFalse(VerifiedIntegrationCheckout::allows('/workspace', '/workspace', $source, $identity.'0', $identity));
        self::assertFalse(VerifiedIntegrationCheckout::allows('/workspace', '/workspace', $source.'-other', $identity, $identity));
    }

    private static function actualCheckoutIdentity(): string
    {
        $checkout = realpath(dirname(__DIR__, 3));
        self::assertIsString($checkout);

        $identity = VerifiedIntegrationCheckout::statIdentityFor($checkout);
        self::assertNotNull($identity);

        return $identity;
    }
}
