<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * US-COMP-04 — documents are versioned, not overwritten.
 *
 * Scenario 4 requires that "the previous document reference remains in the
 * audit history", and the Database task list asks for a "document version and
 * replaced-document reference". So a replacement inserts a NEW row and points
 * at the one it supersedes; nothing is deleted and nothing is updated in
 * place. `version` makes the sequence readable without walking the chain, and
 * `original_name` keeps the name the user uploaded (the stored path is
 * hashed/opaque, so it is the only human-readable label we have).
 *
 * `screened_at` records when the content check ran. It is nullable on purpose:
 * rows written before this migration, and any deployment with scanning turned
 * off, must stay valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uploaded_files', function (Blueprint $table) {
            if (! Schema::hasColumn('uploaded_files', 'version')) {
                $table->unsignedInteger('version')->default(1)->after('type');
            }

            if (! Schema::hasColumn('uploaded_files', 'replaces_id')) {
                $table->unsignedBigInteger('replaces_id')->nullable()->after('version');
            }

            if (! Schema::hasColumn('uploaded_files', 'original_name')) {
                $table->string('original_name')->nullable()->after('path');
            }

            if (! Schema::hasColumn('uploaded_files', 'screened_at')) {
                $table->timestamp('screened_at')->nullable()->after('status');
            }
        });

        Schema::table('uploaded_files', function (Blueprint $table) {
            if (! Schema::hasIndex('uploaded_files', 'uploaded_files_replaces_id_index')) {
                $table->index('replaces_id', 'uploaded_files_replaces_id_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('uploaded_files', function (Blueprint $table) {
            if (Schema::hasIndex('uploaded_files', 'uploaded_files_replaces_id_index')) {
                $table->dropIndex('uploaded_files_replaces_id_index');
            }
        });

        Schema::table('uploaded_files', function (Blueprint $table) {
            $columns = array_values(array_filter(
                ['version', 'replaces_id', 'original_name', 'screened_at'],
                fn (string $column) => Schema::hasColumn('uploaded_files', $column),
            ));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
