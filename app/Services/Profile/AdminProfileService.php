<?php

namespace App\Services\Profile;

use App\Exceptions\ReadinessException;
use App\Models\AdminProfile;
use App\Models\User;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The panel user's own profile — the only writer of `admin_profiles`.
 *
 * Everything here is scoped to the acting user: the caller passes the
 * authenticated `User` and never an id, so there is no code path in which one
 * account can edit another's profile.
 *
 * ## Why the avatar is processed rather than stored as uploaded
 *
 * The bytes go into the row (see the migration), so their size is a database
 * cost that every read pays. An unbounded upload would therefore be a way for
 * any panel user to bloat the table. Every avatar is decoded, centre-cropped to
 * a square and re-encoded at a fixed size before it is stored, which both caps
 * the cost and strips whatever metadata the original carried (EXIF GPS included).
 *
 * Re-encoding from the decoded pixels — rather than trusting the uploaded file —
 * is also what makes the mime check meaningful: the stored `avatar_mime` is the
 * format *we* produced, not the one the client claimed.
 */
class AdminProfileService
{
    /** Formats GD can decode and we are willing to re-encode from. */
    private const ACCEPTED_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /**
     * The profile row for a user, created empty on first access.
     *
     * `firstOrCreate` rather than a migration-time backfill: a profile only
     * exists once someone opens the page, so the table stays proportional to
     * panel users who actually use it.
     */
    public function profileFor(User $user): AdminProfile
    {
        return AdminProfile::firstOrCreate(['user_id' => $user->id]);
    }

    /**
     * Apply the editable details.
     *
     * `name` lives on `users`; everything else lives on the profile. Only the
     * keys the caller actually sent are touched, because the request rules are
     * `sometimes` — an absent key means "leave it", while an explicit `null`
     * means "clear it".
     *
     * @param  array<string, mixed>  $input
     */
    public function updateDetails(User $user, array $input): AdminProfile
    {
        return DB::transaction(function () use ($user, $input) {
            if (array_key_exists('name', $input)) {
                $user->name = trim((string) $input['name']);
                $user->save();
            }

            $profile = $this->profileFor($user);

            $profile->fill(array_intersect_key($input, array_flip([
                'display_title',
                'bio',
                'age',
            ])));

            // Blank strings are stored as NULL rather than as empty text. The UI
            // renders "not set" for both, but a NULL keeps "never filled in"
            // distinguishable from "deliberately emptied" for anything that
            // later queries the column.
            foreach (['display_title', 'bio'] as $field) {
                if (array_key_exists($field, $input) && is_string($profile->{$field})) {
                    $trimmed = trim($profile->{$field});
                    $profile->{$field} = $trimmed === '' ? null : $trimmed;
                }
            }

            $profile->save();

            Log::info('Panel profile updated.', [
                'user_id' => $user->id,
                'fields' => array_keys($input),
            ]);

            return $profile;
        });
    }

    /**
     * Decode, centre-crop and store an uploaded avatar.
     *
     * @throws ReadinessException 422 when the bytes are not a usable image.
     */
    public function storeAvatar(User $user, UploadedFile $file): AdminProfile
    {
        $binary = $file->get();

        if (! is_string($binary) || $binary === '') {
            throw new ReadinessException(
                'The uploaded file could not be read.',
                422,
                'PROFILE_AVATAR_INVALID',
            );
        }

        // Cheap structural check before any decoding: `getimagesizefromstring`
        // reads only the header, so a non-image is rejected without allocating
        // a bitmap for it.
        $info = @getimagesizefromstring($binary);

        if ($info === false || ! in_array($info['mime'] ?? null, self::ACCEPTED_MIMES, true)) {
            throw new ReadinessException(
                'The uploaded file is not a supported image. Use a JPEG, PNG, WebP or GIF.',
                422,
                'PROFILE_AVATAR_INVALID',
            );
        }

        // The header read above is cheap and attacker-controlled, and the decode
        // that follows allocates width * height * 4 bytes — so the declared size
        // has to be refused here, between the two, before any bitmap exists.
        $this->assertDecodableSize($info);

        [$encoded, $mime] = $this->squareAvatar($binary, $info['mime']);

        $profile = $this->profileFor($user);

        $profile->forceFill([
            'avatar_data' => base64_encode($encoded),
            'avatar_mime' => $mime,
            'avatar_updated_at' => now(),
        ])->save();

        Log::info('Panel avatar updated.', [
            'user_id' => $user->id,
            'mime' => $mime,
            'bytes' => strlen($encoded),
        ]);

        return $profile;
    }

    public function removeAvatar(User $user): AdminProfile
    {
        $profile = $this->profileFor($user);

        $profile->forceFill([
            'avatar_data' => null,
            'avatar_mime' => null,
            'avatar_updated_at' => null,
        ])->save();

        Log::info('Panel avatar removed.', ['user_id' => $user->id]);

        return $profile;
    }

    /**
     * The stored image as raw bytes, or null when there is none.
     *
     * Base64 is decoded here rather than in the controller so the transport
     * layer never has to know the storage format.
     */
    public function avatarBinary(AdminProfile $profile): ?string
    {
        if (! $profile->hasAvatar()) {
            return null;
        }

        $decoded = base64_decode((string) $profile->avatar_data, true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * Replace the password after verifying the current one.
     *
     * The current password is required even though the caller already holds an
     * authenticated session: without it, a walk-up to an unlocked browser would
     * be enough to take the account over permanently.
     *
     * @throws ReadinessException 422 when the current password does not match.
     */
    public function changePassword(User $user, string $currentPassword, string $newPassword): void
    {
        if (! Hash::check($currentPassword, (string) $user->password)) {
            Log::warning('Panel password change refused: current password did not match.', [
                'user_id' => $user->id,
            ]);

            throw new ReadinessException(
                'The current password is not correct.',
                422,
                'PROFILE_CURRENT_PASSWORD_INCORRECT',
            );
        }

        // The `password` cast hashes on assignment, so the plaintext never
        // reaches the column and no explicit Hash::make() is needed here.
        $user->password = $newPassword;
        $user->save();

        Log::info('Panel password changed.', ['user_id' => $user->id]);
    }

    /**
     * Refuse an image whose declared dimensions would exhaust memory when
     * decoded.
     *
     * `getimagesizefromstring` reads only the header, so the numbers are
     * attacker-controlled and cost almost nothing to inflate: a ~30 byte PNG
     * header can declare 40000x40000 pixels. `imagecreatefromstring` then
     * allocates width * height * 4 bytes for the bitmap — roughly 6 GB for that
     * file — a decompression bomb that kills the worker with an out-of-memory
     * fatal before any later check can run. The upload byte ceiling does not
     * help here: the file is tiny on the wire.
     *
     * The ceilings are generous on purpose (see config/services.php) so every
     * normal avatar or phone photo still passes, and both are configurable for
     * a host with more memory.
     *
     * @param  array<int|string, mixed>  $info  the array returned by getimagesizefromstring()
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
            Log::warning('Panel avatar refused: declared dimensions exceed the decode ceiling.', [
                'width' => $width,
                'height' => $height,
                'max_dimension' => $maxDimension,
                'max_pixels' => $maxPixels,
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
     * Cropping before scaling (rather than squashing to a square) is what keeps
     * a portrait photo from arriving distorted.
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

            // Keep alpha through the resample, or a transparent PNG logo would
            // come out on a black square.
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

            // PNG/WebP can carry transparency, so they stay lossless; everything
            // else is a photograph and JPEG is both smaller and perfectly
            // adequate at 256px.
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
}
