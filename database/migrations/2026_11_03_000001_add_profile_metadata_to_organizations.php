<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * US-COMP-04 — the Database task list asks the profile to record who last
 * changed it, when, what completeness it had, and whether a change pushed the
 * organization into re-verification.
 *
 * None of this is derivable after the fact: `updated_at` moves for every
 * unrelated write, the completeness percentage is a function of the current
 * configuration (so a snapshot needs its version), and "this organization is
 * awaiting re-verification" has to survive the request that caused it.
 *
 * All columns are nullable, so every existing organization stays valid — an
 * organization that has never opened the profile simply has no metadata yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            if (! Schema::hasColumn('organizations', 'profile_updated_at')) {
                $table->timestamp('profile_updated_at')->nullable()->after('verified_at');
            }

            if (! Schema::hasColumn('organizations', 'profile_updated_by')) {
                $table->unsignedBigInteger('profile_updated_by')->nullable()->after('profile_updated_at');
            }

            if (! Schema::hasColumn('organizations', 'profile_completeness')) {
                $table->unsignedTinyInteger('profile_completeness')->nullable()->after('profile_updated_by');
            }

            // Which set of completeness rules produced the stored percentage.
            // Without it a stored 67 % is unreadable once the field list
            // changes: 67 % of what?
            if (! Schema::hasColumn('organizations', 'completeness_version')) {
                $table->string('completeness_version', 32)->nullable()->after('profile_completeness');
            }

            if (! Schema::hasColumn('organizations', 'reverification_required_at')) {
                $table->timestamp('reverification_required_at')->nullable()->after('completeness_version');
            }
        });

        Schema::table('organizations', function (Blueprint $table) {
            if (! Schema::hasIndex('organizations', 'organizations_reverification_required_at_index')) {
                $table->index('reverification_required_at', 'organizations_reverification_required_at_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            if (Schema::hasIndex('organizations', 'organizations_reverification_required_at_index')) {
                $table->dropIndex('organizations_reverification_required_at_index');
            }
        });

        Schema::table('organizations', function (Blueprint $table) {
            $columns = array_values(array_filter(
                [
                    'profile_updated_at',
                    'profile_updated_by',
                    'profile_completeness',
                    'completeness_version',
                    'reverification_required_at',
                ],
                fn (string $column) => Schema::hasColumn('organizations', $column),
            ));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
