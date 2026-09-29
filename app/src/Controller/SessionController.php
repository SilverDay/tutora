<?php

declare(strict_types=1);

namespace Tutora\Controller;

use Tutora\Activity\AnswerRejected;
use Tutora\Activity\BlockNotOpen;
use Tutora\Activity\BlockStates;
use Tutora\Activity\QuizService;
use Tutora\Activity\SubmissionService;
use Tutora\Activity\WallService;
use Tutora\Block\BlockType;
use Tutora\Http\HttpException;
use Tutora\Http\Request;
use Tutora\Http\Response;
use Tutora\Security\HmacToken;
use Tutora\Session\SessionService;
use Tutora\Support\ValidationException;
use Tutora\Tenant\TenantContext;
use Tutora\Tenant\TenantDb;
use Tutora\Whiteboard\SnapshotService;
use Tutora\Whiteboard\WhiteboardModeration;
use Tutora\Whiteboard\WhiteboardService;
use Tutora\Slides\SlideImportService;
use Tutora\View\View;

/** Tutor live-session console and tutor-side JSON API. */
final class SessionController
{
    public function __construct(
        private readonly SessionService $sessions,
        private readonly HmacToken $relayTokens,
        private readonly View $view,
        private readonly TenantDb $tenantDb,
        private readonly SubmissionService $submissions,
        private readonly WallService $wall,
        private readonly QuizService $quiz,
        private readonly BlockStates $states,
        private readonly WhiteboardService $whiteboard,
        private readonly WhiteboardModeration $whiteboardModeration,
        private readonly SnapshotService $snapshots,
        private readonly SlideImportService $slides,
    ) {
    }

    public function whiteboardToken(Request $r, TenantContext $t): Response
    {
        return Response::json($this->whiteboard->tutorToken($this->tenantDb, $r->intParam('id'), $r->intParam('block'))
            ?? throw new HttpException(404, 'Not found'));
    }

