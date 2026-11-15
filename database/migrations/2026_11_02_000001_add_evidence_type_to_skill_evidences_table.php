<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('skill_evidences', 'evidence_type')) {
            return;
        }

        Schema::table('skill_evidences', function (Blueprint $table) {
            $table->string('evidence_type', 32)
                ->nullable()
                ->after('source');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('skill_evidences', 'evidence_type')) {
            return;
        }

        Schema::table('skill_evidences', function (Blueprint $table) {
            $table->dropColumn('evidence_type');
        });
    }
};
