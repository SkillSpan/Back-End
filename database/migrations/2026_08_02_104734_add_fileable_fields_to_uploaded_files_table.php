<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uploaded_files', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();

            $table->nullableMorphs('fileable');
        });
    }

    public function down(): void
    {
        Schema::table('uploaded_files', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable(false)->change();

            $table->dropMorphs('fileable');
        });
    }
};
