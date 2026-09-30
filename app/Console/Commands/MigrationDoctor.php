<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only audit for the "deployment fails because a table already exists"
 * class of problem.
 *
 * `php artisan migrate --force` fails with SQLSTATE[42S01] when a migration
 * file that is still unrecorded in the `migrations` table tries to CREATE a
 * table that the database already holds. That happens when the bookkeeping
 * table and the real schema have drifted apart, and the only correct fix is
 * to reconcile the bookkeeping — never to drop the table.
 *
 * This command performs no migration, no INSERT, no UPDATE and no DELETE. It
 * reports the drift and prints the reconciliation SQL for a human to review
 * and run deliberately. It exists because production (Clever Cloud) is only
 * reachable through the app's own environment, so a read-only Artisan command
 * is the least invasive way to look at it.
 */
class MigrationDoctor extends Command
{
    protected $signature = 'db:migration-doctor';

    protected $description = 'Read-only audit of migration files vs the migrations table (writes nothing)';

    public function handle(): int
    {
        $connection = config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        $this->info('Migration doctor - READ ONLY. No migration runs and no row is written.');
        $this->line("  connection: {$connection}");
        $this->line("  database  : {$database}");
        $this->newLine();

        $files = $this->migrationFiles();
        $recordedNames = collect();

        if (Schema::hasTable('migrations')) {
            $recorded = DB::table('migrations')->orderBy('batch')->orderBy('migration')->get(['migration', 'batch']);
            $recordedNames = $recorded->pluck('migration');

            $this->reportTableCounts($files, $recorded, $recordedNames);
        } else {
            $this->error('The `migrations` table does not exist on this database.');
            $this->line('Laravel would create it and replay every migration from scratch, failing');
            $this->line('with SQLSTATE[42S01] on the first table that already exists.');
            $this->line('Every migration below is therefore treated as unrecorded.');
        }

        $pending = $files->diff($recordedNames)->values();

        if ($pending->isEmpty()) {
            $this->newLine();
            $this->info('No pending migrations. `php artisan migrate --force` has nothing to run.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn("Pending migrations: {$pending->count()}");

        $alreadyApplied = [];
        $genuinelyNew = [];

        foreach ($pending as $name) {
            $targets = $this->targetsOf(database_path("migrations/{$name}.php"));
            $status = $this->targetStatus($targets);

            if ($targets['creates'] === [] && $targets['alters'] === []) {
                $verdict = 'no table operation -> safe to run';
                $genuinelyNew[] = $name;
            } elseif ($status['missing'] === []) {
                $verdict = 'every target table already exists -> already applied';
                $alreadyApplied[] = $name;
            } else {
                $verdict = 'target table(s) missing -> genuinely new';
                $genuinelyNew[] = $name;
            }

            $this->line("  - {$name}");
            $this->line('      creates: '.($targets['creates'] === [] ? '-' : implode(', ', $targets['creates'])));
            $this->line('      alters : '.($targets['alters'] === [] ? '-' : implode(', ', $targets['alters'])));

            if ($targets['drops'] !== []) {
                $this->line('      DROPS  : '.implode(', ', $targets['drops']).'  <- re-running this discards its rows');
            }

            $this->line("      status : {$verdict}");
        }

        $this->newLine();
        $this->printVerdict($alreadyApplied, $genuinelyNew);

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, string>
     */
    private function migrationFiles(): Collection
    {
        return collect((array) glob(database_path('migrations/*.php')))
            ->map(static fn (string $path): string => basename($path, '.php'))
            ->sort()
            ->values();
    }

    /**
     * @param  Collection<int, string>  $files
     * @param  Collection<int, object>  $recorded
     * @param  Collection<int, string>  $recordedNames
     */
    private function reportTableCounts(Collection $files, Collection $recorded, Collection $recordedNames): void
    {
        $this->line("  migration files : {$files->count()}");
        $this->line("  recorded rows   : {$recorded->count()}");
        $this->line('  batches         : '.$recorded->pluck('batch')->unique()->sort()->implode(', '));

        // A recorded value that carries a path or an extension can never match
        // a file name stem, so every migration looks pending and the deploy
        // replays the whole suite. This is a bookkeeping-format problem, not a
        // schema problem, and it is fixed by normalising those values.
        $malformed = $recordedNames
            ->filter(static fn (string $name): bool => str_contains($name, '/') || str_contains($name, '.php'))
            ->values();

        if ($malformed->isNotEmpty()) {
            $this->newLine();
            $this->error("Malformed `migration` values: {$malformed->count()} (they contain a path or a .php extension)");
            $malformed->take(5)->each(fn (string $name) => $this->line("  - {$name}"));
            $this->line('  Every file is treated as pending while these rows stay in that shape.');
        }

        // Rows whose file no longer exists: a renamed or deleted migration.
        // Harmless for `migrate`, but it is the signature of history drift.
        $orphans = $recordedNames->diff($files)->values();

        if ($orphans->isNotEmpty()) {
            $this->newLine();
            $this->warn("Recorded migrations with no matching file: {$orphans->count()}");
            $orphans->take(5)->each(fn (string $name) => $this->line("  - {$name}"));
        }
    }

    /**
     * Table operations declared by a migration's `up()` method.
     *
     * Comments are stripped first: a doc block that merely mentions
     * "Schema::create('users', ...)" must not be read as a real operation.
     * Only `up()` is scanned, because `down()` never runs during `migrate`
     * and its dropIfExists() calls are not a deployment hazard.
     *
     * @return array{creates: array<int, string>, alters: array<int, string>, drops: array<int, string>}
     */
    private function targetsOf(string $path): array
    {
        if (! is_file($path)) {
            return ['creates' => [], 'alters' => [], 'drops' => []];
        }

        $source = $this->upBody($this->stripComments((string) file_get_contents($path)));

        $collect = static function (string $pattern) use ($source): array {
            preg_match_all($pattern, $source, $matches);

            return array_values(array_unique($matches[1]));
        };

        return [
            'creates' => $collect("/Schema::create\(\s*'([^']+)'/"),
            'alters' => $collect("/Schema::table\(\s*'([^']+)'/"),
            'drops' => $collect("/Schema::dropIfExists\(\s*'([^']+)'/"),
        ];
    }

    private function upBody(string $source): string
    {
        $start = strpos($source, 'function up');

        if ($start === false) {
            return $source;
        }

        $end = strpos($source, 'function down', $start);

        return $end === false ? substr($source, $start) : substr($source, $start, $end - $start);
    }

    private function stripComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }

                $code .= $token[1];

                continue;
            }

            $code .= $token;
        }

