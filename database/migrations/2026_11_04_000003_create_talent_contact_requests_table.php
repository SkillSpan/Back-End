<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A recorded request to contact a candidate.
 *
 * WHY A ROW AND NOT JUST AN EMAIL
 * -------------------------------
 * `TalentProfileResource` deliberately withholds the student's e-mail address —
 * the comment in that file says a company is expected to reach a learner
 * "through a recorded contact request". This table is that record. It is what
 * makes withholding the address defensible: the company can ask, the learner
 * can answer, and there is an audit trail of who asked whom and when.
 *
 * `status` starts at `pending` and only the learner's answer moves it. The
 * company can cancel, which is why `cancelled` exists — an unanswered request
 * that the company withdrew must not stay `pending` forever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('talent_contact_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->text('message');
            $table->enum('status', ['pending', 'accepted', 'declined', 'cancelled'])->default('pending');
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'student_id']);
            $table->index(['student_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('talent_contact_requests');
    }
};
