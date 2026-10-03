<?php

namespace App\Providers;

use App\Events\SkillDataChanged;
use App\Listeners\RecalculateIntelligence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
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
        // Production-only safety measures.
        if ($this->app->environment('production')) {
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
}
