<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('admin_profiles')) {
            Schema::create('admin_profiles', function (Blueprint $table) {
                $table->id();

                $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();

                $table->string('display_title', 60)->nullable();

                $table->text('bio')->nullable();

                $table->unsignedTinyInteger('age')->nullable();

                $table->mediumText('avatar_data')->nullable();
                $table->string('avatar_mime', 40)->nullable();

                $table->timestamp('avatar_updated_at')->nullable();

                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_profiles');
    }
};