        return $code;
    }

    /**
     * @param  array{creates: array<int, string>, alters: array<int, string>, drops: array<int, string>}  $targets
     * @return array{present: array<int, string>, missing: array<int, string>}
     */
    private function targetStatus(array $targets): array
    {
        $present = [];
        $missing = [];

        foreach (array_unique(array_merge($targets['creates'], $targets['alters'])) as $table) {
            if (Schema::hasTable($table)) {
                $present[] = $table;
            } else {
                $missing[] = $table;
            }
        }

        return ['present' => $present, 'missing' => $missing];
    }

    /**
     * @param  array<int, string>  $alreadyApplied
     * @param  array<int, string>  $genuinelyNew
     */
    private function printVerdict(array $alreadyApplied, array $genuinelyNew): void
    {
        if ($alreadyApplied !== []) {
            $this->warn('Already applied but unrecorded: '.count($alreadyApplied).' migration(s)');
            $this->line('Their tables exist on this database, so running them again is what');
            $this->line('produces SQLSTATE[42S01]. Record them in `migrations` WITHOUT running them.');

            $this->newLine();
            $this->printBaselineSql(collect($alreadyApplied), $this->nextBatch());
        }

        if ($genuinelyNew !== []) {
            $this->newLine();
            $this->info('Genuinely new or unclassified: '.count($genuinelyNew).' migration(s)');
            $this->line('These will run normally through `php artisan migrate --force` - once the');
            $this->line('already-applied ones above are recorded. Read their table list first: a');
            $this->line('migration that alters an existing table still needs its columns verified.');
        }
    }

    private function nextBatch(): int
    {
        if (! Schema::hasTable('migrations')) {
            return 1;
        }

        return ((int) DB::table('migrations')->max('batch')) + 1;
    }

    /**
     * @param  Collection<int, string>  $names
     */
    private function printBaselineSql(Collection $names, int $batch): void
    {
        $this->newLine();
        $this->line('--- REVIEW BEFORE RUNNING (this command does not run it) ---');
        $this->line('-- Read-only check first:');
        $this->line('--   SELECT migration, batch FROM migrations ORDER BY batch DESC, migration DESC;');
        $this->line('-- Then record the migrations that are already reflected in the schema:');

        $values = $names
            ->map(static fn (string $name): string => "('".$name."', ".$batch.')')
            ->implode(','.PHP_EOL.'  ');

        $this->line('INSERT INTO migrations (migration, batch) VALUES');
        $this->line('  '.$values.';');

        $this->line('-- Verify afterwards:');
        $this->line('--   php artisan migrate:status');
    }
}
