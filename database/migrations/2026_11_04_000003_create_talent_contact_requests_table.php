<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('talent_contact_requests')) {
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

            return;
        }

        if (! Schema::hasIndex('talent_contact_requests', 'talent_contact_requests_student_id_status_index')) {
            Schema::table('talent_contact_requests', function (Blueprint $table) {
                $table->index(
                    ['student_id', 'status'],
                    'talent_contact_requests_student_id_status_index'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('talent_contact_requests');
    }
};
