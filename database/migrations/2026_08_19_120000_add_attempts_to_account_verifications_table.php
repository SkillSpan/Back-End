<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_verifications', function (Blueprint $table) {
            if (! Schema::hasColumn('account_verifications', 'attempts')) {
                $table->unsignedTinyInteger('attempts')->default(0)->after('challenge_state');
            }
        });
    }

    public function down(): void
    {
        Schema::table('account_verifications', function (Blueprint $table) {
            if (Schema::hasColumn('account_verifications', 'attempts')) {
                $table->dropColumn('attempts');
            }
        });
    }
};
