<?php

namespace App\Services\Screening;

use Illuminate\Http\UploadedFile;

class DocumentScreeningService
{
    private const EICAR_PARTS = [
        'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-',
        'STANDARD-ANTIVIRUS-TEST-FILE!$H+H*',
    ];

    private const SCRIPT_MARKERS = ['<?php', '<?=', '<script', '#!/bin/'];

    private const STREAM_CHUNK = 8192;

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

    private function engineVerdict(string $path): array
    {
        $socket = config('company.profile.documents.clamav.socket');

        if (! is_string($socket) || trim($socket) === '') {
            return ['checked' => false, 'reason' => null];
        }

        $timeout = (int) config('company.profile.documents.clamav.timeout', 10);

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

        return str_contains($reply, 'OK')
            ? ['checked' => true, 'reason' => null]
            : ['checked' => false, 'reason' => null];
    }

    private function signatureVerdict(string $path): ?string
    {
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
