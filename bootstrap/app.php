<?php

use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureOrganizationIsApproved;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\EnsureUserIsMentor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Render terminates TLS and forwards the request to the app as
        // plain HTTP. Without this, Laravel thinks every request is
        // insecure, generates http:// URLs (route(), url(), asset()),
        // and that scheme mismatch is what causes the "not secure" form
        // warning and the 419 CSRF/session errors on the admin login.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR |
                Request::HEADER_X_FORWARDED_HOST |
                Request::HEADER_X_FORWARDED_PORT |
                Request::HEADER_X_FORWARDED_PROTO
        );

        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'admin' => EnsureUserIsAdmin::class,
            'mentor' => EnsureUserIsMentor::class,
            'organization.approved' => EnsureOrganizationIsApproved::class,
            'account.active' => EnsureAccountIsActive::class,
        ]);

        // Browser-facing security headers on every response (see the
        // middleware for why there is no CSP). Global, so error and
        // middleware-rejected responses carry them too.
        $middleware->append(AddSecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A database failure can reach the log through two doors, and both
        // used to leak row data. The application's own catch blocks now go
        // through SafeLog::reason(); this covers the other door — an
        // *unhandled* QueryException reaching the default reporter, which logs
        // `$e->getMessage()` with every bound value substituted into the SQL.
        //
        // Returning false suppresses that default report; the redacted line
        // below replaces it. The stack trace is kept on purpose — it carries no
        // bound values — so a database failure stays diagnosable.
        $exceptions->report(function (QueryException $e): bool {
            Log::error('Unhandled database query failure.', [
                'connection' => $e->getConnectionName(),
                'code' => (string) $e->getCode(),
                'sql' => $e->getSql(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        });
    })->create();
