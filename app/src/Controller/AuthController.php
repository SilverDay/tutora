<?php

declare(strict_types=1);

namespace Tutora\Controller;

use Tutora\Auth\AuthResult;
use Tutora\Auth\AuthStage;
use Tutora\Auth\TutorAuthService;
use Tutora\Http\Request;
use Tutora\Http\Response;
use Tutora\Tenant\TenantContext;
use Tutora\View\View;

final class AuthController
{
    public function __construct(private readonly TutorAuthService $auth, private readonly View $view)
    {
    }

    public function showLogin(Request $r): Response
    {
        return $this->view->render('auth/login', ['title' => 'Sign in', 'errors' => [], 'email' => '']);
    }

    public function login(Request $r): Response
    {
        $email = (string) $r->input('email');
        $result = $this->auth->login($email, (string) $r->input('password'), $r->clientIp);
        if (!$result->ok) {
            return $this->failed('auth/login', 'Sign in', $result, ['email' => $email]);
        }
        return $this->redirectForStage($result->stage);
    }

    public function showSignup(Request $r): Response
    {
        return $this->view->render('auth/signup', ['title' => 'Create account', 'errors' => [], 'email' => '', 'name' => '']);
    }

    public function signup(Request $r): Response
    {
        $email = (string) $r->input('email');
        $name = (string) $r->input('display_name');
        $result = $this->auth->register($email, $name, (string) $r->input('password'), $r->clientIp);
        if (!$result->ok) {
            return $this->failed('auth/signup', 'Create account', $result, ['email' => $email, 'name' => $name]);
        }
        // identical page for new and already registered addresses
        return $this->view->render('auth/check_inbox', ['title' => 'Check your inbox']);
    }

    public function showVerify(Request $r): Response
    {
        return $this->view->render('auth/verify', ['title' => 'Confirm your account', 'errors' => [], 'token' => '']);
    }

    public function verify(Request $r): Response
    {
        $token = (string) $r->input('token');
        $result = $this->auth->verifySignup($token, (string) $r->input('password'), $r->clientIp);
        if (!$result->ok) {
            // keep the token in the form so a mistyped password can be retried
            return $this->failed('auth/verify', 'Confirm your account', $result, ['token' => preg_match('/^[A-Za-z0-9_-]{1,100}$/', $token) === 1 ? $token : '']);
        }
        return $this->redirectForStage($result->stage);
    }

    public function showMfa(Request $r): Response
    {
        if ($this->auth->currentStage() !== AuthStage::MfaPending) {
            return Response::redirect('/login');
        }
        return $this->view->render('auth/mfa', ['title' => 'Two-factor authentication', 'errors' => []]);
    }

    public function verifyMfa(Request $r): Response
    {
        $result = $this->auth->verifyMfa((string) $r->input('code'), $r->clientIp);
        if (!$result->ok) {
            if ($this->auth->currentStage() !== AuthStage::MfaPending) {
                return Response::redirect('/login');
            }
            return $this->failed('auth/mfa', 'Two-factor authentication', $result);
        }
        return Response::redirect('/dashboard');
    }

    public function showEnroll(Request $r): Response
    {
        $enrol = $this->auth->beginEnrollment();
        if ($enrol === null) {
            return Response::redirect('/login');
        }
        return $this->view->render('auth/enroll', ['title' => 'Set up two-factor authentication', 'errors' => []] + $enrol);
    }

    public function confirmEnroll(Request $r): Response
    {
        $result = $this->auth->confirmEnrollment((string) $r->input('code'), $r->clientIp);
        if ($result->ok) {
            // shown exactly once, in this response
            return $this->view->render('auth/recovery_codes', ['title' => 'Save your recovery codes', 'codes' => $result->recoveryCodes])
                ->withHeader('Cache-Control', 'no-store');
        }
        // new secret on each attempt page render; keep it simple and restart enrolment
        $enrol = $this->auth->beginEnrollment();
        if ($enrol === null) {
            return Response::redirect('/login');
        }
        return $this->view->render('auth/enroll', ['title' => 'Set up two-factor authentication', 'errors' => $result->errors] + $enrol, 422);
    }

    public function logout(Request $r): Response
    {
        $this->auth->logout($r->clientIp);
        return Response::redirect('/login');
    }

    public function showPassword(Request $r, TenantContext $t): Response
    {
        return $this->account($t, [], false);
    }

    public function regenerateRecoveryCodes(Request $r, TenantContext $t): Response
    {
        $result = $this->auth->regenerateRecoveryCodes($t, (string) $r->input('current_password'), (string) $r->input('code'), $r->clientIp);
        if (!$result->ok) {
            return $this->account($t, $result->errors, false, 422);
        }
        return $this->view->render('auth/recovery_codes', ['title' => 'Save your recovery codes', 'codes' => $result->recoveryCodes])
            ->withHeader('Cache-Control', 'no-store');
    }

    /** @param list<string> $errors */
    private function account(TenantContext $t, array $errors, bool $done, int $status = 200): Response
    {
        return $this->view->render('account/password', [
            'title' => 'Account', 'errors' => $errors, 'done' => $done,
            'recoveryRemaining' => $this->auth->remainingRecoveryCodes($t),
        ], $status);
    }

    public function changePassword(Request $r, TenantContext $t): Response
    {
        $result = $this->auth->changePassword(
            $t,
            (string) $r->input('current_password'),
            (string) $r->input('new_password'),
            (string) $r->input('code'),
            $r->clientIp,
        );
        return $this->account($t, $result->errors, $result->ok, $result->ok ? 200 : 422);
    }

    private function redirectForStage(?AuthStage $stage): Response
    {
        return match ($stage) {
            AuthStage::MfaPending => Response::redirect('/login/mfa'),
            AuthStage::MfaEnrollment => Response::redirect('/login/enroll'),
            AuthStage::Full => Response::redirect('/dashboard'),
            null => Response::redirect('/login'),
        };
    }

    /** @param array<string,mixed> $vars */
    private function failed(string $template, string $title, AuthResult $result, array $vars = []): Response
    {
        $response = $this->view->render($template, ['title' => $title, 'errors' => $result->errors] + $vars, $result->retryAfter > 0 ? 429 : 422);
        return $result->retryAfter > 0 ? $response->withHeader('Retry-After', (string) $result->retryAfter) : $response;
    }
}
