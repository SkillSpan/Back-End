<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role_in_org')->default('member');
            $table->enum('status', ['active','invited','removed'])->default('active');
            $table->timestamps();
            $table->unique(['organization_id','user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_members');
    }
};
