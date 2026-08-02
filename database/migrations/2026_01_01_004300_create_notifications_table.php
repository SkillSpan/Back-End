<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category');
            $table->string('channel')->default('in_app');
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('link')->nullable();
            $table->string('event_key');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id','event_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
