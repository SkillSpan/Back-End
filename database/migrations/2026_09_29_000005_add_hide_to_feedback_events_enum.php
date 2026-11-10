<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const EVENTS = [
        'view',
        'click',
        'save',
        'apply',
        'reject',
        'complete',
        'rating',
        'hide',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('feedback_events', 'event_type')) {
            return;
        }

        Schema::table('feedback_events', function (Blueprint $table) {
            $table->enum('event_type', self::EVENTS)->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('feedback_events', 'event_type')) {
            return;
        }

        Schema::table('feedback_events', function (Blueprint $table) {
            $table->enum('event_type', [
                'view',
                'click',
                'save',
                'apply',
                'reject',
                'complete',
                'rating',
            ])->change();
        });
    }
};
