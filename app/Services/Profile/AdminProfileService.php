<?php

namespace App\Services\Profile;

use App\Exceptions\ReadinessException;
use App\Models\AdminProfile;
use App\Models\AuthSession;
use App\Models\User;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Throwable;

class AdminProfileService
{
    private const ACCEPTED_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function profileFor(User $user): AdminProfile
    {
        return AdminProfile::firstOrCreate(['user_id' => $user->id]);
    }

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

    public function storeAvatar(User $user, UploadedFile $file): AdminProfile
    {
        $error = $file->getError();
        if ($error !== UPLOAD_ERR_OK) {
            throw new ReadinessException(
                $this->uploadErrorMessage($error),
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

        $info = @getimagesizefromstring($binary);

        if ($info === false || ! in_array($info['mime'] ?? null, self::ACCEPTED_MIMES, true)) {
            throw new ReadinessException(
                'The uploaded file is not a supported image. Use a JPEG, PNG, WebP or GIF.',
                422,
                'PROFILE_AVATAR_INVALID',
            );
        }

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

    public function avatarBinary(AdminProfile $profile): ?string
    {
        if (! $profile->hasAvatar()) {
            return null;
        }

        $decoded = base64_decode((string) $profile->avatar_data, true);

        return $decoded === false ? null : $decoded;
    }

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

        $user->password = $newPassword;
        $user->save();

        $user->tokens()->delete();

        AuthSession::where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        Log::info('Panel password changed.', ['user_id' => $user->id]);
    }

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

    private function uploadErrorMessage(int $code): string
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
