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
    private const RATE_LIMITERS = [
        'assistant-ask' => 'rate_limits.assistant_ask',
        'readiness-calculate' => 'rate_limits.readiness_calculate',
        'intelligence-calculate' => 'rate_limits.intelligence_calculate',
        'project-matching' => 'rate_limits.project_matching',
        'baseline-submit' => 'rate_limits.baseline_submit',
        'evidence-upload' => 'rate_limits.evidence_upload',
        'support-write' => 'rate_limits.support_write',
    ];

    public function register(): void {}

    public function boot(): void
    {
        $this->configureRateLimiting();

        if ($this->app->environment('production')) {
            $this->enforceProductionDebugSetting();

            URL::forceScheme('https');

            DB::prohibitDestructiveCommands();
        }

        Event::listen(
            SkillDataChanged::class,
            RecalculateIntelligence::class,
        );
    }

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
