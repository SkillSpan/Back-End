<?php

namespace App\Providers;

use App\Events\SkillDataChanged;
use App\Listeners\RecalculateIntelligence;
use Illuminate\Support\Facades\Event;
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
        // US-INT-01 §24 — approved skill-data changes queue an
        // intelligence recalculation for the affected learner.
        Event::listen(
            SkillDataChanged::class,
            RecalculateIntelligence::class,
        );
    }
}
