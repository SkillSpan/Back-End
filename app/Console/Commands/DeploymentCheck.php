<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use Throwable;

/**
 * Read-only post-deployment check: config sanity, DB connectivity, the region
 * the database actually lives in, and how long a query really takes.
 *
 * It exists because the app and the database can end up on different
 * continents without anything in the code looking wrong: the page is slow,
 * every query pays the round trip, and no test fails. This command makes that
 * visible in one screen.
 *
 * It writes nothing: no migration, no INSERT/UPDATE/DELETE. The only queries
 * are SELECT 1 and a COUNT(*) on `users`.
 */
class DeploymentCheck extends Command
{
    protected $signature = 'app:deployment-check {--json : Print a machine-readable report}';

    protected $description = 'Read-only check of config, DB region/connectivity and query latency';

    public function handle(): int
    {
        $failures = [];

        $app = [
            'environment' => app()->environment(),
            'debug' => (bool) config('app.debug'),
            'app_key_set' => filled(config('app.key')),
            'app_url' => (string) config('app.url'),
            'config_cached' => app()->configurationIsCached(),
            'routes_cached' => app()->routesAreCached(),
            'routes' => count(app('router')->getRoutes()),
        ];

        if (! $app['app_key_set']) {
            $failures[] = 'APP_KEY is not set — encryption and sessions will fail.';
        }
        if ($app['environment'] === 'production' && $app['debug']) {
            $failures[] = 'APP_DEBUG is true in production — stack traces leak to users.';
        }

        $connectionName = (string) config('database.default');
        $connection = (array) config("database.connections.{$connectionName}", []);
        $host = (string) ($connection['host'] ?? '');
        $port = (int) ($connection['port'] ?? 3306);

        $db = [
            'connection' => $connectionName,
            'host' => $this->maskHost($host),
            'region' => $this->regionHint($host),
            'database' => (string) ($connection['database'] ?? ''),
            'username_set' => filled($connection['username'] ?? null),
            'password_set' => filled($connection['password'] ?? null),
        ];

        if (! $db['username_set']) {
            $failures[] = 'DB_USERNAME is empty.';
        }
        if (! $db['password_set']) {
            $failures[] = 'DB_PASSWORD is empty.';
        }

        // --- connectivity & latency (never throws; reports instead) ---------
        $latency = [
            'tcp_connect_ms' => $this->tcpConnectMs($host, $port),
            'pdo_connect_ms' => null,
            'select_1_avg_ms' => null,
            'select_1_max_ms' => null,
            'users_count_ms' => null,
        ];

        $pdo = null;
        try {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $db['database']);
            $started = hrtime(true);
            $pdo = new PDO($dsn, (string) ($connection['username'] ?? ''), (string) ($connection['password'] ?? ''), [
                PDO::ATTR_TIMEOUT => 5,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $latency['pdo_connect_ms'] = $this->ms($started);
        } catch (Throwable $e) {
            $failures[] = 'PDO could not connect: '.$e->getMessage();
        }

        if ($pdo instanceof PDO) {
            try {
                $samples = [];
                for ($i = 0; $i < 5; $i++) {
                    $started = hrtime(true);
                    $pdo->query('SELECT 1')->fetchColumn();
                    $samples[] = $this->ms($started);
                }
                $latency['select_1_avg_ms'] = round(array_sum($samples) / count($samples), 1);
                $latency['select_1_max_ms'] = max($samples);
            } catch (Throwable $e) {
                $failures[] = 'SELECT 1 failed: '.$e->getMessage();
            }
        }

        $migrations = [
            'table_exists' => false,
            'recorded' => 0,
            'files' => 0,
            'pending' => 0,
        ];

        try {
            $migrations['table_exists'] = Schema::hasTable('migrations');
            $migrations['files'] = count(glob(database_path('migrations').'/*.php') ?: []);

            if ($migrations['table_exists']) {
                $recorded = DB::table('migrations')->pluck('migration')->all();
                $migrations['recorded'] = count($recorded);
                $files = array_map(
                    static fn (string $path): string => basename($path, '.php'),
                    glob(database_path('migrations').'/*.php') ?: [],
                );
                $migrations['pending'] = count(array_diff($files, $recorded));
            } else {
                $failures[] = 'The `migrations` table does not exist.';
            }

            $started = hrtime(true);
            DB::table('users')->count();
            $latency['users_count_ms'] = $this->ms($started);
        } catch (Throwable $e) {
            $failures[] = 'A read query failed: '.$e->getMessage();
        }

        if ($migrations['pending'] > 0) {
            $failures[] = $migrations['pending'].' migration(s) recorded on disk but not applied.';
        }

        // The threshold is deliberately generous: the point is to catch a
        // cross-continent round trip, not to grade a busy server.
        if ($latency['select_1_avg_ms'] !== null && $latency['select_1_avg_ms'] > 100) {
            $failures[] = sprintf(
                'SELECT 1 averages %.1f ms — that is cross-region distance, not query cost.',
                $latency['select_1_avg_ms'],
            );
        }

        $report = [
            'app' => $app,
            'database' => $db,
            'latency' => $latency,
            'migrations' => $migrations,
            'failures' => $failures,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $failures === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->info('Deployment check — READ ONLY (no migration, no writes).');
        $this->newLine();

        $this->line('<comment>Application</comment>');
        $this->line('  environment     : '.$app['environment']);
        $this->line('  debug           : '.($app['debug'] ? 'true (should be false in production)' : 'false'));
        $this->line('  APP_KEY set     : '.($app['app_key_set'] ? 'yes' : 'NO'));
        $this->line('  config cached   : '.($app['config_cached'] ? 'yes' : 'no'));
        $this->line('  routes cached   : '.($app['routes_cached'] ? 'yes' : 'no'));
        $this->line('  routes          : '.$app['routes']);

        $this->newLine();
        $this->line('<comment>Database</comment>');
        $this->line('  connection      : '.$db['connection']);
        $this->line('  host            : '.$db['host']);
        $this->line('  region          : '.$db['region']);
        $this->line('  database        : '.$db['database']);
        $this->line('  credentials     : '.($db['username_set'] && $db['password_set'] ? 'set' : 'INCOMPLETE'));

        $this->newLine();
        $this->line('<comment>Latency</comment>');
        $this->line('  TCP connect     : '.$this->fmt($latency['tcp_connect_ms']));
        $this->line('  PDO connect     : '.$this->fmt($latency['pdo_connect_ms']));
        $this->line('  SELECT 1 avg    : '.$this->fmt($latency['select_1_avg_ms']));
        $this->line('  SELECT 1 max    : '.$this->fmt($latency['select_1_max_ms']));
        $this->line('  users COUNT(*)  : '.$this->fmt($latency['users_count_ms']));

        $this->newLine();
        $this->line('<comment>Migrations</comment>');
        $this->line('  migrations table: '.($migrations['table_exists'] ? 'present' : 'MISSING'));
        $this->line('  recorded / files: '.$migrations['recorded'].' / '.$migrations['files']);
        $this->line('  pending         : '.$migrations['pending']);

        $this->newLine();
        if ($failures === []) {
            $this->info('All checks passed.');

            return self::SUCCESS;
        }

        $this->error(count($failures).' issue(s) found:');
        foreach ($failures as $failure) {
            $this->line('  - '.$failure);
        }

        return self::FAILURE;
    }

    private function ms(int $startedNs): float
    {
        return round((hrtime(true) - $startedNs) / 1_000_000, 1);
    }

    private function fmt(?float $value): string
    {
        return $value === null ? 'n/a' : $value.' ms';
    }

    private function tcpConnectMs(string $host, int $port): ?float
    {
        if ($host === '') {
            return null;
        }

        $started = hrtime(true);
        $socket = @fsockopen($host, $port, $errno, $errstr, 5);
        $elapsed = $this->ms($started);

        if ($socket === false) {
            return null;
        }

        fclose($socket);

        return $elapsed;
    }

    /**
     * `b6ad...-mysql.services.clever-cloud.com` is not self-describing, but its
     * CNAME is (`par-mysql-c6...`). The prefix is the region: par=Paris,
     * rbx=Roubaix, mtl=Montreal, …
     */
    private function regionHint(string $host): string
    {
        if ($host === '') {
            return 'unknown';
        }

        $target = '';
        try {
            $records = @dns_get_record($host, DNS_CNAME);
            if (is_array($records) && isset($records[0]['target'])) {
                $target = (string) $records[0]['target'];
            }
        } catch (Throwable) {
            // DNS is best-effort; the rest of the report still stands.
        }

        $candidates = [$target, $host];
        foreach ($candidates as $candidate) {
            if (preg_match('/(?:^|\.)([a-z]{3})-mysql/', $candidate, $matches) === 1) {
                return match ($matches[1]) {
                    'par' => 'Paris (EU)',
                    'rbx' => 'Roubaix (EU)',
                    'mtl' => 'Montreal (CA)',
                    'syd' => 'Sydney (AU)',
                    default => $matches[1].' (unmapped)',
                };
            }
        }

        return 'unknown';
    }

    private function maskHost(string $host): string
    {
        if ($host === '') {
            return '(empty)';
        }

        $dot = strpos($host, '.');
        if ($dot === false || $dot < 6) {
            return substr($host, 0, 4).'***';
        }

        return substr($host, 0, 4).'***'.substr($host, $dot);
    }
}
