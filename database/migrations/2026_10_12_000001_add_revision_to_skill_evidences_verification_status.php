<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const STATUSES = [
        'pending',
        'verified',
        'rejected',
        'expired',
        'revision',
    ];

    private const ORIGINAL_STATUSES = [
        'pending',
        'verified',
        'rejected',
        'expired',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('skill_evidences', 'verification_status')) {
            return;
        }

        Schema::table('skill_evidences', function (Blueprint $table) {
            $table->enum('verification_status', self::STATUSES)
                ->default('pending')
                ->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('skill_evidences', 'verification_status')) {
            return;
        }

        DB::table('skill_evidences')
            ->where('verification_status', 'revision')
            ->update(['verification_status' => 'pending']);

        Schema::table('skill_evidences', function (Blueprint $table) {
            $table->enum('verification_status', self::ORIGINAL_STATUSES)
                ->default('pending')
                ->change();
        });
    }
};
