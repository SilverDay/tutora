<?php

declare(strict_types=1);

namespace Tutora\Auth;

use Tutora\Audit\AuditLog;
use Tutora\Security\RateLimiter;
use Tutora\Security\RateLimitPolicy;
use Tutora\Security\SecretBox;
use Tutora\Support\Clock;
use Tutora\Tenant\TenantContext;

/**
 * Tutor authentication: Argon2id password + mandatory TOTP MFA, server-side sessions,
 * per-IP and per-account backoff, audit events.
 */
final class TutorAuthService
{
    private const S_TENANT = 'auth_tenant_id';
    private const S_STAGE = 'auth_stage';
    private const S_STAGE_SINCE = 'auth_stage_since';
    private const S_LAST_SEEN = 'auth_last_seen';
    private const S_FULL_SINCE = 'auth_full_since';
    private const S_PENDING_SECRET = 'auth_pending_totp';
    private const S_EPOCH = 'auth_epoch';

    /** time allowed between password step and TOTP/enrolment step */
    public const PARTIAL_STAGE_TTL = 600;
    public const IDLE_TIMEOUT = 7200;
    public const ABSOLUTE_TIMEOUT = 43200;

    public function __construct(
        private readonly TutorAccounts $accounts,
        private readonly PasswordHasher $hasher,
        private readonly PasswordPolicy $policy,
        private readonly SecretBox $totpBox,
        private readonly RateLimiter $limiter,
        private readonly AuditLog $audit,
        private readonly SessionStore $session,
        private readonly Clock $clock,
        private readonly SignupVerification $signups,
        private readonly RecoveryCodes $recovery,
        private readonly AccountNotices $notices,
        private readonly TutorRealtimeRevoker $realtime,
    ) {
    }

