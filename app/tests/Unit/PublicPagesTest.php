<?php

declare(strict_types=1);

namespace Tutora\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tutora\App;
use Tutora\Auth\ArraySessionStore;
use Tutora\Config;
use Tutora\Http\Request;
use Tutora\Http\Response;

/**
 * Public pages must render without a database (no PDO is given and none is configured, so any
 * database access would fail the request) and link the legal pages from every page.
 */
final class PublicPagesTest extends TestCase
{
    private function get(string $path, ?ArraySessionStore $session = null, array $env = []): Response
    {
        $config = Config::fromArray($env + [
            'APP_ENV' => 'production',
            'APP_BASE_URL' => 'https://tutora.test',
            'ALLOWED_ORIGINS' => 'https://tutora.test',
            'DB_HOST' => '192.0.2.1', // TEST-NET-1: never reachable, in case a page tried to connect
        ]);
        return (new App($config, $session ?? new ArraySessionStore()))->handle(new Request('GET', $path));
    }

    /** @return iterable<string,array{string,string}> */
    public static function pages(): iterable
    {
        yield 'home' => ['/', 'Run live workshops everyone can join in seconds'];
        yield 'features' => ['/features', '<h1>Features</h1>'];
        yield 'about' => ['/about', '<h1>About Tutora</h1>'];
        yield 'faq' => ['/faq', '<h1>Frequently asked questions</h1>'];
        yield 'privacy' => ['/privacy', '<h1>Privacy policy</h1>'];
        yield 'terms' => ['/terms', '<h1>Terms of use</h1>'];
        yield 'cookies' => ['/cookies', '<h1>Cookies and browser storage</h1>'];
        yield 'imprint' => ['/imprint', '<h1>Imprint</h1>'];
    }

    #[DataProvider('pages')]
    public function testRendersWithoutDatabase(string $path, string $marker): void
    {
        $r = $this->get($path);
        self::assertSame(200, $r->status, $r->body);
        self::assertStringContainsString($marker, $r->body);
        foreach (['/privacy', '/terms', '/cookies', '/imprint'] as $legal) {
            self::assertStringContainsString('href="' . $legal . '"', $r->body, "footer link $legal missing on $path");
        }
        self::assertStringContainsString("script-src 'self'", $r->headers['Content-Security-Policy'] ?? '');
        self::assertStringNotContainsString('style=', $r->body, 'inline styles are blocked by the CSP');
    }

    public function testLegalPlaceholdersAreMarked(): void
    {
        foreach (['/privacy', '/terms', '/cookies', '/imprint'] as $path) {
            self::assertStringContainsString('class="placeholder-notice"', $this->get($path)->body, $path);
        }
    }

    public function testVisitorSeesSignupAndSignIn(): void
    {
        $body = $this->get('/')->body;
        self::assertStringContainsString('href="/signup"', $body);
        self::assertStringContainsString('href="/login"', $body);
        self::assertStringNotContainsString('href="/dashboard"', $body);
    }

    public function testSignedInTutorGetsDashboardLinkNotRedirect(): void
    {
        $session = new ArraySessionStore();
        $session->set('auth_tenant_id', 7);
        $session->set('auth_stage', 'full');
        $r = $this->get('/', $session);
        self::assertSame(200, $r->status);
        self::assertStringContainsString('href="/dashboard"', $r->body);
        self::assertStringContainsString('action="/logout"', $r->body);
        self::assertStringNotContainsString('href="/signup"', $r->body);
    }

    public function testPartialSignInIsNotTreatedAsSignedIn(): void
    {
        $session = new ArraySessionStore();
        $session->set('auth_tenant_id', 7);
        $session->set('auth_stage', 'mfa_pending'); // password done, second factor pending
        self::assertStringNotContainsString('href="/dashboard"', $this->get('/', $session)->body);
    }

    public function testConfiguredValuesAreShown(): void
    {
        $body = $this->get('/faq', null, ['RETENTION_DAYS_DEFAULT' => '14', 'SESSION_MAX_LIVE_HOURS' => '12', 'UPLOAD_MAX_BYTES' => (string) (20 * 1048576)])->body;
        self::assertStringContainsString('14 days after the session ended', $body);
        self::assertStringContainsString('automatically 12 hours after', $body);
        self::assertStringContainsString('up to 20 MB', $body);
    }
}
