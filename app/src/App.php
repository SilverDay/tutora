<?php

declare(strict_types=1);

namespace Tutora;

use PDO;
use Throwable;
use Tutora\Activity\BlockStates;
use Tutora\Activity\QuizService;
use Tutora\Activity\SubmissionService;
use Tutora\Activity\WallService;
use Tutora\Audit\AuditLog;
use Tutora\Auth\HibpPasswordChecker;
use Tutora\Auth\PasswordHasher;
use Tutora\Auth\PasswordPolicy;
use Tutora\Auth\SessionStore;
use Tutora\Auth\SignupVerification;
use Tutora\Auth\AccountNotices;
use Tutora\Auth\AdminMfaReset;
use Tutora\Auth\RecoveryCodes;
use Tutora\Ai\StubSummaryProvider;
use Tutora\Ai\SummaryProvider;
use Tutora\Ai\SummaryService;
use Tutora\Auth\TutorRealtimeRevoker;
use Tutora\Mail\FileMailer;
use Tutora\Mail\Mailer;
use Tutora\Mail\SmtpMailer;
use Tutora\Auth\TutorAccounts;
use Tutora\Auth\TutorAuthService;
use Tutora\Controller\AuthController;
use Tutora\Controller\ParticipantApiController;
use Tutora\Controller\SessionController;
use Tutora\Controller\SlideController;
use Tutora\Slides\SlideImportService;
use Tutora\Slides\SlideStorage;
use Tutora\Controller\WorkshopController;
use Tutora\Participant\ParticipantService;
use Tutora\Realtime\Broadcaster;
use Tutora\Realtime\NullBroadcaster;
use Tutora\Realtime\RelayBroadcaster;
use Tutora\Security\HmacToken;
use Tutora\Session\SessionService;
use Tutora\Tenant\TenantDb;
use Tutora\Workshop\WorkshopRepository;
use Tutora\Whiteboard\NullWhiteboardModeration;
use Tutora\Whiteboard\SidecarWhiteboardModeration;
use Tutora\Whiteboard\SnapshotService;
use Tutora\Whiteboard\WhiteboardModeration;
use Tutora\Whiteboard\WhiteboardService;
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

    /** Public pages: path => [template, title]. */
    public const PUBLIC_PAGES = [
        '/' => ['pages/home', 'Live workshops'],
        '/features' => ['pages/features', 'Features'],
        '/about' => ['pages/about', 'About'],
        '/faq' => ['pages/faq', 'FAQ'],
        '/privacy' => ['pages/privacy', 'Privacy policy'],
        '/terms' => ['pages/terms', 'Terms of use'],
        '/cookies' => ['pages/cookies', 'Cookies and storage'],
        '/imprint' => ['pages/imprint', 'Imprint'],
    ];

    private ?PDO $pdo = null;
    private ?TutorAuthService $auth = null;
    private ?Broadcaster $broadcaster = null;
    private ?WhiteboardModeration $whiteboardModeration = null;
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
            $this->view->share('realtimeUrl', $this->realtimeUrl());
            $this->view->share('whiteboardUrl', $this->whiteboardUrl());
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
        return SecurityHeaders::apply($response, array_values(array_unique([self::originOf($this->realtimeUrl()), self::originOf($this->whiteboardUrl())])), $this->config->isProduction());
    }

    private function routes(): void
    {
        $auth = fn () => new AuthController($this->auth(), $this->view);
        $r = $this->router;

        // public pages (no database access, so they stay reachable when the database is down)
        foreach (self::PUBLIC_PAGES as $path => [$template, $title]) {
            $r->add('GET', $path, fn () => $this->publicPage($template, $title));
        }
        $r->add('GET', '/login', fn (Request $q) => $auth()->showLogin($q));
        $r->add('POST', '/login', fn (Request $q) => $auth()->login($q));
        $r->add('GET', '/signup', fn (Request $q) => $auth()->showSignup($q));
        $r->add('POST', '/signup', fn (Request $q) => $auth()->signup($q));
        $r->add('GET', '/verify-email', fn (Request $q) => $auth()->showVerify($q));
        $r->add('POST', '/verify-email', fn (Request $q) => $auth()->verify($q));
        $r->add('GET', '/login/mfa', fn (Request $q) => $auth()->showMfa($q));
        $r->add('POST', '/login/mfa', fn (Request $q) => $auth()->verifyMfa($q));
        $r->add('GET', '/login/enroll', fn (Request $q) => $auth()->showEnroll($q));
        $r->add('POST', '/login/enroll', fn (Request $q) => $auth()->confirmEnroll($q));
        $r->add('POST', '/logout', fn (Request $q) => $auth()->logout($q));

        // tutor: workshops & sessions
        $ws = fn (TenantContext $t) => new WorkshopController(new WorkshopRepository($this->tenantDb($t), $this->clock), $this->sessionService($t), new AuditLog($this->pdo(), $this->clock), $this->view, $this->slideImports($t));
        $sl = fn () => new SlideController($this->storage());
        $ss = fn (TenantContext $t) => new SessionController(
            $this->sessionService($t), $this->relayTokens(), $this->view, $this->tenantDb($t),
            $this->submissions(), $this->wall(), $this->quiz(), $this->blockStates(),
            $this->whiteboardService(), $this->whiteboardModeration(), $this->snapshots($t),
            $this->slideImports($t), $this->summaries(), new AuditLog($this->pdo(), $this->clock),
        );
        $r->add('GET', '/dashboard', $this->tutor(fn (Request $q, TenantContext $t) => $ws($t)->dashboard($q, $t)));
        $r->add('POST', '/workshops', $this->tutor(fn (Request $q, TenantContext $t) => $ws($t)->create($q, $t)));
        $r->add('GET', '/workshops/{id:\d+}', $this->tutor(fn (Request $q, TenantContext $t) => $ws($t)->show($q, $t)));
        $r->add('POST', '/workshops/{id:\d+}', $this->tutor(fn (Request $q, TenantContext $t) => $ws($t)->update($q, $t)));
        $r->add('POST', '/workshops/{id:\d+}/delete', $this->tutor(fn (Request $q, TenantContext $t) => $ws($t)->delete($q, $t)));
        $r->add('POST', '/workshops/{id:\d+}/blocks', $this->tutor(fn (Request $q, TenantContext $t) => $ws($t)->addBlock($q, $t)));
        $r->add('POST', '/workshops/{id:\d+}/slides', $this->tutor(fn (Request $q, TenantContext $t) => $sl()->upload(
            $q, $this->slideImports($t), fn (array $errors) => $ws($t)->show($q, $t, array_values(array_map('strval', $errors)), 422),
        )));
        $r->add('POST', '/workshops/{id:\d+}/slides/{import:\d+}/add-all', $this->tutor(fn (Request $q, TenantContext $t) => $sl()->addAll(
            $q, $this->slideImports($t), new WorkshopRepository($this->tenantDb($t), $this->clock),
        )));
        $r->add('GET', '/slides/{asset:\d+}', $this->tutor(fn (Request $q, TenantContext $t) => $sl()->tutorImage($q, $t, $this->tenantDb($t))));
        $r->add('POST', '/workshops/{id:\d+}/sessions', $this->tutor(fn (Request $q, TenantContext $t) => $ws($t)->startSession($q, $t)));
        $r->add('POST', '/blocks/{block:\d+}', $this->tutor(fn (Request $q, TenantContext $t) => $ws($t)->updateBlock($q, $t)));
        $r->add('POST', '/blocks/{block:\d+}/move', $this->tutor(fn (Request $q, TenantContext $t) => $ws($t)->moveBlock($q, $t)));
        $r->add('POST', '/blocks/{block:\d+}/delete', $this->tutor(fn (Request $q, TenantContext $t) => $ws($t)->deleteBlock($q, $t)));
        $r->add('GET', '/sessions/{id:\d+}', $this->tutor(fn (Request $q, TenantContext $t) => $ss($t)->show($q, $t)));
        $r->add('POST', '/sessions/{id:\d+}/navigate', $this->tutor(fn (Request $q, TenantContext $t) => $ss($t)->navigate($q, $t)));
        $r->add('POST', '/sessions/{id:\d+}/end', $this->tutor(fn (Request $q, TenantContext $t) => $ss($t)->end($q, $t)));
        $r->add('POST', '/sessions/{id:\d+}/export', $this->tutor(fn (Request $q, TenantContext $t) => $ss($t)->export($q, $t)));
        $r->add('POST', '/sessions/{id:\d+}/delete', $this->tutor(fn (Request $q, TenantContext $t) => $ss($t)->delete($q, $t)));
        $r->add('POST', '/sessions/{id:\d+}/quiz/start', $this->tutor(fn (Request $q, TenantContext $t) => $ss($t)->quizStart($q, $t)));
        $r->add('POST', '/sessions/{id:\d+}/quiz/reveal', $this->tutor(fn (Request $q, TenantContext $t) => $ss($t)->quizReveal($q, $t)));
        $r->add('POST', '/sessions/{id:\d+}/wall/cards', $this->tutor(fn (Request $q, TenantContext $t) => $ss($t)->wallAdd($q, $t)));
        $r->add('POST', '/sessions/{id:\d+}/wall/cards/{card:\d+}/delete', $this->tutor(fn (Request $q, TenantContext $t) => $ss($t)->wallDelete($q, $t)));
        $r->add('POST', '/sessions/{id:\d+}/moderation/remove-actor', $this->tutor(fn (Request $q, TenantContext $t) => $ss($t)->removeActor($q, $t)));
        $r->add('POST', '/sessions/{id:\d+}/ai/summary', $this->tutor(fn (Request $q, TenantContext $t) => $ss($t)->generateSummary($q, $t)));
        $r->add('POST', '/sessions/{id:\d+}/ai/summary/share', $this->tutor(fn (Request $q, TenantContext $t) => $ss($t)->shareSummary($q, $t)));
        $r->add('POST', '/sessions/{id:\d+}/results/reveal', $this->tutor(fn (Request $q, TenantContext $t) => $ss($t)->revealResults($q, $t)));
        $r->add('POST', '/sessions/{id:\d+}/whiteboard/clear', $this->tutor(fn (Request $q, TenantContext $t) => $ss($t)->clearBoard($q, $t)));
        $r->add('GET', '/snapshots/{snapshot:\d+}', $this->tutor(fn (Request $q, TenantContext $t) => $ss($t)->snapshotImage($q, $t)));
        $r->add('POST', '/api/tutor/sessions/{id:\d+}/blocks/{block:\d+}/whiteboard-token', $this->tutor(fn (Request $q, TenantContext $t) => $ss($t)->whiteboardToken($q, $t)));
        $r->add('POST', '/api/tutor/sessions/{id:\d+}/blocks/{block:\d+}/snapshots', $this->tutor(fn (Request $q, TenantContext $t) => $ss($t)->uploadSnapshot($q, $t)));
        $r->add('GET', '/api/tutor/sessions/{id:\d+}/state', $this->tutor(fn (Request $q, TenantContext $t) => $ss($t)->state($q, $t)));
        $r->add('POST', '/api/tutor/sessions/{id:\d+}/connection-token', $this->tutor(fn (Request $q, TenantContext $t) => $ss($t)->connectionToken($q, $t)));

        // participant (anonymous, bearer credential)
        $p = fn () => new ParticipantApiController($this->participants(), $this->submissions(), $this->wall(), $this->quiz(), $this->blockStates(), $this->whiteboardService());
        $r->add('GET', '/join', fn () => $this->view->render('participant/join', ['title' => 'Join session'], 200, 'participant/layout'));
        $r->add('POST', '/api/participant/join', fn (Request $q) => $p()->join($q));
        $r->add('POST', '/api/participant/resume', fn (Request $q) => $p()->resume($q));
        $r->add('POST', '/api/participant/sessions/{id:\d+}/presence', fn (Request $q) => $p()->presence($q));
        $r->add('GET', '/api/participant/sessions/{id:\d+}/state', fn (Request $q) => $p()->state($q));
        $r->add('POST', '/api/participant/sessions/{id:\d+}/connection-token', fn (Request $q) => $p()->connectionToken($q));
        $r->add('GET', '/api/participant/sessions/{id:\d+}/slides/{asset:\d+}', fn (Request $q) => $sl()->participantImage($q, $this->participants(), $this->pdo()));
        $pb = '/api/participant/sessions/{id:\d+}/blocks/{block:\d+}';
        $r->add('POST', $pb . '/submission', fn (Request $q) => $p()->submit($q));
        $r->add('POST', $pb . '/whiteboard-token', fn (Request $q) => $p()->whiteboardToken($q));
        $r->add('POST', $pb . '/wall/cards', fn (Request $q) => $p()->addCard($q));
        $r->add('POST', $pb . '/wall/cards/{card:\d+}/move', fn (Request $q) => $p()->moveCard($q));
        $r->add('POST', $pb . '/wall/cards/{card:\d+}/edit', fn (Request $q) => $p()->editCard($q));
        $r->add('POST', $pb . '/wall/cards/{card:\d+}/delete', fn (Request $q) => $p()->deleteCard($q));
        $r->add('POST', $pb . '/quiz/{question:[A-Za-z0-9_-]+}/open', fn (Request $q) => $p()->openQuestion($q));
        $r->add('POST', $pb . '/quiz/{question:[A-Za-z0-9_-]+}/answer', fn (Request $q) => $p()->answer($q));
        $r->add('GET', '/account/password', $this->tutor(fn (Request $q, TenantContext $t) => $auth()->showPassword($q, $t)));
        $r->add('POST', '/account/password', $this->tutor(fn (Request $q, TenantContext $t) => $auth()->changePassword($q, $t)));
        $r->add('POST', '/account/recovery-codes', $this->tutor(fn (Request $q, TenantContext $t) => $auth()->regenerateRecoveryCodes($q, $t)));
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
                if (str_starts_with($request->path, '/api/')) {
                    throw new HttpException(401, 'Not signed in');
                }
                return Response::redirect('/login');
            }
            $this->view->share('signedIn', true);
            $this->view->share('maxLiveHours', $this->config->int('SESSION_MAX_LIVE_HOURS', 24));
            return $handler($request, $tenant)->withHeader('Cache-Control', 'no-store');
        };
    }

    private function publicPage(string $template, string $title): Response
    {
        $this->view->share('signedIn', TutorAuthService::hasFullSession($this->session));
        return $this->view->render($template, [
            'title' => $title,
            'retentionDays' => $this->config->int('RETENTION_DAYS_DEFAULT', 30),
            'maxLiveHours' => $this->config->int('SESSION_MAX_LIVE_HOURS', 24),
            'uploadMaxMb' => intdiv($this->config->int('UPLOAD_MAX_BYTES', 50 * 1048576), 1048576),
        ]);
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

    /** Public WebSocket URL of the relay (default: same host, path /ws, proxied by Apache). */
    private function realtimeUrl(): string
    {
        $url = $this->config->string('REALTIME_URL', '');
        if ($url === '') {
            $base = $this->config->string('APP_BASE_URL', 'http://localhost');
            $url = preg_replace('#^http#i', 'ws', rtrim($base, '/')) . '/ws';
        }
        return $url;
    }

    /** Public WebSocket URL of the whiteboard sidecar (default: same host, path /wb). */
    private function whiteboardUrl(): string
    {
        $url = $this->config->string('WHITEBOARD_URL', '');
        if ($url === '') {
            $base = $this->config->string('APP_BASE_URL', 'http://localhost');
            $url = preg_replace('#^http#i', 'ws', rtrim($base, '/')) . '/wb';
        }
        return $url;
    }

    private static function originOf(string $url): string
    {
        $p = parse_url($url);
        return ($p['scheme'] ?? 'wss') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
    }

    /** Test seam. */
    public function withBroadcaster(Broadcaster $b): self
    {
        $this->broadcaster = $b;
        return $this;
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

    private function tenantDb(TenantContext $t): TenantDb
    {
        return new TenantDb($this->pdo(), $t);
    }

    private function sessionService(TenantContext $t): SessionService
    {
        return new SessionService($this->tenantDb($t), $this->clock, $this->broadcaster(), new AuditLog($this->pdo(), $this->clock),
            $this->config->int('RETENTION_DAYS_DEFAULT', 30), $this->whiteboardModeration());
    }

    private function whiteboardService(): WhiteboardService
    {
        return new WhiteboardService($this->pdo(), new HmacToken($this->config->key('WHITEBOARD_TOKEN_KEY'), HmacToken::AUD_WHITEBOARD, $this->clock));
    }

    private function whiteboardModeration(): WhiteboardModeration
    {
        if ($this->whiteboardModeration === null) {
            $url = $this->config->string('WHITEBOARD_INTERNAL_URL', '');
            $this->whiteboardModeration = $url === ''
                ? new NullWhiteboardModeration()
                : new SidecarWhiteboardModeration($url, $this->config->string('WHITEBOARD_INTERNAL_SECRET'), $this->logger);
        }
        return $this->whiteboardModeration;
    }

    private function snapshots(TenantContext $t): SnapshotService
    {
        return new SnapshotService($this->tenantDb($t), $this->config->string('STORAGE_PATH'), new RateLimiter($this->pdo(), $this->clock), $this->clock);
    }

    /** Test seam. */
    public function withWhiteboardModeration(WhiteboardModeration $m): self
    {
        $this->whiteboardModeration = $m;
        return $this;
    }

    private function relayTokens(): HmacToken
    {
        return new HmacToken($this->config->key('RELAY_TOKEN_KEY'), HmacToken::AUD_RELAY, $this->clock);
    }

    private function participants(): ParticipantService
    {
        return new ParticipantService(
            $this->pdo(),
            $this->clock,
            new HmacToken($this->config->key('PARTICIPANT_CREDENTIAL_KEY'), HmacToken::AUD_PARTICIPANT, $this->clock),
            $this->relayTokens(),
            new RateLimiter($this->pdo(), $this->clock),
        );
    }

    private function storage(): SlideStorage
    {
        return new SlideStorage($this->config->string('STORAGE_PATH'));
    }

    private function slideImports(TenantContext $t): SlideImportService
    {
        return new SlideImportService($this->tenantDb($t), $this->storage(), new RateLimiter($this->pdo(), $this->clock), $this->clock,
            $this->config->int('UPLOAD_MAX_BYTES', 50 * 1048576));
    }

    private function submissions(): SubmissionService
    {
        return new SubmissionService($this->pdo(), $this->clock, $this->broadcaster(), $this->participants());
    }

    private function wall(): WallService
    {
        return new WallService($this->pdo(), $this->clock, $this->broadcaster(), $this->participants());
    }

    private function quiz(): QuizService
    {
        return new QuizService($this->pdo(), $this->clock, $this->broadcaster(), $this->participants());
    }

    private function blockStates(): BlockStates
    {
        return new BlockStates($this->submissions(), $this->wall(), $this->quiz(), $this->summaries());
    }

    private function summaries(): SummaryService
    {
        return new SummaryService(
            $this->pdo(), $this->clock, $this->summaryProvider(), $this->submissions(), $this->broadcaster(),
            new AuditLog($this->pdo(), $this->clock), $this->logger,
            $this->config->int('AI_QUOTA_CALLS_PER_MONTH', 200),
            $this->config->int('AI_QUOTA_TOKENS_PER_MONTH', 500000),
            $this->config->int('AI_CALLS_PER_SESSION_PER_DAY', 10),
            $this->config->int('AI_MAX_INPUT_CHARS', 60000),
        );
    }

    /** AI stays disabled until a provider with a DPA is configured (owner decision 6). */
    private function summaryProvider(): ?SummaryProvider
    {
        $provider = $this->config->string('AI_PROVIDER', '');
        return match ($provider) {
            '' => null,
            'stub' => $this->config->isProduction()
                ? throw new \RuntimeException('AI_PROVIDER=stub is for development only')
                : new StubSummaryProvider(),
            default => throw new \RuntimeException('Unknown AI_PROVIDER'),
        };
    }

    private function broadcaster(): Broadcaster
    {
        if ($this->broadcaster === null) {
            $url = $this->config->string('RELAY_INTERNAL_URL', '');
            $this->broadcaster = $url === ''
                ? new NullBroadcaster()
                : new RelayBroadcaster($url, $this->config->string('RELAY_INTERNAL_SECRET'), $this->logger);
        }
        return $this->broadcaster;
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
            new SignupVerification($this->pdo(), $this->mailer(), $this->clock, $this->logger, $this->config->string('APP_BASE_URL')),
            new RecoveryCodes($this->pdo(), $this->clock),
            new AccountNotices($this->mailer(), $this->logger, $this->config->string('APP_BASE_URL')),
            $this->realtimeRevoker(),
        );
    }

    private function realtimeRevoker(): TutorRealtimeRevoker
    {
        return new TutorRealtimeRevoker($this->pdo(), $this->broadcaster(), $this->whiteboardModeration());
    }

    /** For bin/purge.php. */
    public static function retentionPurger(Config $config): \Tutora\Retention\RetentionPurger
    {
        $app = new self($config, new \Tutora\Auth\ArraySessionStore());
        return new \Tutora\Retention\RetentionPurger(
            $app->pdo(),
            $app->clock,
            fn (TenantContext $t): array => [$app->sessionService($t), $app->snapshots($t), $app->slideImports($t)],
            $app->logger,
            new AuditLog($app->pdo(), $app->clock),
            $config->int('SESSION_MAX_LIVE_HOURS', 24),
        );
    }

    /** For bin/admin-reset-mfa.php. */
    public static function adminMfaReset(Config $config): AdminMfaReset
    {
        $app = new self($config, new \Tutora\Auth\ArraySessionStore());
        return new AdminMfaReset(
            new TutorAccounts($app->pdo(), $app->clock),
            new RecoveryCodes($app->pdo(), $app->clock),
            new AuditLog($app->pdo(), $app->clock),
            new AccountNotices($app->mailer(), $app->logger, $config->string('APP_BASE_URL')),
            $app->realtimeRevoker(),
        );
    }

    /** SMTP submission with mandatory STARTTLS (default), or a dev-only file outbox. */
    private function mailer(): Mailer
    {
        if ($this->config->string('MAIL_DRIVER', 'smtp') === 'file') {
            return new FileMailer($this->config->string('STORAGE_PATH') . '/mail-outbox', $this->config->string('SMTP_FROM'), $this->config->isProduction(), $this->clock);
        }
        $host = parse_url($this->config->string('APP_BASE_URL'), PHP_URL_HOST);
        return new SmtpMailer(
            $this->config->string('SMTP_HOST'),
            $this->config->int('SMTP_PORT', 587),
            $this->config->string('SMTP_USERNAME', ''),
            $this->config->string('SMTP_PASSWORD', ''),
            $this->config->string('SMTP_FROM'),
            $this->config->string('SMTP_FROM_NAME', 'Tutora'),
            $this->config->string('SMTP_HELO', is_string($host) ? $host : 'localhost'),
            $this->config->int('SMTP_TIMEOUT', 15),
            $this->config->string('SMTP_CAFILE', '') === '' ? null : $this->config->string('SMTP_CAFILE'),
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
