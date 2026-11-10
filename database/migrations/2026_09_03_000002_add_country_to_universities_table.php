<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('universities', function (Blueprint $table) {
            $table->foreignId('country_id')->nullable()->after('name')
                ->constrained('countries')->restrictOnDelete();
        });

        Schema::table('universities', function (Blueprint $table) {
            $table->dropUnique(['name']);
        });

        Schema::table('universities', function (Blueprint $table) {
            $table->unique(['country_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('universities', function (Blueprint $table) {
            $table->dropForeign(['country_id']);
        });

        Schema::table('universities', function (Blueprint $table) {
            $table->dropUnique(['country_id', 'name']);
        });

        Schema::table('universities', function (Blueprint $table) {
            $table->unique(['name']);
        });

        Schema::table('universities', function (Blueprint $table) {
            $table->dropColumn('country_id');
        });
    }
};
