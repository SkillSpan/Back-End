<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Read-only region + latency probe for the deployed service.
 *
 * Purpose: the app runs on Render (US West) and the database lives at a different
 * provider, so "the site is slow" can only be settled by measuring the real
 * round trip from the machine that actually talks to MySQL. That machine is the
 * container, not a laptop, so the numbers have to come from here.
 *
 * It reports nothing secret: the host is never echoed, only the region encoded
 * in its CNAME (`par-` = Paris, `mtl-` = Montreal, ...), the resolved IPs, and
 * timings. Two queries at most, both constant-cost, no writes.
 *
 * Reached on a fixed unguessable path rather than the open root so it does not
 * show up in a casual route scan.
 */
class SystemPulseController extends Controller
{
    /** Clever Cloud puts the region in the CNAME: <region>-mysql-<n>. */
    private const REGIONS = [
        'par' => 'Paris (FR)',
        'rbx' => 'Roubaix (FR)',
        'gra' => 'Gravelines (FR)',
        'mtl' => 'Montreal (CA)',
        'sbg' => 'Singapore (SG)',
        'waw' => 'Warsaw (PL)',
        'syd' => 'Sydney (AU)',
    ];

    public function show(): JsonResponse
    {
        $host = (string) config('database.connections.mysql.host');
        $cname = $this->cname($host);

        $connectMs = null;
        $selectMs = null;
        $error = null;

        try {
            $t0 = microtime(true);
            DB::connection()->getPdo();
            $connectMs = round((microtime(true) - $t0) * 1000, 1);

            $t1 = microtime(true);
            DB::select('select 1');
            $selectMs = round((microtime(true) - $t1) * 1000, 1);
        } catch (Throwable $e) {
            $error = mb_substr($e->getMessage(), 0, 160);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'database' => [
                    'region' => $this->region($cname),
                    'cname' => $cname,
                    'ips' => $this->ips($host),
                    'connect_ms' => $connectMs,
                    'select1_ms' => $selectMs,
                    'error' => $error,
                ],
                'app' => [
                    'env' => config('app.env'),
                    'debug' => (bool) config('app.debug'),
                    'php' => PHP_VERSION,
                    'cache_store' => config('cache.default'),
                ],
                'server' => [
                    'now' => now()->toIso8601String(),
                ],
            ],
        ]);
    }

    /** First CNAME hop for the database host, or an empty string. */
    private function cname(string $host): string
    {
        if ($host === '' || ! function_exists('dns_get_record')) {
            return '';
        }

        $records = @dns_get_record($host, DNS_CNAME);

        if (is_array($records) && isset($records[0]['target'])) {
            return (string) $records[0]['target'];
        }

        return '';
    }

    /** @return list<string> */
    private function ips(string $host): array
    {
        if ($host === '') {
            return [];
        }

        $ips = @gethostbynamel($host);

        return is_array($ips) ? array_values($ips) : [];
    }

    private function region(string $cname): string
    {
        $needle = strtolower($cname);

        foreach (self::REGIONS as $prefix => $label) {
            if (str_contains($needle, $prefix.'-mysql')) {
                return $label;
            }
        }

        return $cname === '' ? 'unknown (no cname)' : 'unknown ('.$cname.')';
    }
}
