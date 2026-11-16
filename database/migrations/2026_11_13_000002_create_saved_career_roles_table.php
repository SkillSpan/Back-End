<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('saved_career_roles')) {
            return;
        }

        Schema::create('saved_career_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('career_role_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'career_role_id'], 'saved_career_roles_user_role_unique');
            $table->index('career_role_id', 'saved_career_roles_role_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_career_roles');
    }
};
