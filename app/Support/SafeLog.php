<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Throwable;

/**
 * Produces a log-safe description of a throwable.
 *
 * `$e->getMessage()` is not safe to log for a database failure, and the reason
 * is easy to miss:
 *
 *  1. `Illuminate\Database\QueryException` builds its message with
 *     `Str::replaceArray('?', $bindings, $sql)` — the bound values are
 *     substituted back into the SQL. A failed `insert`/`update`/`select`
 *     therefore writes the actual row data into the log: names, emails,
 *     phone numbers, message bodies, and password / token *hashes*.
 *  2. The underlying PDO message compounds it. MySQL echoes the offending
 *     value in its own text ("Duplicate entry 'someone@example.com' for key
 *     'users_email_unique'"), which `getMessage()` keeps at the front.
 *
 * So on any 500 the application was writing a copy of the very data that
 * failed to be stored — straight into `laravel.log`, which lives longer, is
 * shipped to more places, and is read by more people than the database.
 *
 * This helper keeps the part an operator actually needs — the SQL *shape*
 * (placeholders intact), the driver error code and the connection name — and
 * drops every value. Non-database throwables are returned unchanged: their
 * messages are written by this application, not by the driver, and carry no
 * bound data.
 */
final class SafeLog
{
    public static function reason(Throwable $e): string
    {
        if ($e instanceof QueryException) {
            return sprintf(
                'QueryException [%s] on connection [%s]: %s',
                (string) $e->getCode(),
                $e->getConnectionName() ?? 'default',
                // getSql() is the statement as the driver received it, with the
                // placeholders intact — the values live in getBindings(), which
                // is deliberately never read here.
                $e->getSql(),
            );
        }

        return $e->getMessage();
    }

    /**
     * A log-safe summary of an *upstream* service's failure body.
     *
     * The body of a failed response from another service is untrusted text this
     * application does not control. On a 5xx it can be a framework debug page
     * or traceback that echoes the request that was sent — and for the
     * intelligence calls that request is the learner's own record. Copying it
     * into our log would make the log a second copy of the data, in a place
     * with weaker access control and a longer retention.
     *
     * The one thing worth keeping is the service's own one-line explanation:
     * FastAPI answers a 5xx with `{"detail": "..."}`, and that string is what
     * actually diagnoses the failure. Anything else — HTML, a traceback, any
     * body that is not the documented shape — is summarised by size instead of
     * quoted, so an operator can still see that the service answered and how
     * much it said, and correlate it with the service's own logs.
     */
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
