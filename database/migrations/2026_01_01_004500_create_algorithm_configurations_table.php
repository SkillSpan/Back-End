<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('algorithm_configurations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('version')->default(1);
            $table->enum('status', ['draft','active','retired'])->default('draft');
            $table->json('config');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
            $table->unique(['name','version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('algorithm_configurations');
    }
};
