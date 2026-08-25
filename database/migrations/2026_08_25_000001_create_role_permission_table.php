<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the role_permission pivot that Role::permissions() and
 * Permission::roles() (both using ->withTimestamps()) have been pointing
 * at since the models were introduced. A migration named
 * "create_role_permission_table" previously created password_reset_tokens
 * instead, so any call to $role->permissions() failed with
 * "table not found". This closes that gap without touching history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permission', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->unique(['role_id', 'permission_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permission');
    }
};
