<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Throwable;

final class SafeLog
{
    public static function reason(Throwable $e): string
    {
        if ($e instanceof QueryException) {
            return sprintf(
                'QueryException [%s] on connection [%s]: %s',
                (string) $e->getCode(),
                $e->getConnectionName() ?? 'default',
                $e->getSql(),
            );
        }

        return $e->getMessage();
    }

    public static function upstreamBody(string $body): string
    {
        $body = trim($body);

        if ($body === '') {
            return '<empty body>';
        }

        $decoded = json_decode($body, true);
        $detail = is_array($decoded) ? ($decoded['detail'] ?? null) : null;

        if (is_string($detail) && trim($detail) !== '') {
            $detail = trim(preg_replace('/\s+/', ' ', $detail) ?? $detail);

            return mb_substr($detail, 0, 200);
        }

        return sprintf('<%d-byte upstream body omitted>', strlen($body));
    }
}
