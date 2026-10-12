<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds the browser-facing security headers to every response.
 *
 * Nothing in the app sent them before: the only one anywhere in the codebase
 * was a single `X-Content-Type-Options: nosniff` on one avatar response. That
 * left two real holes:
 *
 *  - **Content sniffing.** Without `nosniff`, a browser may decide a JSON or
 *    uploaded-file response is really HTML and render it — which turns any
 *    endpoint that echoes user input into stored XSS. This is the header that
 *    matters most for an API.
 *  - **Clickjacking.** With no `X-Frame-Options`, the admin panel could be
 *    framed and its buttons overlaid.
 *
 * Registered globally (see bootstrap/app.php) so it also covers error and
 * middleware-rejected responses, which are produced inside the stack.
 *
 * Deliberately NOT a Content-Security-Policy. The Blade admin panel and the
 * l5-swagger UI both rely on inline <script>/<style> and pull fonts from
 * Google's CDN, so any CSP strict enough to be worth shipping would have to be
 * built alongside nonces and an explicit source allow-list — a change to the
 * pages themselves, not a header. Shipping a weak `'unsafe-inline'` policy
 * would look like protection without being it.
 */
class AddSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            // Everything this app actually needs is same-origin fetch. Anything
            // that could be abused to reach the device is turned off.
            'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()',
        ];

        // HSTS is only meaningful — and only honoured — over TLS, so sending it
        // on a plain-HTTP dev request would be wrong. `includeSubDomains` is
        // left out until every subdomain is known to be HTTPS.
        if ($request->secure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000';
        }

        foreach ($headers as $name => $value) {
            // A controller that set its own value (the avatar download sets
            // nosniff explicitly) keeps it — this is a floor, not a ceiling.
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
