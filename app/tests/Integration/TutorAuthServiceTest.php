<?php

declare(strict_types=1);

namespace Tutora\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tutora\Audit\AuditLog;
use Tutora\Auth\ArraySessionStore;
use Tutora\Auth\AuthStage;
use Tutora\Auth\Base32;
use Tutora\Auth\PasswordHasher;
use Tutora\Auth\PasswordPolicy;
use Tutora\Auth\Totp;
use Tutora\Auth\TutorAccounts;
use Tutora\Auth\TutorAuthService;
use Tutora\Security\RateLimiter;
use Tutora\Security\SecretBox;
use Tutora\Support\FrozenClock;
use Tutora\Tests\Support\TestDatabase;
use Tutora\Tests\Unit\PasswordPolicyTest;

final class TutorAuthServiceTest extends TestCase
{
    private const PW = 'correct horse battery staple';
    private PDO $pdo;
    private FrozenClock $clock;
    private ArraySessionStore $session;
    private TutorAuthService $auth;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::reset();
        $this->clock = new FrozenClock('2026-03-01 10:00:00');
        $this->session = new ArraySessionStore();
        $this->auth = $this->service($this->session);
    }

    private function service(ArraySessionStore $session, bool $breached = false): TutorAuthService
    {
        return new TutorAuthService(
            new TutorAccounts($this->pdo, $this->clock),
            new PasswordHasher(1024, 1, 1), // cheap parameters for tests only
            new PasswordPolicy(PasswordPolicyTest::checker($breached)),
            new SecretBox(str_repeat("\x07", 32)),
            new RateLimiter($this->pdo, $this->clock),
            new AuditLog($this->pdo, $this->clock),
            $session,
            $this->clock,
        );
    }

    /** @return string raw TOTP secret */
    private function registerAndEnrol(): string
    {
        self::assertTrue($this->auth->register('Tutor@Example.org ', 'Klaus', self::PW, '198.51.100.1')->ok);
        $enrol = $this->auth->beginEnrollment();
        $secret = Base32::decode($enrol['secret']);
        $code = Totp::code($secret, Totp::stepAt($this->clock->now()->getTimestamp()));
        self::assertSame(AuthStage::Full, $this->auth->confirmEnrollment($code, '198.51.100.1')->stage);
        return $secret;
    }

    public function testSignupRequiresMfaEnrollmentBeforeAccess(): void
    {
        $r = $this->auth->register('tutor@example.org', 'Klaus', self::PW, '198.51.100.1');
        self::assertSame(AuthStage::MfaEnrollment, $r->stage);
        self::assertNull($this->auth->currentTenant(), 'no tenant context before MFA');
    }

    public function testFullEnrollmentAndLoginFlow(): void
    {
        $secret = $this->registerAndEnrol();
        $tenant = $this->auth->currentTenant();
        self::assertNotNull($tenant);

        $row = $this->pdo->query('SELECT email, password_hash, totp_secret_enc FROM tenants')->fetch();
        self::assertSame('tutor@example.org', $row['email'], 'email normalised');
        self::assertStringStartsWith('$argon2id$', $row['password_hash']);
        self::assertStringNotContainsString($secret, $row['totp_secret_enc'], 'TOTP secret encrypted at rest');

        $this->auth->logout('198.51.100.1');
        self::assertNull($this->auth->currentTenant());

        $this->clock->advance('PT5M');
        $r = $this->auth->login('TUTOR@example.org', self::PW, '198.51.100.1');
        self::assertSame(AuthStage::MfaPending, $r->stage);
        self::assertNull($this->auth->currentTenant(), 'password alone is not enough');

        $code = Totp::code($secret, Totp::stepAt($this->clock->now()->getTimestamp()));
        self::assertSame(AuthStage::Full, $this->auth->verifyMfa($code, '198.51.100.1')->stage);
        self::assertSame($tenant->tenantId, $this->auth->currentTenant()?->tenantId);
    }

    public function testTotpReplayRejected(): void
    {
        $secret = $this->registerAndEnrol();
        $this->auth->logout(null);
        $this->auth->login('tutor@example.org', self::PW, '198.51.100.1');
        // same code (same step) that was used during enrolment
        $code = Totp::code($secret, Totp::stepAt($this->clock->now()->getTimestamp()));
        self::assertFalse($this->auth->verifyMfa($code, '198.51.100.1')->ok);
    }

    public function testWrongPasswordAndUnknownAccountLookIdentical(): void
    {
        $this->registerAndEnrol();
        $a = $this->auth->login('tutor@example.org', 'wrong password here', '198.51.100.2');
        $b = $this->auth->login('nobody@example.org', 'wrong password here', '198.51.100.2');
        self::assertFalse($a->ok);
        self::assertSame($a->errors, $b->errors);
    }

    public function testPerAccountBackoffWithoutHardLockout(): void
    {
        $this->registerAndEnrol();
        $this->auth->logout(null);
        for ($i = 0; $i < 6; $i++) {
            $this->auth->login('tutor@example.org', 'wrong password here', '203.0.113.' . $i);
        }
        $r = $this->auth->login('tutor@example.org', self::PW, '203.0.113.99');
        self::assertFalse($r->ok);
        self::assertGreaterThan(0, $r->retryAfter, 'throttled even from a new IP (per-account)');
        $this->clock->advance('PT16M');
        self::assertTrue($this->auth->login('tutor@example.org', self::PW, '203.0.113.99')->ok, 'backoff expires');
    }

    public function testMfaStageExpires(): void
    {
        $secret = $this->registerAndEnrol();
        $this->auth->logout(null);
        $this->auth->login('tutor@example.org', self::PW, '198.51.100.1');
        $this->clock->advance('PT11M');
        $code = Totp::code($secret, Totp::stepAt($this->clock->now()->getTimestamp()));
        self::assertFalse($this->auth->verifyMfa($code, '198.51.100.1')->ok);
    }

    public function testIdleAndAbsoluteTimeouts(): void
    {
        $this->registerAndEnrol();
        $this->clock->advance('PT1H59M');
        self::assertNotNull($this->auth->currentTenant());
        $this->clock->advance('PT2H1M');
        self::assertNull($this->auth->currentTenant(), 'idle timeout');

        $this->setUp();
        $this->registerAndEnrol();
        for ($i = 0; $i < 13; $i++) {
            $this->clock->advance('PT1H');
            $t = $this->auth->currentTenant();
        }
        self::assertNull($t, 'absolute timeout even with activity');
    }

    public function testSessionIdRegeneratedOnEachStage(): void
    {
        $this->registerAndEnrol();
        self::assertGreaterThanOrEqual(2, $this->session->regenerations);
    }

    public function testBreachedPasswordRejectedAtSignup(): void
    {
        $auth = $this->service(new ArraySessionStore(), true);
        $r = $auth->register('x@example.org', 'X', self::PW, '198.51.100.1');
        self::assertFalse($r->ok);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM tenants')->fetchColumn());
    }

    public function testChangePasswordRequiresCurrentPasswordAndTotp(): void
    {
        $secret = $this->registerAndEnrol();
        $tenant = $this->auth->currentTenant();
        $this->clock->advance('PT1M');
        $code = Totp::code($secret, Totp::stepAt($this->clock->now()->getTimestamp()));
        self::assertFalse($this->auth->changePassword($tenant, 'wrong', 'another long passphrase', $code, '198.51.100.1')->ok);
        $this->clock->advance('PT1M');
        $code = Totp::code($secret, Totp::stepAt($this->clock->now()->getTimestamp()));
        self::assertTrue($this->auth->changePassword($tenant, self::PW, 'another long passphrase', $code, '198.51.100.1')->ok);
        $this->auth->logout(null);
        self::assertTrue($this->auth->login('tutor@example.org', 'another long passphrase', '198.51.100.1')->ok);
    }

    public function testAuditEventsRecordedWithoutSecrets(): void
    {
        $this->registerAndEnrol();
        $this->auth->login('tutor@example.org', 'wrong password here', '198.51.100.1');
        $types = $this->pdo->query('SELECT event_type FROM audit_events ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame([AuditLog::SIGNUP, AuditLog::MFA_ENROLLED, AuditLog::LOGIN_SUCCESS, AuditLog::LOGIN_FAILURE], $types);
        $details = implode(' ', array_filter($this->pdo->query('SELECT details FROM audit_events')->fetchAll(PDO::FETCH_COLUMN)));
        self::assertStringNotContainsString('wrong password', $details);
    }

    public function testDuplicateSignupRejected(): void
    {
        $this->registerAndEnrol();
        self::assertFalse($this->service(new ArraySessionStore())->register('TUTOR@example.org', 'Y', self::PW, '198.51.100.1')->ok);
    }
}
