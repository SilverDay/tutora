<?php

declare(strict_types=1);

namespace Tutora\Http;

final class SecurityHeaders
{
    /** @param list<string> $connectSrc extra origins for connect-src (realtime endpoints) */
    public static function apply(Response $r, array $connectSrc = [], bool $https = true): Response
    {
        $connect = implode(' ', array_merge(["'self'"], $connectSrc));
        $headers = [
            'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self'; "
                . "img-src 'self' blob: data:; connect-src {$connect}; object-src 'none'; "
                . "base-uri 'none'; frame-ancestors 'none'; form-action 'self'",
            'X-Content-Type-Options' => 'nosniff',
            // same-origin, not no-referrer: with no-referrer browsers send "Origin: null" on
            // same-site form POSTs, which the CSRF Origin check (correctly) refuses. same-origin
            // still never sends a referrer to other sites.
            'Referrer-Policy' => 'same-origin',
            'X-Frame-Options' => 'DENY',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'Cache-Control' => $r->headers['Cache-Control'] ?? 'no-store',
        ];
        if ($https) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }
        foreach ($headers as $k => $v) {
            if (!isset($r->headers[$k])) {
                $r = $r->withHeader($k, $v);
            }
        }
        return $r;
    }
}
