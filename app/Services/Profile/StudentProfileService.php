<?php

namespace App\Services\Profile;

use App\Exceptions\ReadinessException;
use App\Models\StudentProfile;
use App\Models\User;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The learner's own profile — the only writer of `student_profiles.avatar_*`.
 *
 * Everything here is scoped to the acting user: the caller passes the
 * authenticated `User` and never an id, so there is no path by which one
 * learner can touch another's avatar.
 *
 * The avatar is decoded, centre-cropped to a square and re-encoded at a fixed
 * size before storage (see AdminProfileService for the rationale): that caps the
 * database cost and strips the original's metadata, and the stored mime is the
 * format *we* produced rather than the one the client claimed.
 */
class StudentProfileService
{
    /** Formats GD can decode and we are willing to re-encode from. */
    private const ACCEPTED_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /**
     * The profile row for a user, created empty on first access.
     */
    public function profileFor(User $user): StudentProfile
    {
        return $user->studentProfile ?? StudentProfile::forceCreate(['user_id' => $user->id]);
    }

    /**
     * Decode, centre-crop and store an uploaded avatar.
     *
     * @throws ReadinessException 422 when the bytes are not a usable image.
     */
    public function storeAvatar(User $user, UploadedFile $file): StudentProfile
    {
        // Detect PHP-level upload errors BEFORE reading any bytes. Without this
        // guard a phone photo larger than PHP's `upload_max_filesize` (2 MB by
        // default on the CLI image) would be silently truncated mid-stream:
        // the first few KB would arrive intact — enough for
        // `getimagesizefromstring` to identify the format — but the body would
        // be cut off, so `imagecreatefromstring` then fails on the truncated
        // bytes with the opaque "could not be processed" message. Tell the
        // caller exactly what PHP rejected and why.
        $error = $file->getError();
        if ($error !== UPLOAD_ERR_OK) {
            throw new ReadinessException(
                $this->uploadErrorMessage($error, $file),
                422,
                'PROFILE_AVATAR_INVALID',
            );
        }

        $binary = $file->get();

        if (! is_string($binary) || $binary === '') {
            throw new ReadinessException(
                'The uploaded file could not be read.',
                422,
                'PROFILE_AVATAR_INVALID',
            );
        }

        // Cheap structural check before any decoding.
        $info = @getimagesizefromstring($binary);

        if ($info === false || ! in_array($info['mime'] ?? null, self::ACCEPTED_MIMES, true)) {
            throw new ReadinessException(
                'The uploaded file is not a supported image. Use a JPEG, PNG, WebP or GIF.',
                422,
                'PROFILE_AVATAR_INVALID',
            );
        }

        // The header read is cheap and attacker-controlled; the decode that
        // follows allocates width * height * 4 bytes, so refuse here first.
        $this->assertDecodableSize($info);

        [$encoded, $mime] = $this->squareAvatar($binary, $info['mime']);

        $profile = $this->profileFor($user);

        $profile->forceFill([
            'avatar_data' => base64_encode($encoded),
            'avatar_mime' => $mime,
            'avatar_updated_at' => now(),
        ])->save();

        Log::info('Learner avatar updated.', [
            'user_id' => $user->id,
            'mime' => $mime,
            'bytes' => strlen($encoded),
        ]);

        return $profile;
    }

    public function removeAvatar(User $user): StudentProfile
    {
        $profile = $this->profileFor($user);

        $profile->forceFill([
            'avatar_data' => null,
            'avatar_mime' => null,
            'avatar_updated_at' => null,
        ])->save();

        Log::info('Learner avatar removed.', ['user_id' => $user->id]);

        return $profile;
    }

