<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Unifies projects.status on the official Pilot lifecycle.
 *
 * BEFORE (2026_01_01_002700_create_projects_table):
 *   draft, pending_review, open, closed, in_progress, completed, archived
 *
 * AFTER (official lifecycle):
 *   draft, submitted, changes_requested, approved, rejected, open, selection,
 *   active, under_review, completed, cancelled, archived
 *
 * Two legacy values are RENAMED rather than dropped, so no row is lost:
 *   pending_review → submitted
 *   in_progress    → active
 *
 * `closed` is deliberately PRESERVED. It is not part of the official
 * lifecycle and no code in this project reads or writes it, but whether it
 * becomes a real status, is folded into cancelled/archived, or is removed
 * outright is a product decision that has NOT been taken. Dropping it here
 * would take that decision silently, so the enum keeps it and the report
 * flags it as the remaining open question.
 */
return new class extends Migration
{
    /** The enum as it was created. */
    private const BEFORE = ['draft', 'pending_review', 'open', 'closed', 'in_progress', 'completed', 'archived'];

    /** The official lifecycle, plus the undecided legacy value. */
    private const AFTER = [
        'draft', 'submitted', 'changes_requested', 'approved', 'rejected', 'open',
        'selection', 'active', 'under_review', 'completed', 'cancelled', 'archived',
        // Legacy, undecided — see the class docblock.
        'closed',
    ];

    public function up(): void
    {
        // Rewrite the data BEFORE narrowing the enum. MySQL coerces a value
        // that is no longer part of an enum to '' when the column is altered,
        // so renaming after the ALTER would silently blank every affected row.
        $this->renameStatus('pending_review', 'submitted');
        $this->renameStatus('in_progress', 'active');

        Schema::table('projects', function (Blueprint $table) {
            // All modifiers are restated: a changed column keeps only what is
            // declared here. `status` was NOT NULL with a default of 'draft'.
            $table->enum('status', self::AFTER)->default('draft')->change();
        });
    }

    public function down(): void
    {
        // Lossy by nature: the official lifecycle has values the original enum
        // had no slot for. Each is mapped onto its closest original equivalent
        // so a rollback still yields a valid enum instead of blanking rows.
        $this->renameStatus('submitted', 'pending_review');
        $this->renameStatus('active', 'in_progress');
        $this->renameStatus('changes_requested', 'draft');
        $this->renameStatus('approved', 'draft');
        $this->renameStatus('rejected', 'draft');
        $this->renameStatus('selection', 'open');
        $this->renameStatus('under_review', 'in_progress');
        $this->renameStatus('cancelled', 'archived');

        Schema::table('projects', function (Blueprint $table) {
            $table->enum('status', self::BEFORE)->default('draft')->change();
        });
    }

    private function renameStatus(string $from, string $to): void
    {
        DB::table('projects')->where('status', $from)->update(['status' => $to]);
    }
};
