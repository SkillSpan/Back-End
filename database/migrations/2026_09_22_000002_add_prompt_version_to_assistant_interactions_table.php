<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_interactions', function (Blueprint $table) {
            $table->string('prompt_version')->nullable()->after('algorithm_version');
        });
    }

    public function down(): void
    {
        Schema::table('assistant_interactions', function (Blueprint $table) {
            $table->dropColumn('prompt_version');
        });
    }
};