    /**
     * The stored image as raw bytes, or null when there is none.
     */
    public function avatarBinary(StudentProfile $profile): ?string
    {
        if (! $profile->hasAvatar()) {
            return null;
        }

        $decoded = base64_decode((string) $profile->avatar_data, true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * Browser-facing URL for the avatar, or null when there is none.
     */
    public function avatarUrl(StudentProfile $profile): ?string
    {
        if (! $profile->hasAvatar()) {
            return null;
        }

        return route('learner.profile.avatar', ['v' => $profile->avatarVersion()]);
    }

    /**
     * Refuse an image whose declared dimensions would exhaust memory when
     * decoded — a decompression bomb.
     *
     * @param  array<int|string, mixed>  $info
     *
     * @throws ReadinessException 422 when the declared dimensions are too large
     */
    private function assertDecodableSize(array $info): void
    {
        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);

        $maxDimension = max(1, (int) config('services.profile.avatar_max_dimension', 8000));
        $maxPixels = max(1, (int) config('services.profile.avatar_max_pixels', 25000000));

        if ($width > $maxDimension || $height > $maxDimension || ($width * $height) > $maxPixels) {
            Log::warning('Learner avatar refused: declared dimensions exceed the decode ceiling.', [
                'width' => $width,
                'height' => $height,
            ]);

            throw new ReadinessException(
                'The uploaded image is too large to process. The maximum is '
                    .$maxDimension.'x'.$maxDimension.' pixels.',
                422,
                'PROFILE_AVATAR_INVALID',
            );
        }
    }

    /**
     * Centre-crop to a square and re-encode at the configured size.
     *
     * @return array{0: string, 1: string} encoded bytes, and the mime produced
     *
     * @throws ReadinessException
     */
    private function squareAvatar(string $binary, string $sourceMime): array
    {
        $source = @imagecreatefromstring($binary);

        if (! $source instanceof GdImage) {
            throw new ReadinessException(
                'The uploaded image could not be processed.',
                422,
                'PROFILE_AVATAR_INVALID',
            );
        }

        try {
            $width = imagesx($source);
            $height = imagesy($source);

            if ($width < 1 || $height < 1) {
                throw new ReadinessException(
                    'The uploaded image has no usable pixels.',
                    422,
                    'PROFILE_AVATAR_INVALID',
                );
            }

            $size = max(32, (int) config('services.profile.avatar_size', 256));
            $side = min($width, $height);
            $sourceX = (int) (($width - $side) / 2);
            $sourceY = (int) (($height - $side) / 2);

            $target = imagecreatetruecolor($size, $size);

            imagealphablending($target, false);
            imagesavealpha($target, true);
            $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
            imagefilledrectangle($target, 0, 0, $size, $size, $transparent);
            imagealphablending($target, true);

            imagecopyresampled(
                $target, $source,
                0, 0, $sourceX, $sourceY,
                $size, $size, $side, $side,
            );

            $keepAlpha = in_array($sourceMime, ['image/png', 'image/webp', 'image/gif'], true);

            ob_start();

            if ($keepAlpha) {
                imagepng($target, null, 6);
                $mime = 'image/png';
            } else {
                imagejpeg($target, null, 85);
                $mime = 'image/jpeg';
            }

            $encoded = (string) ob_get_clean();
            imagedestroy($target);
        } catch (Throwable $e) {
            imagedestroy($source);

            throw $e;
        }

        imagedestroy($source);

        if ($encoded === '') {
            throw new ReadinessException(
                'The uploaded image could not be encoded.',
                422,
                'PROFILE_AVATAR_INVALID',
            );
        }

        return [$encoded, $mime];
    }

    /**
     * Translate a PHP `UPLOAD_ERR_*` code into a message that tells the
     * learner what to do, not just what went wrong server-side.
     *
     * `UPLOAD_ERR_INI_SIZE` is the case that came out of the dashboard bug:
     * a phone photo larger than `upload_max_filesize` was silently truncated
     * to a 2 MB slice, and the truncated bytes then surfaced downstream as
     * a generic "could not be processed". The fix is twofold — raise the
     * limit in the Dockerfile (so the photo survives the upload), AND give
     * the caller an actionable message when PHP itself rejects the upload.
     */
    private function uploadErrorMessage(int $code, UploadedFile $file): string
    {
        $maxKb = (int) config('services.profile.avatar_max_upload_kb', 4096);

        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => sprintf(
                'The photo is too large to upload (limit %d KB). Please pick a smaller image.',
                $maxKb,
            ),
            UPLOAD_ERR_PARTIAL => 'The upload was cut off before it finished. Please try again.',
            UPLOAD_ERR_NO_FILE => 'No photo was received. Please pick a file and try again.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server could not store the upload. Please try again in a moment.',
            UPLOAD_ERR_EXTENSION => 'The photo was rejected by the server. Please try a JPEG, PNG, WebP or GIF.',
            default => 'The photo could not be uploaded. Please try a JPEG, PNG, WebP or GIF under '
                .$maxKb.' KB.',
        };
    }
}
