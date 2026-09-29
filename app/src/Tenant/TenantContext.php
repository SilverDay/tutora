<?php

declare(strict_types=1);

namespace Tutora\Tenant;

/**
 * Proof that the current request acts on behalf of an authenticated tutor (= tenant).
 *
 * Only the authentication layer creates this (after password + MFA). Tenant IDs are never
 * taken from request input; participant endpoints never construct a TenantContext.
 */
final class TenantContext
{
    private function __construct(public readonly int $tenantId)
    {
    }

    /** @internal Called by Auth\TutorAuthenticator after full (password + TOTP) login. */
    public static function forAuthenticatedTutor(int $tenantId): self
    {
        if ($tenantId <= 0) {
            throw new \InvalidArgumentException('Invalid tenant id');
        }
        return new self($tenantId);
    }

    /**
     * For CLI jobs that legitimately iterate tenants (purge, conversion daemon). Refused in
     * web requests: there a TenantContext only ever comes from authentication.
     */
    public static function forSystemJob(int $tenantId): self
    {
        if (PHP_SAPI !== 'cli') {
            throw new \LogicException('TenantContext::forSystemJob is for CLI jobs only');
        }
        return self::forAuthenticatedTutor($tenantId);
    }
}
