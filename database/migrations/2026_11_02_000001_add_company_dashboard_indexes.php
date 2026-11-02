<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * US-COMP-03 — indexes for the company dashboard queries.
 *
 * The dashboard runs three aggregate queries on every load (project counts by
 * state, occupied seats, application funnel) and one notification badge count.
 * Each one filters on exactly the column pair indexed below, so without these
 * the database scans the whole table and BR-12 / NFR-PERF-06 ("avoid unbounded
 * scans") is not met.
 *
 * Additive only, and guarded: `Schema::hasIndex()` means re-running the
 * migration on an environment where the index already exists is a no-op
 * rather than a duplicate-key error. Nothing is dropped, no column changes.
 */
return new class extends Migration
{
    /**
     * @var array<int, array{0: string, 1: array<int, string>, 2: string}>
     */
    private array $indexes = [
        // `where organization_id = ? group by status` — the project-count and
        // recent-projects queries.
        ['projects', ['organization_id', 'status'], 'projects_organization_status_index'],

        // `join projects ... where applications.status in (...)` and the funnel
        // group-by.
        ['applications', ['project_id', 'status'], 'applications_project_status_index'],

        // `where user_id = ? and read_at is null` — the unread badge, which the
        // sidebar polls.
        ['notifications', ['user_id', 'read_at'], 'notifications_user_read_index'],
    ];

    public function up(): void
    {
        foreach ($this->indexes as [$table, $columns, $name]) {
            if (! Schema::hasTable($table) || Schema::hasIndex($table, $name)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($columns, $name) {
                $blueprint->index($columns, $name);
            });
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as [$table, $columns, $name]) {
            if (! Schema::hasTable($table) || ! Schema::hasIndex($table, $name)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($name) {
                $blueprint->dropIndex($name);
            });
        }
    }
};
