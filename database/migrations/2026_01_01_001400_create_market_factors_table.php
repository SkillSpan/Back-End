<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_factors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('career_role_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->nullable()->constrained()->cascadeOnDelete();
            $table->decimal('demand_value', 8, 4)->nullable();
            $table->string('source')->nullable();
            $table->string('geography')->nullable();
            $table->string('segment')->nullable();
            $table->date('collection_date')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_factors');
    }
};
