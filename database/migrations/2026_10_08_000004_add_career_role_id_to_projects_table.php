<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('projects', 'career_role_id')) {
            return;
        }

        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('career_role_id')
                ->nullable()
                ->after('role')
                ->constrained('career_roles')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('projects', 'career_role_id')) {
            return;
        }

        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('career_role_id');
        });
    }
};
