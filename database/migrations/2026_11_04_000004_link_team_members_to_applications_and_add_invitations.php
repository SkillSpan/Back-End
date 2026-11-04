<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two things the team screens need that the Pilot schema did not have.
 *
 * 1. `project_team_members.application_id`
 *
 *    Staffing starts from an application — the company accepts an application,
 *    then assigns that applicant. But the member row only recorded the user, so
 *    "remove this participant" had to be addressed by user id, and a participant
 *    could not be traced back to the application they were hired through. The
 *    frontend addresses participants by `application_id` (that is what its
 *    assignment payload carries), so the column is the missing link.
 *
 *    Nullable: a member added by an administrator without an application stays
 *    valid, and existing rows have no application to point at.
 *
 * 2. `team_invitations`
 *
 *    Assignment and acceptance are two different acts. Being put on a team does
 *    not mean the person agreed to join, and the learner has to be able to see
 *    the invitation and answer it. One row per (member, send), so a re-send
 *    after a decline is a new row and the history survives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_team_members', function (Blueprint $table) {
            $table->foreignId('application_id')
                ->nullable()
                ->after('user_id')
                ->constrained('applications')
                ->nullOnDelete();

            // "Which member came from which application" is looked up on every
            // participant action, and it must not return two rows.
            $table->index(['application_id', 'assignment_state'], 'ptm_application_state_index');
        });

        Schema::create('team_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_team_member_id')
                ->constrained('project_team_members')
                ->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // The invited person, duplicated from the member row on purpose: the
            // learner's inbox query must not depend on the team tables, and a
            // member row that is later removed must not take the invitation with
            // it.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('project_role')->nullable();
            $table->text('message')->nullable();
            $table->enum('status', ['pending', 'accepted', 'declined', 'cancelled'])->default('pending');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            // One PENDING invitation per member at a time. A decline followed by
            // a re-invite is a second row, which is why this is not unique on
            // (member, status) alone.
            $table->index(['project_team_member_id', 'status'], 'team_invitations_member_status_index');
            $table->index(['user_id', 'status'], 'team_invitations_user_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_invitations');

        Schema::table('project_team_members', function (Blueprint $table) {
            $table->dropIndex('ptm_application_state_index');
            $table->dropConstrainedForeignId('application_id');
        });
    }
};