    public const SIGNUPS_PER_IP_PER_HOUR = 10;
    public const SIGNUPS_PER_EMAIL_PER_HOUR = 5;

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email), 'UTF-8');
    }

    /**
     * Starts a signup. For every valid request the outcome is the same neutral
     * "check your inbox": new addresses get a verification link, registered addresses a
     * neutral notice (owner decision 4b: no account enumeration at signup).
     */
    public function register(string $email, string $displayName, string $password, string $ip): AuthResult
    {
        $email = self::normalizeEmail($email);
        $displayName = trim($displayName);
        $errors = [];
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Please enter a valid email address.';
        }
        $nameLen = mb_strlen($displayName, 'UTF-8');
        if ($nameLen < 1 || $nameLen > 100 || !mb_check_encoding($displayName, 'UTF-8')) {
            $errors[] = 'Please enter a name between 1 and 100 characters.';
        }
        if ($errors !== []) {
            return AuthResult::fail($errors);
        }
        if (!$this->limiter->consume('signup-ip:' . $ip, self::SIGNUPS_PER_IP_PER_HOUR, 3600)
            || !$this->limiter->consume('signup-email:' . $email, self::SIGNUPS_PER_EMAIL_PER_HOUR, 3600)) {
            return AuthResult::throttled(3600);
        }
        $errors = $this->policy->validate($password, $email);
        if ($errors !== []) {
            return AuthResult::fail($errors);
        }
        // hash in every path so response timing does not reveal registered addresses
        $hash = $this->hasher->hash($password);
        if ($this->accounts->findByEmail($email) !== null) {
            $this->signups->notifyExisting($email);
        } else {
            $this->signups->createPending($email, $displayName, $hash);
        }
        return AuthResult::verificationPending();
    }

    /**
     * Completes a signup from the emailed link: token and the password chosen at signup
     * must both match. Creates the account and continues with mandatory TOTP enrolment.
     */
    public function verifySignup(string $token, string $password, string $ip): AuthResult
    {
        $key = 'verify-fail-ip:' . $ip;
        $wait = $this->limiter->retryAfter($key);
        if ($wait > 0) {
            return AuthResult::throttled($wait);
        }
        $pending = $this->signups->consume($token, $password, $this->hasher);
        $id = $pending === null ? null : $this->accounts->create($pending['email'], $pending['display_name'], $pending['password_hash']);
        if ($id === null) {
            $this->limiter->recordFailure($key, new RateLimitPolicy(10, 2, 900));
            return AuthResult::fail(['This link is invalid or has expired, or the password does not match.']);
        }
        $this->audit->record($id, AuditLog::SIGNUP, $ip);
        $this->enterStage($id, AuthStage::MfaEnrollment);
        return AuthResult::stage(AuthStage::MfaEnrollment);
    }

    public function login(string $email, string $password, string $ip): AuthResult
    {
        $email = self::normalizeEmail($email);
        $ipKey = 'login-ip:' . $ip;
        $acctKey = 'login-acct:' . $email;

        $wait = max($this->limiter->retryAfter($ipKey), $this->limiter->retryAfter($acctKey));
        if ($wait > 0) {
            $this->audit->record(null, AuditLog::LOGIN_THROTTLED, $ip);
            return AuthResult::throttled($wait);
        }

        $account = strlen($password) <= PasswordPolicy::MAX_LENGTH * 4 ? $this->accounts->findByEmail($email) : null;
        if ($account === null) {
            $this->hasher->dummyVerify($password);
        }
        if ($account === null || !$this->hasher->verify($password, (string) $account['password_hash'])) {
            $this->limiter->recordFailure($ipKey, RateLimitPolicy::loginPerIp());
            $this->limiter->recordFailure($acctKey, RateLimitPolicy::loginPerAccount());
            $this->audit->record($account === null ? null : (int) $account['id'], AuditLog::LOGIN_FAILURE, $ip, ['reason' => 'password']);
            return AuthResult::fail(['Invalid email address or password.']);
        }

        $id = (int) $account['id'];
        if ($this->hasher->needsRehash((string) $account['password_hash'])) {
            $this->accounts->updatePasswordHash($id, $this->hasher->hash($password), false);
        }
        $stage = $account['totp_enabled_at'] === null ? AuthStage::MfaEnrollment : AuthStage::MfaPending;
        $this->enterStage($id, $stage);
        return AuthResult::stage($stage);
    }

    public function verifyMfa(string $code, string $ip): AuthResult
    {
        $id = $this->partialTenant(AuthStage::MfaPending);
        if ($id === null) {
            return AuthResult::fail(['Your sign-in has expired. Please sign in again.']);
        }
        $account = $this->accounts->findById($id);
        if ($account === null || $account['totp_secret_enc'] === null) {
            $this->logout(null);
            return AuthResult::fail(['Your sign-in has expired. Please sign in again.']);
        }
        $acctKey = 'login-acct:' . $account['email'];
        $wait = $this->limiter->retryAfter($acctKey);
        if ($wait > 0) {
            return AuthResult::throttled($wait);
        }
        if (RecoveryCodes::looksLikeCode($code)) {
            if (!$this->recovery->consume($id, $code)) {
                $this->limiter->recordFailure($acctKey, RateLimitPolicy::loginPerAccount());
                $this->audit->record($id, AuditLog::MFA_FAILURE, $ip, ['method' => 'recovery_code']);
                return AuthResult::fail(['Invalid authentication code.']);
            }
            $remaining = $this->recovery->remaining($id);
            $this->audit->record($id, AuditLog::MFA_RECOVERY_USED, $ip, ['remaining' => $remaining]);
            $this->notices->recoveryCodeUsed((string) $account['email'], $remaining);
        } else {
            $secret = $this->totpBox->decrypt((string) $account['totp_secret_enc']);
            $step = Totp::verify($secret, $code, $this->clock->now()->getTimestamp(), $account['totp_last_step'] === null ? null : (int) $account['totp_last_step']);
            if ($step === null || !$this->accounts->consumeTotpStep($id, $step)) {
                $this->limiter->recordFailure($acctKey, RateLimitPolicy::loginPerAccount());
                $this->audit->record($id, AuditLog::MFA_FAILURE, $ip);
                return AuthResult::fail(['Invalid authentication code.']);
            }
        }
        $this->limiter->reset($acctKey);
        $this->enterStage($id, AuthStage::Full);
        $this->audit->record($id, AuditLog::LOGIN_SUCCESS, $ip);
        return AuthResult::stage(AuthStage::Full);
    }

    /**
     * Starts (or restarts) TOTP enrolment for an account in the enrolment stage.
     *
     * @return array{secret:string, uri:string}|null base32 secret + otpauth URI for the authenticator app
     */
    public function beginEnrollment(): ?array
    {
        $id = $this->partialTenant(AuthStage::MfaEnrollment);
        if ($id === null) {
            return null;
        }
        $account = $this->accounts->findById($id);
        if ($account === null) {
            return null;
        }
        $secret = Totp::generateSecret();
        $this->session->set(self::S_PENDING_SECRET, base64_encode($this->totpBox->encrypt($secret)));
        return ['secret' => Base32::encode($secret), 'uri' => Totp::provisioningUri($secret, (string) $account['email'])];
    }

    public function confirmEnrollment(string $code, string $ip): AuthResult
    {
        $id = $this->partialTenant(AuthStage::MfaEnrollment);
        $pending = $this->session->get(self::S_PENDING_SECRET);
        if ($id === null || !is_string($pending)) {
            return AuthResult::fail(['Your sign-in has expired. Please sign in again.']);
        }
        $secret = $this->totpBox->decrypt((string) base64_decode($pending, true));
        $step = Totp::verify($secret, $code, $this->clock->now()->getTimestamp(), null);
        if ($step === null) {
            $this->audit->record($id, AuditLog::MFA_FAILURE, $ip, ['phase' => 'enrollment']);
            return AuthResult::fail(['That code did not match. Check the time on your device and try again.']);
        }
        $this->accounts->enableTotp($id, $this->totpBox->encrypt($secret), $step);
        $this->session->remove(self::S_PENDING_SECRET);
        $this->audit->record($id, AuditLog::MFA_ENROLLED, $ip);
        $this->enterStage($id, AuthStage::Full);
        $this->audit->record($id, AuditLog::LOGIN_SUCCESS, $ip);
        return AuthResult::withRecoveryCodes(AuthStage::Full, $this->recovery->regenerate($id));
    }

    /** New recovery codes (old ones become invalid); requires password + TOTP. */
    public function regenerateRecoveryCodes(TenantContext $tenant, string $password, string $totpCode, string $ip): AuthResult
    {
        $check = $this->reauthenticate($tenant, $password, $totpCode);
        if ($check !== null) {
            return $check;
        }
        // treated like a credential change: other sessions end, this one continues
        $this->session->set(self::S_EPOCH, $this->accounts->bumpAuthEpoch($tenant->tenantId));
        $this->session->regenerate();
        $this->realtime->revokeAll($tenant->tenantId);
        $this->audit->record($tenant->tenantId, AuditLog::MFA_RECOVERY_REGENERATED, $ip);
        return AuthResult::withRecoveryCodes(AuthStage::Full, $this->recovery->regenerate($tenant->tenantId));
    }

    public function remainingRecoveryCodes(TenantContext $tenant): int
    {
        return $this->recovery->remaining($tenant->tenantId);
    }

    /** Current password + fresh TOTP code; null on success, otherwise the failure result. */
    private function reauthenticate(TenantContext $tenant, string $password, string $totpCode): ?AuthResult
    {
        $account = $this->accounts->findById($tenant->tenantId);
        if ($account === null || $account['totp_secret_enc'] === null) {
            return AuthResult::fail(['Account not found.']);
        }
        $acctKey = 'login-acct:' . $account['email'];
        $wait = $this->limiter->retryAfter($acctKey);
        if ($wait > 0) {
            return AuthResult::throttled($wait);
        }
        $secret = $this->totpBox->decrypt((string) $account['totp_secret_enc']);
        $step = Totp::verify($secret, $totpCode, $this->clock->now()->getTimestamp(), $account['totp_last_step'] === null ? null : (int) $account['totp_last_step']);
        if (!$this->hasher->verify($password, (string) $account['password_hash'])
            || $step === null || !$this->accounts->consumeTotpStep($tenant->tenantId, $step)) {
            $this->limiter->recordFailure($acctKey, RateLimitPolicy::loginPerAccount());
            return AuthResult::fail(['Current password or authentication code is incorrect.']);
        }
        return null;
    }

    /**
     * Password change requires the current password and a fresh TOTP code (re-authentication).
     */
    public function changePassword(TenantContext $tenant, string $current, string $new, string $totpCode, string $ip): AuthResult
    {
        $check = $this->reauthenticate($tenant, $current, $totpCode);
        if ($check !== null) {
            return $check;
        }
        $account = $this->accounts->findById($tenant->tenantId);
        $errors = $this->policy->validate($new, (string) $account['email']);
        if ($errors !== []) {
            return AuthResult::fail($errors);
        }
        // other sessions end (auth epoch bumped in the same statement); this one continues
        $this->session->set(self::S_EPOCH, $this->accounts->updatePasswordHash($tenant->tenantId, $this->hasher->hash($new), true));
        $this->audit->record($tenant->tenantId, AuditLog::PASSWORD_CHANGED, $ip);
        $this->session->regenerate();
        $this->realtime->revokeAll($tenant->tenantId);
        return AuthResult::stage(AuthStage::Full);
    }

    /** The authenticated tenant, or null. Enforces idle and absolute session timeouts. */
    public function currentTenant(): ?TenantContext
    {
        $id = $this->session->get(self::S_TENANT);
        if (!is_int($id) || $this->session->get(self::S_STAGE) !== AuthStage::Full->value) {
            return null;
        }
        $now = $this->clock->now()->getTimestamp();
        $lastSeen = (int) $this->session->get(self::S_LAST_SEEN);
        $fullSince = (int) $this->session->get(self::S_FULL_SINCE);
        if ($now - $lastSeen > self::IDLE_TIMEOUT || $now - $fullSince > self::ABSOLUTE_TIMEOUT || !$this->epochValid($id)) {
            $this->logout(null);
            return null;
        }
        $this->session->set(self::S_LAST_SEEN, $now);
        return TenantContext::forAuthenticatedTutor($id);
    }

    public function currentStage(): ?AuthStage
    {
        $s = $this->session->get(self::S_STAGE);
        return is_string($s) ? AuthStage::tryFrom($s) : null;
    }

    public function logout(?string $ip): void
    {
        $id = $this->session->get(self::S_TENANT);
        if ($ip !== null && is_int($id) && $this->session->get(self::S_STAGE) === AuthStage::Full->value) {
            $this->audit->record($id, AuditLog::LOGOUT, $ip);
        }
        $this->session->destroy();
    }

    private function enterStage(int $tenantId, AuthStage $stage): void
    {
        // new session id on every privilege change (session fixation)
        $this->session->regenerate();
        $now = $this->clock->now()->getTimestamp();
        $this->session->set(self::S_TENANT, $tenantId);
        $this->session->set(self::S_STAGE, $stage->value);
        $this->session->set(self::S_STAGE_SINCE, $now);
        $this->session->set(self::S_LAST_SEEN, $now);
        $this->session->remove('_csrf');
        $this->session->set(self::S_EPOCH, $this->accounts->authEpoch($tenantId));
        if ($stage === AuthStage::Full) {
            $this->session->set(self::S_FULL_SINCE, $now);
        }
    }

    private function partialTenant(AuthStage $required): ?int
    {
        $id = $this->session->get(self::S_TENANT);
        if (!is_int($id) || $this->session->get(self::S_STAGE) !== $required->value) {
            return null;
        }
        if ($this->clock->now()->getTimestamp() - (int) $this->session->get(self::S_STAGE_SINCE) > self::PARTIAL_STAGE_TTL
            || !$this->epochValid($id)) {
            $this->session->destroy();
            return null;
        }
        return $id;
    }

    /** False once the account's credentials changed after this session was established (or it is gone). */
    private function epochValid(int $tenantId): bool
    {
        $stored = $this->session->get(self::S_EPOCH);
        $current = $this->accounts->authEpoch($tenantId);
        return is_int($stored) && $current !== null && $stored === $current;
    }
}
