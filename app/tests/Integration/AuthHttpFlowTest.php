<?php

declare(strict_types=1);

namespace Tutora\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tutora\App;
use Tutora\Audit\AuditLog;
use Tutora\Auth\ArraySessionStore;
use Tutora\Auth\Base32;
use Tutora\Auth\PasswordHasher;
use Tutora\Auth\PasswordPolicy;
use Tutora\Auth\Totp;
use Tutora\Auth\TutorAccounts;
use Tutora\Auth\TutorAuthService;
use Tutora\Config;
use Tutora\Http\Request;
use Tutora\Http\Response;
use Tutora\Security\RateLimiter;
use Tutora\Security\SecretBox;
use Tutora\Support\FrozenClock;
use Tutora\Tests\Support\TestDatabase;
use Tutora\Tests\Unit\PasswordPolicyTest;
use Tutora\Tests\Support\RecordingMailer;
use Tutora\Auth\SignupVerification;
use Tutora\Auth\RecoveryCodes;
use Tutora\Auth\AccountNotices;
use Tutora\Security\Logger;

final class AuthHttpFlowTest extends TestCase
{
    private ArraySessionStore $session;
    private FrozenClock $clock;
    private App $app;
    private RecordingMailer $mailer;

    protected function setUp(): void
    {
        $pdo = TestDatabase::reset();
        $this->clock = new FrozenClock('2026-03-01 10:00:00');
        $this->session = new ArraySessionStore();
        $this->mailer = new RecordingMailer();
        $config = Config::fromArray([
            'APP_ENV' => 'production',
            'APP_BASE_URL' => 'https://tutora.test',
            'ALLOWED_ORIGINS' => 'https://tutora.test',
            'WHITEBOARD_TOKEN_KEY' => str_repeat('33', 32),
            'STORAGE_PATH' => sys_get_temp_dir() . '/tutora-http-test-storage',
        ]);
        $this->app = (new App($config, $this->session, $this->clock, $pdo))->withAuthService(new TutorAuthService(
            new TutorAccounts($pdo, $this->clock),
            new PasswordHasher(1024, 1, 1),
            new PasswordPolicy(PasswordPolicyTest::checker(false)),
            new SecretBox(str_repeat("\x07", 32)),
            new RateLimiter($pdo, $this->clock),
            new AuditLog($pdo, $this->clock),
            $this->session,
            $this->clock,
            new SignupVerification($pdo, $this->mailer, $this->clock, new Logger(static fn () => null), 'https://tutora.test'),
            new RecoveryCodes($pdo, $this->clock),
            new AccountNotices($this->mailer, new Logger(static fn () => null), 'https://tutora.test'),
        ));
    }

    private function get(string $path): Response
    {
        return $this->app->handle(new Request('GET', $path, clientIp: '198.51.100.7'));
    }

    /** @param array<string,string> $fields */
    private function post(string $path, array $fields, bool $withCsrf = true): Response
    {
        if ($withCsrf) {
            $fields['_csrf'] = (string) $this->session->get('_csrf');
        }
        return $this->app->handle(new Request('POST', $path, post: $fields, headers: ['origin' => 'https://tutora.test'], clientIp: '198.51.100.7'));
    }

