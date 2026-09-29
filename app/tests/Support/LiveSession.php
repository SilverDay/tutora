<?php

declare(strict_types=1);

namespace Tutora\Tests\Support;

use PDO;
use Tutora\Audit\AuditLog;
use Tutora\Block\BlockType;
use Tutora\Participant\ParticipantContext;
use Tutora\Participant\ParticipantService;
use Tutora\Realtime\NullBroadcaster;
use Tutora\Security\HmacToken;
use Tutora\Security\RateLimiter;
use Tutora\Session\SessionService;
use Tutora\Support\FrozenClock;
use Tutora\Tenant\TenantContext;
use Tutora\Tenant\TenantDb;
use Tutora\Workshop\WorkshopRepository;

/** A running session for one tenant with the given blocks, plus helpers to join participants. */
final class LiveSession
{
    public int $sessionId;
    /** @var list<int> session block ids in order */
    public array $blocks = [];
    public TenantDb $tenantDb;
    public SessionService $sessions;
    public ParticipantService $participants;
    private int $ipCounter = 1;

    /** @param list<array{BlockType, array<string,mixed>}> $blockDefs */
    public function __construct(
        public PDO $pdo,
        public FrozenClock $clock,
        public NullBroadcaster $broadcaster,
        array $blockDefs,
        string $tenantEmail = 'tutor@example.org',
    ) {
        $tenant = Fixtures::tenant($pdo, $tenantEmail);
        $this->tenantDb = new TenantDb($pdo, TenantContext::forAuthenticatedTutor($tenant));
        $w = new WorkshopRepository($this->tenantDb, $clock);
        $wid = $w->create('Fixture', null);
        foreach ($blockDefs as [$type, $config]) {
            $w->addBlock($wid, $type, $config);
        }
        $this->sessions = new SessionService($this->tenantDb, $clock, $broadcaster, new AuditLog($pdo, $clock), 30);
        $this->sessionId = (int) $this->sessions->start($wid);
        $this->blocks = array_map(static fn ($b) => (int) $b['id'], $this->sessions->blocks($this->sessionId));
        $this->participants = new ParticipantService(
            $pdo, $clock,
            new HmacToken(str_repeat('p', 32), HmacToken::AUD_PARTICIPANT, $clock),
            new HmacToken(str_repeat('r', 32), HmacToken::AUD_RELAY, $clock),
            new RateLimiter($pdo, $clock),
        );
    }

    public function join(): ParticipantContext
    {
        $code = (string) $this->sessions->find($this->sessionId)['join_code'];
        $j = $this->participants->join($code, null, '198.51.100.' . ($this->ipCounter++ % 250));
        return $this->participants->authenticate($j['credential'], $this->sessionId);
    }

    public function goTo(int $index): void
    {
        $this->sessions->goToBlock($this->sessionId, $this->blocks[$index]);
    }
}
