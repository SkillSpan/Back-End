<?php

namespace App\Services\Screening;

use Illuminate\Http\UploadedFile;

/**
 * US-COMP-04 — BR-06: uploaded documents are "type-checked, size-limited,
 * malware-screened **where available**, and access-controlled".
 *
 * The type and size checks live in the controller (they need the request); the
 * access control lives in the service's `files()` lookup. This class is the
 * "where available" half, and it takes that phrase literally:
 *
 *   1. **A real engine, when one is configured.** Set
 *      `COMPANY_CLAMAV_SOCKET=unix:///var/run/clamav/clamd.ctl` (or
 *      `tcp://127.0.0.1:3310`) and every upload is streamed to clamd over the
 *      INSTREAM protocol before it is stored.
 *   2. **A signature check, when one is not.** No antivirus is installed on the
 *      app servers today, and installing one is a hosting decision rather than
 *      a code change — so the default path catches the two things that actually
 *      matter for a "PDF or image" upload on a PHP host: an executable payload
 *      wearing a document extension, and the EICAR test string (which lets the
 *      pipeline be proven end to end without a real sample).
 *
 * **An unreachable engine never blocks an upload.** If the socket is
 * configured but clamd is down, the connection fails, the check falls through
 * to the signature pass, and the upload proceeds. Refusing every document
 * because a scanner is offline would turn a security control into an outage;
 * the fallback is deliberate, and `engineVerdict()` says so.
 *
 * When a real engine IS reachable its verdict is final — a clean signature does
 * not override `FOUND`.
 */
class DocumentScreeningService
{
    /**
     * The industry-standard antivirus test file, harmless by construction —
     * kept in two halves so no single literal matches a signature.
     *
     * That is not cosmetic: with the string written out whole, an on-access
     * scanner quarantined this file on a Windows checkout and the path could
     * not even be re-created. Assembled at runtime, no half matches, and the
     * comparison is still against the real value.
     *
     * @var array<int, string>
     */
    private const EICAR_PARTS = [
        'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-',
        'STANDARD-ANTIVIRUS-TEST-FILE!$H+H*',
    ];

    /**
     * Script openers that have no business inside a PDF or an image.
     *
     * Every marker is at least three bytes long on purpose. Two-byte needles
     * such as `<?` or `<%` would match by chance inside the binary payload of
     * a real JPEG (roughly one in eight files at this read length), turning
     * the check into a random rejection. `<?php`, `<?=`, `<script` and a
     * shebang are long enough that a false positive is negligible, and they
     * are what an uploaded "PDF" actually has to contain to be dangerous.
     */
    private const SCRIPT_MARKERS = ['<?php', '<?=', '<script', '#!/bin/'];

    /** Bytes per INSTREAM chunk. clamd's own limit is 128 KB; 8 KB is plenty. */
    private const STREAM_CHUNK = 8192;

    /**
     * @return string|null null when the file looks clean, otherwise a short
     *                     reason suitable for a 422 body (never an internal path)
     */
    public function screen(UploadedFile $file): ?string
    {
        if (! (bool) config('company.profile.documents.scan', true)) {
            return null;
        }

        $path = $file->getRealPath();

        if ($path === false || ! is_readable($path)) {
            return 'The uploaded file could not be read.';
        }

        $engine = $this->engineVerdict($path);

        if ($engine['checked']) {
            return $engine['reason'];
        }

        return $this->signatureVerdict($path);
    }

    /**
     * Ask the configured engine, if there is one.
     *
     * `checked` is false for every failure mode — no socket configured, socket
     * unreachable, unexpected reply — and the caller then falls back. A clean
     * engine reply is `checked: true, reason: null`; an infected one is
     * `checked: true` with a reason, which the caller must not override.
     *
     * @return array{checked: bool, reason: string|null}
     */
    private function engineVerdict(string $path): array
    {
        $socket = config('company.profile.documents.clamav.socket');

        if (! is_string($socket) || trim($socket) === '') {
            return ['checked' => false, 'reason' => null];
        }

        $timeout = (int) config('company.profile.documents.clamav.timeout', 10);

        // `@` because a missing socket is an expected state, not an error the
        // user should ever see in a log line per upload.
        $connection = @stream_socket_client($socket, $errno, $error, $timeout);

        if ($connection === false) {
            return ['checked' => false, 'reason' => null];
        }

        $reply = '';

        try {
            stream_set_timeout($connection, $timeout);
            fwrite($connection, "zINSTREAM\0");

            $handle = @fopen($path, 'rb');

            if ($handle === false) {
                return ['checked' => false, 'reason' => null];
            }

            while (! feof($handle)) {
                $chunk = fread($handle, self::STREAM_CHUNK);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                fwrite($connection, pack('N', strlen($chunk)).$chunk);
            }

            fclose($handle);

            // A zero-length chunk ends the stream.
            fwrite($connection, pack('N', 0));
            $reply = (string) stream_get_contents($connection);
        } catch (\Throwable) {
            return ['checked' => false, 'reason' => null];
        } finally {
            if (is_resource($connection)) {
                fclose($connection);
            }
        }

        if (str_contains($reply, 'FOUND')) {
            return ['checked' => true, 'reason' => 'The file was rejected by the security check.'];
        }

        // `stream: OK` is the only reply that counts as a clean scan; anything
        // else (an error line, an empty read, a timeout) means the engine did
        // not actually judge this file.
        return str_contains($reply, 'OK')
            ? ['checked' => true, 'reason' => null]
            : ['checked' => false, 'reason' => null];
    }

    /**
     * The always-available pass: the head of the file, checked for an
     * executable payload and for the EICAR signature.
     */
    private function signatureVerdict(string $path): ?string
    {
        // Only the head of the file is needed: an executable payload has to
        // declare itself in the first bytes to be executed by a web server,
        // and reading a 5 MB upload into memory to look for a marker that can
        // only appear at the start would be wasteful.
        $head = (string) file_get_contents($path, false, null, 0, self::STREAM_CHUNK);

        if (str_contains($head, implode('', self::EICAR_PARTS))) {
            return 'The file was rejected by the security check.';
        }

        $lower = strtolower($head);

        foreach (self::SCRIPT_MARKERS as $marker) {
            if (str_contains($lower, $marker)) {
                return 'The file contains executable content and was rejected.';
            }
        }

        return null;
    }
}
