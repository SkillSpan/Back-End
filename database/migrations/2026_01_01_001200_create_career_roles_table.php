<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('career_roles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug');
            $table->unsignedInteger('version')->default(1);
            $table->enum('status', ['draft','approved','retired'])->default('draft');
            $table->date('effective_date')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['slug','version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('career_roles');
    }
};
