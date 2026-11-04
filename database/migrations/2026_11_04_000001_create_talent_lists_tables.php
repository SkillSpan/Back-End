<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shortlists: a company's named lists of talent.
 *
 * `organization_id` is the scope every read is filtered by, so a list can never
 * be reached from another company even if its id is guessed. `created_by` is
 * kept separately from the organization because a list belongs to the company,
 * not to the person who happened to create it — when that person leaves, the
 * list stays.
 *
 * The candidate table has a UNIQUE on (list, student): adding the same person
 * twice is a no-op the database refuses, rather than two rows a UI has to
 * de-duplicate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('talent_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('description', 500)->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
        });

        Schema::create('talent_list_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('talent_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->unique(['talent_list_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('talent_list_candidates');
        Schema::dropIfExists('talent_lists');
    }
};
