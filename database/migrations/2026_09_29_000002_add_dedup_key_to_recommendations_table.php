<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recommendations', function (Blueprint $table) {
            if (! Schema::hasColumn('recommendations', 'dedup_key')) {
                $table->string('dedup_key', 64)->nullable()->after('candidate_id');
            }
        });

        Schema::table('recommendations', function (Blueprint $table) {
            $table->unique('dedup_key', 'recommendations_dedup_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('recommendations', function (Blueprint $table) {
            $table->dropUnique('recommendations_dedup_key_unique');
        });

        Schema::table('recommendations', function (Blueprint $table) {
            if (Schema::hasColumn('recommendations', 'dedup_key')) {
                $table->dropColumn('dedup_key');
            }
        });
    }
};
