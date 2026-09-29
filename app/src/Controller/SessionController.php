<?php

declare(strict_types=1);

namespace Tutora\Controller;

use Tutora\Http\HttpException;
use Tutora\Http\Request;
use Tutora\Http\Response;
use Tutora\Security\HmacToken;
use Tutora\Session\SessionService;
use Tutora\Tenant\TenantContext;
use Tutora\View\View;

/** Tutor live-session console and tutor-side JSON API. */
final class SessionController
{
    public function __construct(
        private readonly SessionService $sessions,
        private readonly HmacToken $relayTokens,
        private readonly View $view,
    ) {
    }

    public function show(Request $r, TenantContext $t): Response
    {
        $id = $r->intParam('id');
        $state = $this->sessions->tutorState($id) ?? throw new HttpException(404, 'Not found');
        return $this->view->render('sessions/show', [
            'title' => 'Live session',
            'session' => $this->sessions->find($id),
            'state' => $state,
            'blocks' => $this->sessions->blocks($id),
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
        if (!$this->sessions->delete($r->intParam('id'), $r->clientIp)) {
            throw new HttpException(404, 'Not found');
        }
        return Response::redirect('/dashboard');
    }

    public function state(Request $r, TenantContext $t): Response
    {
        return Response::json($this->sessions->tutorState($r->intParam('id')) ?? throw new HttpException(404, 'Not found'));
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
