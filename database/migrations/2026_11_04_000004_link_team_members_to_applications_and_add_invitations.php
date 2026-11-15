<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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

            $table->index(['application_id', 'assignment_state'], 'ptm_application_state_index');
        });

        Schema::create('team_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_team_member_id')
                ->constrained('project_team_members')
                ->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('project_role')->nullable();
            $table->text('message')->nullable();
            $table->enum('status', ['pending', 'accepted', 'declined', 'cancelled'])->default('pending');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

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
