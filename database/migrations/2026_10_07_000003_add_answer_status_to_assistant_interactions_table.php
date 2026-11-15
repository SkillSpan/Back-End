<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_interactions', function (Blueprint $table) {
            if (! Schema::hasColumn('assistant_interactions', 'answer_status')) {
                $table->string('answer_status')->nullable()->after('response_status');
            }

            if (! Schema::hasColumn('assistant_interactions', 'grounded')) {
                $table->boolean('grounded')->nullable()->after('answer_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('assistant_interactions', function (Blueprint $table) {
            if (Schema::hasColumn('assistant_interactions', 'grounded')) {
                $table->dropColumn('grounded');
            }

            if (Schema::hasColumn('assistant_interactions', 'answer_status')) {
                $table->dropColumn('answer_status');
            }
        });
    }
};
