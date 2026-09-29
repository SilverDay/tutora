<?php

declare(strict_types=1);

namespace Tutora\Tests\Support;

use PDO;
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
use Tutora\Tests\Unit\PasswordPolicyTest;

/** One browser-like client (own session store) against a fresh App instance. */
final class HttpHarness
{
    public ArraySessionStore $session;
    public App $app;

    public function __construct(PDO $pdo, public FrozenClock $clock, public string $ip = '198.51.100.7')
    {
        $this->session = new ArraySessionStore();
        $config = Config::fromArray([
            'APP_ENV' => 'production',
            'APP_BASE_URL' => 'https://tutora.test',
            'ALLOWED_ORIGINS' => 'https://tutora.test',
            'RELAY_TOKEN_KEY' => str_repeat('11', 32),
            'PARTICIPANT_CREDENTIAL_KEY' => str_repeat('22', 32),
            'RETENTION_DAYS_DEFAULT' => '30',
            'STORAGE_PATH' => sys_get_temp_dir() . '/tutora-http-test-storage',
        ]);
        $this->app = (new App($config, $this->session, $clock, $pdo))->withAuthService(new TutorAuthService(
            new TutorAccounts($pdo, $clock),
            new PasswordHasher(1024, 1, 1),
            new PasswordPolicy(PasswordPolicyTest::checker(false)),
            new SecretBox(str_repeat("\x07", 32)),
            new RateLimiter($pdo, $clock),
            new AuditLog($pdo, $clock),
            $this->session,
            $clock,
        ));
    }

    public function get(string $path, array $headers = []): Response
    {
        return $this->app->handle(new Request('GET', $path, headers: $headers, clientIp: $this->ip));
    }

    /** @param array<string,string> $fields */
    public function post(string $path, array $fields = []): Response
    {
        $fields['_csrf'] = (string) $this->session->get('_csrf');
        return $this->app->handle(new Request('POST', $path, post: $fields, headers: ['origin' => 'https://tutora.test'], clientIp: $this->ip));
    }

    /** @param array<string,mixed>|null $json */
    public function api(string $method, string $path, ?array $json = null, ?string $bearer = null, array $headers = []): Response
    {
        if ($json !== null) {
            $headers['content-type'] = 'application/json';
        }
        if ($bearer !== null) {
            $headers['authorization'] = 'Bearer ' . $bearer;
        }
        return $this->app->handle(new Request($method, $path, headers: $headers, body: $json === null ? '' : json_encode($json), clientIp: $this->ip));
    }

    public function signUpTutor(string $email): void
    {
        $this->get('/signup');
        $this->post('/signup', ['email' => $email, 'display_name' => 'T', 'password' => 'a long enough passphrase']);
        $page = $this->get('/login/enroll');
        preg_match('#<p class="secret"><code>([A-Z2-7 ]+)</code>#', $page->body, $m);
        $code = Totp::code(Base32::decode($m[1]), Totp::stepAt($this->clock->now()->getTimestamp()));
        $this->post('/login/enroll', ['code' => $code]);
        $this->get('/dashboard'); // fresh CSRF token after login
    }

    /** @return array<string,mixed> */
    public static function json(Response $r): array
    {
        return json_decode($r->body, true, 64, JSON_THROW_ON_ERROR);
    }
}
