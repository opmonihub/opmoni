<?php

namespace Tests\Support;

final class VerifiedIntegrationCheckout
{
    private const HOST_WORKTREE_PREFIX = '/home/obsidian/dev/opmoni/.worktrees/';

    private const CONTAINER_CHECKOUT = '/workspace';

    private const VERIFIED_SOURCE_CHECKOUT = '/home/obsidian/dev/opmoni/.worktrees/office-erp-commercial-design';

    public static function statIdentityFor(string $path): ?string
    {
        $stat = @stat($path);

        if (! is_array($stat) || ! is_int($stat['dev'] ?? null) || ! is_int($stat['ino'] ?? null)) {
            return null;
        }

        return $stat['dev'].':'.$stat['ino'];
    }

    public static function allows(
        string $checkout,
        string|false $expectedCheckout,
        string|false|null $sourceCheckout,
        string|false|null $sourceIdentity,
        ?string $actualCheckoutIdentity,
    ): bool {
        if ($checkout !== $expectedCheckout) {
            return false;
        }

        if (str_starts_with($checkout, self::HOST_WORKTREE_PREFIX)) {
            return true;
        }

        return $checkout === self::CONTAINER_CHECKOUT
            && $expectedCheckout === self::CONTAINER_CHECKOUT
            && $sourceCheckout === self::VERIFIED_SOURCE_CHECKOUT
            && is_string($sourceIdentity)
            && preg_match('/\A(?:0|[1-9][0-9]*):(?:0|[1-9][0-9]*)\z/', $sourceIdentity) === 1
            && $actualCheckoutIdentity !== null
            && $actualCheckoutIdentity === $sourceIdentity;
    }
}
