<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skill_evidences', function (Blueprint $table) {
            if (! Schema::hasColumn('skill_evidences', 'description')) {
                $table->string('description', 500)->nullable()->after('reference');
            }
        });
    }

    public function down(): void
    {
        Schema::table('skill_evidences', function (Blueprint $table) {
            if (Schema::hasColumn('skill_evidences', 'description')) {
                $table->dropColumn('description');
            }
        });
    }
};
