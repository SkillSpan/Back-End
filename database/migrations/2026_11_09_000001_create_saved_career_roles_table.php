<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_career_roles', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('career_role_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['user_id', 'career_role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_career_roles');
    }
};
