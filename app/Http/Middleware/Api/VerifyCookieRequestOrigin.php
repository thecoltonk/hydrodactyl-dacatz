<?php

namespace Pterodactyl\Http\Middleware\Api;

use Illuminate\Http\Request;
use Laravel\Sanctum\TransientToken;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * CSRF protection for the API when it is called by the panel's own frontend.
 *
 * The browser authenticates to /api/* with the session's remember-me cookie
 * (no session, so no CSRF token is checked). SameSite=Lax doesn't help on
 * hosts like *.sslip.io, which aren't on the Public Suffix List: every other
 * *.sslip.io page counts as the same site and its forms would carry the
 * cookie. So for cookie-authenticated requests that change anything, require
 * what only our own JavaScript can send: the X-Requested-With header (a
 * cross-origin page can't add it without a CORS preflight, which the panel
 * refuses), plus matching Origin / Sec-Fetch-Site when the browser sends them.
 *
 * API-key (Bearer) requests are unaffected.
 */
class VerifyCookieRequestOrigin
{
    private const SAFE = ['GET', 'HEAD', 'OPTIONS'];

    public function handle(Request $request, \Closure $next): mixed
    {
        if (!$this->isCookieAuthenticated($request) || in_array($request->getRealMethod(), self::SAFE, true)) {
            return $next($request);
        }

        $site = $request->headers->get('Sec-Fetch-Site');
        if ($site !== null && !in_array($site, ['same-origin', 'none'], true)) {
            throw new HttpException(403, 'Cross-site request blocked.');
        }

        $origin = $request->headers->get('Origin');
        if ($origin !== null && strtolower((string) parse_url($origin, PHP_URL_HOST)) !== strtolower($request->getHost())) {
            throw new HttpException(403, 'Cross-origin request blocked.');
        }

        if ($request->headers->get('X-Requested-With') !== 'XMLHttpRequest') {
            throw new HttpException(403, 'Missing X-Requested-With header. Use an API key for scripts.');
        }

        return $next($request);
    }

    private function isCookieAuthenticated(Request $request): bool
    {
        $user = $request->user();

        return $user !== null && method_exists($user, 'currentAccessToken') && $user->currentAccessToken() instanceof TransientToken;
    }
}