    public function clearBoard(Request $r, TenantContext $t): Response
    {
        $id = $r->intParam('id');
        return $this->act($id, function () use ($r, $id): bool {
            $block = self::intInput($r, 'block');
            if ($this->tenantDb->one("SELECT b.id FROM session_blocks b JOIN sessions s ON s.id = b.session_id
                WHERE s.id = :sid AND s.tenant_id = :tenant_id AND b.id = :bid AND b.block_type IN ('whiteboard', 'annotate')", ['sid' => $id, 'bid' => $block]) === null) {
                return false;
            }
            $this->whiteboardModeration->clear($id, $block);
            return true;
        });
    }

    /** Shows a block's hidden results to participants (owner decision 11). */
    public function revealResults(Request $r, TenantContext $t): Response
    {
        $id = $r->intParam('id');
        return $this->act($id, fn (): bool => $this->submissions->revealResults($this->tenantDb, $id, self::intInput($r, 'block')));
    }

    /** Client-side captured PNG of the rendered board (raw image/png body). */
    public function uploadSnapshot(Request $r, TenantContext $t): Response
    {
        if (strtolower((string) $r->header('content-type')) !== 'image/png') {
            throw new HttpException(415, 'Expected image/png');
        }
        try {
            $id = $this->snapshots->store($r->intParam('id'), $r->intParam('block'), $r->body);
        } catch (ValidationException $e) {
            return Response::json(['error' => 'validation', 'errors' => $e->errors], 422);
        }
        return $id === null ? throw new HttpException(404, 'Not found') : Response::json(['id' => $id], 201);
    }

    public function snapshotImage(Request $r, TenantContext $t): Response
    {
        $path = $this->snapshots->path($r->intParam('snapshot')) ?? throw new HttpException(404, 'Not found');
        return new Response(200, (string) file_get_contents($path), [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'inline; filename="whiteboard.png"',
            'Cache-Control' => 'private, max-age=3600',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function show(Request $r, TenantContext $t): Response
    {
        $id = $r->intParam('id');
        $state = $this->fullState($id) ?? throw new HttpException(404, 'Not found');
        return $this->view->render('sessions/show', [
            'title' => 'Live session',
            'session' => $this->sessions->find($id),
            'state' => $state,
            'blocks' => $this->sessions->blocks($id),
            'snapshots' => $this->snapshots->list($id),
            'errors' => self::errorMessages($r),
        ]);
    }

    public function navigate(Request $r, TenantContext $t): Response
    {
        $id = $r->intParam('id');
        $target = $r->input('session_block_id');
        $ok = $target !== null && ctype_digit($target)
            ? $this->sessions->goToBlock($id, (int) $target)
            : $this->sessions->step($id, $r->input('direction') === 'prev' ? -1 : 1);
        if ($ok === null && $this->sessions->find($id) === null) {
            throw new HttpException(404, 'Not found');
        }
        return Response::redirect('/sessions/' . $id);
    }

    public function end(Request $r, TenantContext $t): Response
    {
        $id = $r->intParam('id');
        if (!$this->sessions->end($id) && $this->sessions->find($id) === null) {
            throw new HttpException(404, 'Not found');
        }
        return Response::redirect('/sessions/' . $id);
    }

    public function delete(Request $r, TenantContext $t): Response
    {
        $this->snapshots->deleteSessionFiles($r->intParam('id'));
        if (!$this->sessions->delete($r->intParam('id'), $r->clientIp)) {
            throw new HttpException(404, 'Not found');
        }
        // slide images of deleted workshops that only this session still used
        $this->slides->collectOrphans();
        return Response::redirect('/dashboard');
    }

    public function state(Request $r, TenantContext $t): Response
    {
        return Response::json($this->fullState($r->intParam('id')) ?? throw new HttpException(404, 'Not found'));
    }

    /** Fixed, user-safe messages for redirect-after-POST errors (never reflected from input). */
    private const ERRORS = [
        'not_open' => 'That action is not possible for the current block.',
        'invalid' => 'The request was not valid.',
        'quiz_open' => 'Reveal the open question before starting another, and each question can only be run once.',
    ];

    public function quizStart(Request $r, TenantContext $t): Response
    {
        $id = $r->intParam('id');
        return $this->act($id, function () use ($r, $id): bool {
            try {
                return $this->quiz->startQuestion($this->tenantDb, $id, self::intInput($r, 'block'), (string) $r->input('question'));
            } catch (ValidationException) {
                throw new ActionFailed('quiz_open');
            }
        });
    }

    public function quizReveal(Request $r, TenantContext $t): Response
    {
        $id = $r->intParam('id');
        return $this->act($id, function () use ($r, $id): bool {
            // revealing an already revealed question is a harmless no-op
            $this->quiz->reveal($this->tenantDb, $id, self::intInput($r, 'block'), (string) $r->input('question'));
            return true;
        });
    }

    public function wallAdd(Request $r, TenantContext $t): Response
    {
        $id = $r->intParam('id');
        return $this->act($id, fn () => $this->wall->tutorAddCard($this->tenantDb, $id, self::intInput($r, 'block'), $r->input('text'), $r->input('column_id')) !== null);
    }

    public function wallDelete(Request $r, TenantContext $t): Response
    {
        $id = $r->intParam('id');
        return $this->act($id, fn () => $this->wall->tutorDeleteCard($this->tenantDb, $id, $r->intParam('card')));
    }

    /** Moderation: remove one participant's submissions and wall cards (by opaque actor id). */
    public function removeActor(Request $r, TenantContext $t): Response
    {
        $id = $r->intParam('id');
        return $this->act($id, function () use ($r, $id): bool {
            $actor = (string) $r->input('actor');
            $this->submissions->removeActor($this->tenantDb, $id, $actor);
            $this->wall->removeActor($this->tenantDb, $id, $actor);
            $this->whiteboardModeration->removeActor($id, $actor);
            return true;
        });
    }

    /** @param callable():bool $fn */
    private function act(int $sessionId, callable $fn): Response
    {
        if ($this->sessions->find($sessionId) === null) {
            throw new HttpException(404, 'Not found');
        }
        $error = null;
        try {
            if (!$fn()) {
                $error = 'invalid';
            }
        } catch (ActionFailed $e) {
            $error = $e->getMessage();
        } catch (BlockNotOpen|AnswerRejected) {
            $error = 'not_open';
        } catch (ValidationException) {
            $error = 'invalid';
        }
        return Response::redirect('/sessions/' . $sessionId . ($error === null ? '' : '?error=' . $error));
    }

    /** @return list<string> */
    private static function errorMessages(Request $r): array
    {
        $key = $r->query['error'] ?? null;
        return is_string($key) && isset(self::ERRORS[$key]) ? [self::ERRORS[$key]] : [];
    }

    private static function intInput(Request $r, string $key): int
    {
        $v = (string) $r->input($key);
        return ctype_digit($v) && strlen($v) < 19 ? (int) $v : 0;
    }

    /** @return array<string,mixed>|null */
    private function fullState(int $sessionId): ?array
    {
        $state = $this->sessions->tutorState($sessionId);
        if ($state !== null && $state['current_block'] !== null) {
            $state['current_block']['state'] = $this->states->forTutor($this->tenantDb, $sessionId, $state['current_block']['id'], BlockType::from($state['current_block']['type']));
        }
        return $state;
    }

    public function connectionToken(Request $r, TenantContext $t): Response
    {
        $id = $r->intParam('id');
        $s = $this->sessions->find($id);
        if ($s === null || $s['status'] !== 'live') {
            throw new HttpException(404, 'Not found');
        }
        return Response::json(['token' => $this->relayTokens->issue(['sid' => $id, 'actor' => 'tutor', 'role' => 'tutor'], 60), 'expires_in' => 60]);
    }
}