    public function testCompleteSignupEnrolLoginFlow(): void
    {
        $this->get('/signup');
        $r = $this->post('/signup', ['email' => 'k@example.org', 'display_name' => 'Klaus', 'password' => 'a long enough passphrase']);
        self::assertSame(200, $r->status);
        self::assertStringContainsString('Check your inbox', $r->body);
        $link = $this->mailer->sent[0]->textBody;
        self::assertStringContainsString('https://tutora.test/verify-email#t=', $link, 'token in the fragment, never in a logged URL');

        self::assertStringContainsString('id="verify-form"', $this->get('/verify-email')->body);
        $bad = $this->post('/verify-email', ['token' => (string) $this->mailer->tokenFor('k@example.org'), 'password' => 'wrong password here']);
        self::assertSame(422, $bad->status);
        self::assertStringContainsString('value="' . $this->mailer->tokenFor('k@example.org') . '"', $bad->body, 'token kept for a retry');
        $r = $this->post('/verify-email', ['token' => (string) $this->mailer->tokenFor('k@example.org'), 'password' => 'a long enough passphrase']);
        self::assertSame(303, $r->status);
        self::assertSame('/login/enroll', $r->headers['Location']);

        // not usable before MFA enrolment
        self::assertSame('/login', $this->get('/dashboard')->headers['Location']);

        $page = $this->get('/login/enroll');
        self::assertSame(200, $page->status);
        preg_match('#<p class="secret"><code>([A-Z2-7 ]+)</code>#', $page->body, $m);
        $secret = Base32::decode($m[1]);
        $code = Totp::code($secret, Totp::stepAt($this->clock->now()->getTimestamp()));
        $r = $this->post('/login/enroll', ['code' => $code]);
        self::assertSame(200, $r->status, 'recovery codes are shown once after enrolment');
        self::assertSame('no-store', $r->headers['Cache-Control']);
        preg_match_all('#<li><code>([A-Z2-7]{4}-[A-Z2-7]{4}-[A-Z2-7]{4}-[A-Z2-7]{4})</code></li>#', $r->body, $m);
        self::assertCount(10, $m[1]);
        $recoveryCodes = $m[1];
        self::assertSame(200, $this->get('/dashboard')->status);

        $this->post('/logout', []);
        self::assertNull($this->session->get('auth_tenant_id'), 'session destroyed on logout');
        self::assertSame('/login', $this->get('/dashboard')->headers['Location']);

        $this->clock->advance('PT2M');
        $this->get('/login');
        $r = $this->post('/login', ['email' => 'k@example.org', 'password' => 'a long enough passphrase']);
        self::assertSame('/login/mfa', $r->headers['Location']);
        self::assertSame(200, $this->get('/login/mfa')->status); // browser follows redirect, gets fresh CSRF token
        $r = $this->post('/login/mfa', ['code' => Totp::code($secret, Totp::stepAt($this->clock->now()->getTimestamp()))]);
        self::assertSame('/dashboard', $r->headers['Location']);
        self::assertSame(200, $this->get('/dashboard')->status);
    }

    public function testRecoveryCodeLoginAndRegeneration(): void
    {
        $this->get('/signup');
        $this->post('/signup', ['email' => 'k@example.org', 'display_name' => 'K', 'password' => 'a long enough passphrase']);
        $this->post('/verify-email', ['token' => (string) $this->mailer->tokenFor('k@example.org'), 'password' => 'a long enough passphrase']);
        preg_match('#<p class="secret"><code>([A-Z2-7 ]+)</code>#', $this->get('/login/enroll')->body, $m);
        $secret = Base32::decode($m[1]);
        $r = $this->post('/login/enroll', ['code' => Totp::code($secret, Totp::stepAt($this->clock->now()->getTimestamp()))]);
        preg_match_all('#<li><code>([A-Z2-7-]{19})</code></li>#', $r->body, $m);
        $codes = $m[1];
        $this->post('/logout', []);

        $login = function (string $code): \Tutora\Http\Response {
            $this->get('/login');
            $this->post('/login', ['email' => 'k@example.org', 'password' => 'a long enough passphrase']);
            $this->get('/login/mfa');
            return $this->post('/login/mfa', ['code' => $code]);
        };
        self::assertSame('/dashboard', $login(strtolower($codes[0]))->headers['Location'] ?? null, 'recovery code accepted, case-insensitive');
        self::assertStringContainsString('<strong>9</strong> unused recovery codes', $this->get('/account/password')->body);
        $this->post('/logout', []);
        self::assertSame(422, $login($codes[0])->status, 'a recovery code works only once');
        self::assertCount(1, array_filter($this->mailer->sent, static fn ($msg) => str_contains($msg->subject, 'recovery code')), 'tutor notified on use');

        // regeneration needs password + TOTP and invalidates the old codes
        $this->clock->advance('PT2M');
        self::assertSame('/dashboard', $login($codes[1])->headers['Location'] ?? null);
        $this->get('/account/password');
        $bad = $this->post('/account/recovery-codes', ['current_password' => 'wrong', 'code' => '000000']);
        self::assertSame(422, $bad->status);
        $r = $this->post('/account/recovery-codes', ['current_password' => 'a long enough passphrase', 'code' => Totp::code($secret, Totp::stepAt($this->clock->now()->getTimestamp()))]);
        self::assertSame(200, $r->status);
        preg_match_all('#<li><code>([A-Z2-7-]{19})</code></li>#', $r->body, $m);
        self::assertCount(10, $m[1]);
        self::assertSame([], array_intersect($codes, $m[1]));
        $this->post('/logout', []);
        self::assertSame(422, $login($codes[2])->status, 'old codes invalidated');
        self::assertSame('/dashboard', $login($m[1][0])->headers['Location'] ?? null);
    }

