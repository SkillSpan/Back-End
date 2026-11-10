<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('baseline_assessment_items', function (Blueprint $table) {
            if (! Schema::hasColumn('baseline_assessment_items', 'question_text')) {
                $table->text('question_text')->nullable()->after('item_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('baseline_assessment_items', function (Blueprint $table) {
            if (Schema::hasColumn('baseline_assessment_items', 'question_text')) {
                $table->dropColumn('question_text');
            }
        });
    }
};
