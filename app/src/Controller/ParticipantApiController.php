<?php

declare(strict_types=1);

namespace Tutora\Controller;

use Tutora\Http\HttpException;
use Tutora\Http\Request;
use Tutora\Http\Response;
use Tutora\Participant\JoinFailure;
use Tutora\Participant\ParticipantContext;
use Tutora\Participant\ParticipantService;

/**
 * Participant JSON API. Authenticated by a bearer participant credential
 * (Authorization header, never a cookie), so it is not CSRF-relevant.
 */
final class ParticipantApiController
{
    public function __construct(private readonly ParticipantService $participants)
    {
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
        return Response::json($this->participants->state($this->ctx($r)));
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
