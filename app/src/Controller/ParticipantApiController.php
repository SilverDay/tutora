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
use Tutora\Participant\JoinFailure;
use Tutora\Participant\ParticipantContext;
use Tutora\Participant\ParticipantService;
use Tutora\Support\ValidationException;

/**
 * Participant JSON API. Authenticated by a bearer participant credential
 * (Authorization header, never a cookie), so it is not CSRF-relevant.
 */
final class ParticipantApiController
{
    public function __construct(
        private readonly ParticipantService $participants,
        private readonly SubmissionService $submissions,
        private readonly WallService $wall,
        private readonly QuizService $quiz,
        private readonly BlockStates $states,
    ) {
    }

    public function join(Request $r): Response
    {
        $body = $r->json();
        $result = $this->participants->join(
            is_string($body['code'] ?? null) ? $body['code'] : '',
            is_string($body['display_name'] ?? null) ? $body['display_name'] : null,
            $r->clientIp,
        );
        if ($result instanceof JoinFailure) {
            $resp = Response::json(['error' => $result->message], $result->retryAfter > 0 ? 429 : 404);
            return $result->retryAfter > 0 ? $resp->withHeader('Retry-After', (string) $result->retryAfter) : $resp;
        }
        return Response::json($result, 201);
    }

    public function resume(Request $r): Response
    {
        $body = $r->json();
        $result = $this->participants->resume(is_string($body['resume_token'] ?? null) ? $body['resume_token'] : '', $r->clientIp);
        return $result === null ? Response::json(['error' => 'Session can no longer be resumed.'], 401) : Response::json($result);
    }

    public function presence(Request $r): Response
    {
        return Response::json($this->participants->renewPresence($this->ctx($r)));
    }

    public function state(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $state = $this->participants->state($ctx);
        if ($state['current_block'] !== null) {
            $state['current_block']['state'] = $this->states->forParticipant($ctx, $state['current_block']['id'], BlockType::from($state['current_block']['type']));
        }
        return Response::json($state);
    }

    public function submit(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $body = $r->json();
        $payload = $body['payload'] ?? null;
        if (!is_array($payload)) {
            throw new HttpException(422, 'Expected a payload object');
        }
        return $this->guard(fn () => Response::json(['mine' => $this->submissions->submit($ctx, $r->intParam('block'), $payload)]));
    }

    public function addCard(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $b = $r->json();
        return $this->guard(fn () => Response::json(['id' => $this->wall->addCard($ctx, $r->intParam('block'), $b['text'] ?? null, $b['column_id'] ?? null)], 201));
    }

    public function moveCard(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $b = $r->json();
        return $this->guard(fn () => $this->wall->moveCard($ctx, $r->intParam('card'), $b['column_id'] ?? null, $b['position'] ?? null)
            ? Response::json(['ok' => true]) : throw new HttpException(404, 'Not found'));
    }

    public function editCard(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $b = $r->json();
        return $this->guard(fn () => $this->wall->editCard($ctx, $r->intParam('card'), $b['text'] ?? null)
            ? Response::json(['ok' => true]) : throw new HttpException(404, 'Not found'));
    }

    public function deleteCard(Request $r): Response
    {
        $ctx = $this->ctx($r);
        return $this->guard(fn () => $this->wall->deleteCard($ctx, $r->intParam('card'))
            ? Response::json(['ok' => true]) : throw new HttpException(404, 'Not found'));
    }

    public function openQuestion(Request $r): Response
    {
        $ctx = $this->ctx($r);
        return $this->guard(fn () => Response::json($this->quiz->openQuestion($ctx, $r->intParam('block'), $r->param('question'))));
    }

    public function answer(Request $r): Response
    {
        $ctx = $this->ctx($r);
        $b = $r->json();
        return $this->guard(function () use ($ctx, $r, $b): Response {
            $this->quiz->answer($ctx, $r->intParam('block'), $r->param('question'), $b);
            return Response::json(['ok' => true], 201);
        });
    }

    /** Maps domain errors to user-safe HTTP responses. */
    private function guard(callable $fn): Response
    {
        try {
            return $fn();
        } catch (ValidationException $e) {
            return Response::json(['error' => 'validation', 'errors' => $e->errors], 422);
        } catch (BlockNotOpen|AnswerRejected $e) {
            return Response::json(['error' => $e->getMessage()], 409);
        }
    }

    public function connectionToken(Request $r): Response
    {
        return Response::json(['token' => $this->participants->connectionToken($this->ctx($r)), 'expires_in' => ParticipantService::CONNECTION_TOKEN_TTL]);
    }

    private function ctx(Request $r): ParticipantContext
    {
        $auth = $r->header('authorization');
        $credential = $auth !== null && str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : null;
        return $this->participants->authenticate($credential, $r->intParam('id'))
            ?? throw new HttpException(401, 'Not authorised for this session', ['WWW-Authenticate' => 'Bearer']);
    }
}
