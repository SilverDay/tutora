<?php

declare(strict_types=1);

namespace Tutora;

use PDO;
use Throwable;
use Tutora\Audit\AuditLog;
use Tutora\Auth\HibpPasswordChecker;
use Tutora\Auth\PasswordHasher;
use Tutora\Auth\PasswordPolicy;
use Tutora\Auth\SessionStore;
use Tutora\Auth\TutorAccounts;
use Tutora\Auth\TutorAuthService;
use Tutora\Controller\AuthController;
use Tutora\Database\ConnectionFactory;
use Tutora\Http\HttpException;
use Tutora\Http\Request;
use Tutora\Http\Response;
use Tutora\Http\Router;
use Tutora\Http\SecurityHeaders;
use Tutora\Security\Csrf;
use Tutora\Security\Logger;
use Tutora\Security\RateLimiter;
use Tutora\Security\SecretBox;
use Tutora\Support\Clock;
use Tutora\Support\SystemClock;
use Tutora\Tenant\TenantContext;
use Tutora\View\View;

/**
 * Composition root and request pipeline:
 * CSRF check (cookie-authenticated routes) -> route -> tutor auth gate -> handler,
 * with uniform error handling and security headers.
 */
final class App
{
    /**
     * Path prefixes authenticated by a bearer credential (participant API, internal hooks),
     * not by the session cookie — CSRF does not apply to them.
     */
    private const CSRF_EXEMPT_PREFIXES = ['/api/participant/'];

    private ?PDO $pdo = null;
    private ?TutorAuthService $auth = null;
    private readonly Router $router;
    private readonly View $view;
    private readonly Csrf $csrf;
    private readonly Logger $logger;

    public function __construct(
        private readonly Config $config,
        private readonly SessionStore $session,
        private readonly Clock $clock = new SystemClock(),
        ?PDO $pdo = null,
    ) {
        $this->pdo = $pdo;
        $this->logger = new Logger();
        $this->csrf = new Csrf($session, $config->list('ALLOWED_ORIGINS', $config->string('APP_BASE_URL', 'http://localhost')));
        $this->view = new View(__DIR__ . '/../templates');
        $this->router = new Router();
        $this->routes();
    }

    public function handle(Request $request): Response
    {
        try {
            if (!$this->isCsrfExempt($request->path)) {
                $this->csrf->verify($request);
            }
            $this->view->share('csrf', $this->csrf->token());
            $this->view->share('signedIn', false);
            $response = $this->router->dispatch($request);
        } catch (HttpException $e) {
            $response = $this->error($request, $e->status, $e->getMessage());
            foreach ($e->headers as $k => $v) {
                $response = $response->withHeader($k, $v);
            }
        } catch (Throwable $e) {
            $this->logger->error('Unhandled exception', ['class' => $e::class, 'message' => $e->getMessage(), 'at' => $e->getFile() . ':' . $e->getLine()]);
            $response = $this->error($request, 500, 'Something went wrong.');
        }
        return SecurityHeaders::apply($response, $this->config->list('REALTIME_ORIGINS'), $this->config->isProduction());
    }

    private function routes(): void
    {
        $auth = fn () => new AuthController($this->auth(), $this->view);
        $r = $this->router;

        $r->add('GET', '/', fn () => Response::redirect($this->auth()->currentTenant() ? '/dashboard' : '/login'));
        $r->add('GET', '/login', fn (Request $q) => $auth()->showLogin($q));
        $r->add('POST', '/login', fn (Request $q) => $auth()->login($q));
        $r->add('GET', '/signup', fn (Request $q) => $auth()->showSignup($q));
        $r->add('POST', '/signup', fn (Request $q) => $auth()->signup($q));
        $r->add('GET', '/login/mfa', fn (Request $q) => $auth()->showMfa($q));
        $r->add('POST', '/login/mfa', fn (Request $q) => $auth()->verifyMfa($q));
        $r->add('GET', '/login/enroll', fn (Request $q) => $auth()->showEnroll($q));
        $r->add('POST', '/login/enroll', fn (Request $q) => $auth()->confirmEnroll($q));
        $r->add('POST', '/logout', fn (Request $q) => $auth()->logout($q));

        $r->add('GET', '/dashboard', $this->tutor(fn (Request $q, TenantContext $t) => $this->view->render('dashboard', ['title' => 'Dashboard'])));
        $r->add('GET', '/account/password', $this->tutor(fn (Request $q, TenantContext $t) => $auth()->showPassword($q, $t)));
        $r->add('POST', '/account/password', $this->tutor(fn (Request $q, TenantContext $t) => $auth()->changePassword($q, $t)));
    }

    /**
     * Wraps a tutor-only handler: resolves the TenantContext from the fully authenticated
     * session or redirects to login. Handlers never read tenant ids from input.
     *
     * @param callable(Request,TenantContext):Response $handler
     * @return callable(Request):Response
     */
    private function tutor(callable $handler): callable
    {
        return function (Request $request) use ($handler): Response {
            $tenant = $this->auth()->currentTenant();
            if ($tenant === null) {
                return Response::redirect('/login');
            }
            $this->view->share('signedIn', true);
            return $handler($request, $tenant)->withHeader('Cache-Control', 'no-store');
        };
    }

    private function error(Request $request, int $status, string $message): Response
    {
        if (str_starts_with($request->path, '/api/')) {
            return Response::json(['error' => $message], $status);
        }
        try {
            return $this->view->render('error', ['title' => 'Error', 'status' => $status, 'message' => $message], $status);
        } catch (Throwable) {
            return new Response($status, 'Error', ['Content-Type' => 'text/plain; charset=utf-8']);
        }
    }

    private function isCsrfExempt(string $path): bool
    {
        foreach (self::CSRF_EXEMPT_PREFIXES as $p) {
            if (str_starts_with($path, $p)) {
                return true;
            }
        }
        return false;
    }

    public function pdo(): PDO
    {
        return $this->pdo ??= ConnectionFactory::fromConfig($this->config);
    }

    private function auth(): TutorAuthService
    {
        return $this->auth ??= new TutorAuthService(
            new TutorAccounts($this->pdo(), $this->clock),
            new PasswordHasher(),
            new PasswordPolicy(new HibpPasswordChecker(), $this->config->bool('HIBP_FAIL_OPEN', false)),
            new SecretBox($this->config->key('TOTP_ENCRYPTION_KEY')),
            new RateLimiter($this->pdo(), $this->clock),
            new AuditLog($this->pdo(), $this->clock),
            $this->session,
            $this->clock,
        );
    }

    /** Test seam: replace the auth service (e.g. with a stub breach checker). */
    public function withAuthService(TutorAuthService $auth): self
    {
        $this->auth = $auth;
        return $this;
    }
}
