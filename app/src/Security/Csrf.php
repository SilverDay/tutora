<?php

declare(strict_types=1);

namespace Tutora\Security;

use Tutora\Auth\SessionStore;
use Tutora\Http\HttpException;
use Tutora\Http\Request;

/**
 * Synchroniser-token CSRF protection on every state-changing tutor request
 * (spec: SameSite=Strict alone is not sufficient). Also checks Origin when present.
 */
final class Csrf
{
    private const KEY = '_csrf';
    public const FIELD = '_csrf';
    public const HEADER = 'x-csrf-token';

    /** @param list<string> $allowedOrigins */
    public function __construct(private readonly SessionStore $session, private readonly array $allowedOrigins)
    {
    }

    public function token(): string
    {
        $t = $this->session->get(self::KEY);
        if (!is_string($t)) {
            $t = Base64Url::encode(random_bytes(32));
            $this->session->set(self::KEY, $t);
        }
        return $t;
    }

    /** Rotate after login so a pre-login token can't be reused. */
    public function rotate(): void
    {
        $this->session->remove(self::KEY);
    }

    /** @throws HttpException 403 */
    public function verify(Request $request): void
    {
        if (!$request->isStateChanging()) {
            return;
        }
        $origin = $request->header('origin');
        if ($origin !== null && !in_array($origin, $this->allowedOrigins, true)) {
            throw new HttpException(403, 'Cross-origin request refused');
        }
        $expected = $this->session->get(self::KEY);
        $given = $request->header(self::HEADER) ?? $request->input(self::FIELD);
        if (!is_string($expected) || !is_string($given) || !hash_equals($expected, $given)) {
            throw new HttpException(403, 'Invalid or missing CSRF token');
        }
    }
}
