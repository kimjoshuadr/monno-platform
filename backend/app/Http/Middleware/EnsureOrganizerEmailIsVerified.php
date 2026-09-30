<?php

namespace HiEvents\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Full lock for an organizer whose email is not confirmed yet.
 *
 * The onboarding wizard's "check your email" step is only its visible half; this
 * is the half that means something, because a JWT with a valid account_id would
 * otherwise sail straight past the wizard to /events and start publishing.
 *
 * Scope decisions, all deliberate:
 *  - Only runs when APP_REQUIRE_EMAIL_VERIFICATION is on, so it can be turned off
 *    if the sending domain ever falls over and organizers must not be stranded.
 *  - Ticket buyers have no account_id in their token and are exempt: their
 *    addresses are confirmed at registration and they have no organizer surface.
 *  - A handful of routes stay reachable so the session can prove itself, resend
 *    the code and log out. Everything else under auth:api is refused.
 */
class EnsureOrganizerEmailIsVerified
{
    /** Routes an unverified organizer still has to reach, keyed by method+path. */
    private const ALLOWLIST = [
        'GET /users/me',
        'PUT /users/me',
        'POST /users/{user_id}/confirm-email-with-code',
        'POST /users/{user_id}/resend-email-confirmation',
        'GET /auth/logout',
        'POST /auth/refresh',
    ];

    public function handle(Request $request, Closure $next): mixed
    {
        if (! (bool) config('app.require_email_verification')) {
            return $next($request);
        }

        if (! Auth::check()) {
            return $next($request);
        }

        // Buyers carry no account context, and admins sign in through the
        // /admin surface with their own concerns. Neither is gated by this.
        if (! Auth::payload()->get('account_id')) {
            return $next($request);
        }

        // The model is already resolved by Auth::check() above, so this reads a
        // single row the request needed anyway rather than paying for the domain
        // object's extra account_user lookup on every API call.
        $authUser = Auth::user();

        if ($authUser === null || ! empty($authUser->email_verified_at)) {
            return $next($request);
        }

        if ($this->routeIsAllowed($request)) {
            return $next($request);
        }

        return new JsonResponse([
            'message' => __('Your email address has not been verified yet.'),
            'code' => 'email_not_verified',
        ], 403);
    }

    private function routeIsAllowed(Request $request): bool
    {
        $path = '/'.ltrim($request->path(), '/');

        foreach (self::ALLOWLIST as $allowed) {
            [$method, $pattern] = explode(' ', $allowed, 2);

            if ($request->method() !== $method) {
                continue;
            }

            if (preg_match($this->toRegex($pattern), $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * '/users/{user_id}/confirm-email-with-code' becomes a one-segment wildcard.
     *
     * Built by quoting each literal stretch and joining them with `[^/]+` —
     * substituting a sentinel first does not work, because preg_quote() escapes
     * the sentinel byte itself and the pattern then looks for a literal one.
     */
    private function toRegex(string $pattern): string
    {
        $segments = preg_split('/\{[^}]+\}/', $pattern);

        return '#^'.implode(
            '[^/]+',
            array_map(fn (string $segment): string => preg_quote($segment, '#'), $segments),
        ).'$#';
    }
}
