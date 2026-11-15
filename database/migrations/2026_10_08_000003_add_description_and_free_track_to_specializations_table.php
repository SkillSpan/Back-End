<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('specializations', function (Blueprint $table) {
            if (! Schema::hasColumn('specializations', 'description')) {
                $table->text('description')->nullable()->after('name');
            }

            if (! Schema::hasColumn('specializations', 'is_free_track')) {
                $table->boolean('is_free_track')->default(false)->after('is_active');
            }
        });
    }

    public function down(): void
    {
        Schema::table('specializations', function (Blueprint $table) {
            if (Schema::hasColumn('specializations', 'is_free_track')) {
                $table->dropColumn('is_free_track');
            }

            if (Schema::hasColumn('specializations', 'description')) {
                $table->dropColumn('description');
            }
        });
    }
};
