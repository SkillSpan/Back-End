<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class SystemPulseController extends Controller
{
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
