<?php

declare(strict_types=1);

namespace Tutora\Audit;

use PDO;
use Tutora\Support\Clock;
use Tutora\Support\Time;

/**
 * Audit events for tutor security-relevant actions (spec: Additional Hardening).
 * Details must never contain secrets or participant content.
 */
final class AuditLog
{
    public const LOGIN_SUCCESS = 'auth.login.success';
    public const LOGIN_FAILURE = 'auth.login.failure';
    public const LOGIN_THROTTLED = 'auth.login.throttled';
    public const MFA_FAILURE = 'auth.mfa.failure';
    public const MFA_ENROLLED = 'auth.mfa.enrolled';
    public const MFA_RESET = 'auth.mfa.reset';
    public const MFA_RECOVERY_USED = 'auth.mfa.recovery_code_used';
    public const MFA_RECOVERY_REGENERATED = 'auth.mfa.recovery_codes_regenerated';
    public const LOGOUT = 'auth.logout';
    public const SIGNUP = 'auth.signup';
    public const PASSWORD_CHANGED = 'auth.password.changed';
    public const WORKSHOP_DELETED = 'workshop.deleted';
    public const SESSION_DELETED = 'session.deleted';
    public const SESSION_AUTO_ENDED = 'session.auto_ended';
    public const EXPORT = 'session.exported';
    public const AI_SUMMARY = 'ai.summary.generated';

    public function __construct(private readonly PDO $pdo, private readonly Clock $clock)
    {
    }

    /** @param array<string,scalar|null> $details */
    public function record(?int $tenantId, string $eventType, ?string $ip, array $details = []): void
    {
        $packedIp = $ip !== null && filter_var($ip, FILTER_VALIDATE_IP) !== false ? inet_pton($ip) : false;
        $this->pdo->prepare(
            'INSERT INTO audit_events (tenant_id, event_type, ip_address, details, created_at) VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $tenantId,
            $eventType,
            $packedIp === false ? null : $packedIp,
            $details === [] ? null : json_encode($details, JSON_THROW_ON_ERROR),
            Time::toDb($this->clock->now()),
        ]);
    }
}
