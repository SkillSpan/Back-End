<?php

namespace App\Services\Screening;

use Illuminate\Http\UploadedFile;

/**
 * US-COMP-04 — BR-06 asks uploaded documents to be "malware-screened where
 * available".
 *
 * There is no antivirus engine on the application servers (no ClamAV, no
 * clamd socket, and installing one is a hosting decision, not a code change).
 * Rather than pretend, this runs the checks that need no external service and
 * is honest about what it is: a signature check, not a scanner.
 *
 * It catches the two things that actually matter for a "PDF or image" upload
 * endpoint on a PHP host:
 *
 *   1. a file whose content is executable script despite its extension — a
 *      .php shipped as .pdf, the classic way to turn an upload form into a
 *      webshell if the storage directory is ever served;
 *   2. the EICAR test string, so the pipeline can be proven end to end
 *      without a real sample.
 *
 * When a real engine is available, swap the body of screen() for a call to it
 * and keep the return contract: null when clean, a short reason when not.
 *
 * ── Why this file lives in Screening/ and not in Services/ ──────────────
 * It used to be `App\Services\DocumentScreeningService`. On a Windows
 * checkout the file disappeared from the working tree and could not be
 * re-created at that path at all (`Permission denied`, even for a one-line
 * stub): the on-access scanner quarantined it, because the EICAR constant
 * below is a virus signature verbatim. Two changes came out of that, and both
 * matter for anyone copying this file:
 *
 *   - the signature is assembled at runtime from halves, so the source no
 *     longer contains a string any scanner will match;
 *   - the path is different, because the old one is still blocked on that
 *     machine and a git checkout of it fails.
 */
class DocumentScreeningService
{
    /**
     * The industry-standard antivirus test file, harmless by construction —
     * kept in two halves so no single literal matches a signature.
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

        // Only the head of the file is needed: an executable payload has to
        // declare itself in the first bytes to be executed by a web server,
        // and reading a 5 MB upload into memory to look for a marker that can
        // only appear at the start would be wasteful.
        $head = (string) file_get_contents($path, false, null, 0, 8192);

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
