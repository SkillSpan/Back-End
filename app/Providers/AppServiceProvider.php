<?php

namespace App\Providers;

use App\Events\SkillDataChanged;
use App\Listeners\RecalculateIntelligence;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Named limiter => the config/rate_limits.php key holding its ceiling.
     *
     * Kept as one map so a limiter and its tunable can never drift apart: the
     * routes reference the names on the left, the numbers live on the right.
     */
    private const RATE_LIMITERS = [
        'assistant-ask' => 'rate_limits.assistant_ask',
        'readiness-calculate' => 'rate_limits.readiness_calculate',
        'intelligence-calculate' => 'rate_limits.intelligence_calculate',
        'project-matching' => 'rate_limits.project_matching',
        'baseline-submit' => 'rate_limits.baseline_submit',
        'evidence-upload' => 'rate_limits.evidence_upload',
        'support-write' => 'rate_limits.support_write',
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();

        // Production-only safety measures.
        if ($this->app->environment('production')) {
            // Debug mode in production is a data leak, not a convenience: an
            // exception page renders the stack trace, the environment and the
            // queries, to whoever triggered it. `config/app.php` already
            // defaults APP_DEBUG to false, so this only fires when someone has
            // actively turned it ON in a production environment — which is
            // exactly the mistake worth catching.
            $this->enforceProductionDebugSetting();

            // Safety net alongside trustProxies(): force every generated
            // URL (route(), url(), asset()...) to https in production so the
            // admin login form can never post back over http again.
            URL::forceScheme('https');

            // Refuse `db:wipe`, `migrate:fresh`, `migrate:refresh`,
            // `migrate:reset` and `migrate:rollback` in production.
            //
            // This is not theoretical hardening. This database is shared with
            // local development, and the `migrations` table has already once
            // lost most of its rows — which made a deploy replay ~90 migrations
            // from scratch. One mistyped command against that same database
            // would have destroyed real data instead of merely failing. These
            // commands are never correct in production, so making them error
            // out costs nothing and removes the whole failure mode.
            DB::prohibitDestructiveCommands();
        }

        // US-INT-01 §24 — approved skill-data changes queue an
        // intelligence recalculation for the affected learner.
        Event::listen(
            SkillDataChanged::class,
            RecalculateIntelligence::class,
        );
    }

    /**
     * Guarantee that production never serves with APP_DEBUG on.
     *
     * Two things happen, in this order:
     *
     *  1. `app.debug` is forced to false for the rest of the request. This is
     *     the part that actually protects anyone: the exception handler reads
     *     the flag when it renders, so flipping it here — before any handler
     *     runs — means no stack trace, environment dump or query log can be
     *     produced even though the environment still says otherwise.
     *  2. A critical line is logged, so the misconfiguration is loud in the
     *     place an operator will actually see it, rather than silently
     *     corrected.
     *
     * Deliberately NOT an exception. Refusing to boot would turn a
     * one-line environment mistake into a full outage, and the forced-off flag
     * already removes the exposure — the misconfiguration is worth an alert,
     * not a downed service.
     */
    private function enforceProductionDebugSetting(): void
    {
        if (! config('app.debug')) {
            return;
        }

        config(['app.debug' => false]);

        Log::critical(
            'APP_DEBUG was enabled in a production environment. It has been forced off for this request — set APP_DEBUG=false.',
        );
    }

    /**
     * Named limiters for the expensive endpoints (see config/rate_limits.php).
     *
     * Only the routes that cost real money or real CPU are covered: an
     * external Data Science / assistant call, an uploaded file, or a write
     * that fans out notifications. Everything else stays unthrottled so normal
     * browsing is never affected.
     *
     * The bucket is keyed per authenticated user, not per IP: two learners
     * behind one campus NAT must not spend each other's budget, and one noisy
     * account must not be able to starve another. The IP is only the fallback
     * for a request that somehow reaches the middleware unauthenticated.
     *
     * The ceiling is read from config inside the closure rather than at boot,
     * so an override (per environment, or per test) takes effect immediately.
     */
    private function configureRateLimiting(): void
    {
        foreach (self::RATE_LIMITERS as $name => $configKey) {
            RateLimiter::for($name, function (Request $request) use ($configKey) {
                return Limit::perMinute((int) config($configKey))
                    ->by('user:'.($request->user()?->id ?? $request->ip()));
            });
        }
    }
}