    public function testStateChangingRequestWithoutCsrfTokenRefused(): void
    {
        $this->get('/login');
        $r = $this->post('/login', ['email' => 'k@example.org', 'password' => 'x'], false);
        self::assertSame(403, $r->status);
    }

    public function testCrossOriginPostRefused(): void
    {
        $this->get('/login');
        $r = $this->app->handle(new Request('POST', '/login', post: ['_csrf' => (string) $this->session->get('_csrf')], headers: ['origin' => 'https://evil.example']));
        self::assertSame(403, $r->status);
    }

    public function testSecurityHeadersPresent(): void
    {
        $r = $this->get('/login');
        self::assertStringContainsString("frame-ancestors 'none'", $r->headers['Content-Security-Policy']);
        self::assertStringContainsString("script-src 'self'", $r->headers['Content-Security-Policy']);
        self::assertSame('nosniff', $r->headers['X-Content-Type-Options']);
        self::assertArrayHasKey('Strict-Transport-Security', $r->headers);
        // regression: no-referrer makes browsers send "Origin: null" on same-site form posts,
        // which breaks every tutor form behind the CSRF Origin check
        self::assertSame('same-origin', $r->headers['Referrer-Policy']);
    }

    public function testNullOriginIsRefused(): void
    {
        $this->get('/login');
        $r = $this->app->handle(new Request('POST', '/login', post: ['_csrf' => (string) $this->session->get('_csrf')], headers: ['origin' => 'null']));
        self::assertSame(403, $r->status);
    }

    public function testReflectedInputIsEncoded(): void
    {
        $this->get('/login');
        $r = $this->post('/login', ['email' => '"><script>alert(1)</script>', 'password' => 'wrong password here']);
        self::assertSame(422, $r->status);
        self::assertStringNotContainsString('<script>alert(1)</script>', $r->body);
        self::assertStringContainsString('&quot;&gt;&lt;script&gt;', $r->body);
    }

    public function testThrottledLoginReturns429WithRetryAfter(): void
    {
        $this->get('/login');
        for ($i = 0; $i < 6; $i++) {
            $r = $this->post('/login', ['email' => 'k@example.org', 'password' => 'wrong password here']);
        }
        $r = $this->post('/login', ['email' => 'k@example.org', 'password' => 'wrong password here']);
        self::assertSame(429, $r->status);
        self::assertGreaterThan(0, (int) $r->headers['Retry-After']);
    }

    public function testUnknownRoute404AndWrongMethod405(): void
    {
        self::assertSame(404, $this->get('/nope')->status);
        self::assertSame(405, $this->get('/logout')->status);
    }
}
