<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TYPES = ['single_choice', 'scale', 'text'];

    public function up(): void
    {
        if (! Schema::hasColumn('baseline_assessment_items', 'item_type')) {
            return;
        }

        Schema::table('baseline_assessment_items', function (Blueprint $table) {
            $table->enum('item_type', self::TYPES)->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('baseline_assessment_items', 'item_type')) {
            return;
        }

        Schema::table('baseline_assessment_items', function (Blueprint $table) {
            $table->enum('item_type', ['single_choice', 'scale'])->change();
        });
    }
};
