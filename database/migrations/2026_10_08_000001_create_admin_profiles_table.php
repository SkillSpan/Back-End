<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The panel user's own profile — administrator or mentor.
 *
 * A separate table rather than columns on `users`, for three reasons:
 *
 *  1. `users` is the shared identity row for learners, mentors, admins and
 *     organization staff. Bio/age/avatar are panel-presentation fields that
 *     belong to a fraction of those rows, and every learner query would start
 *     carrying columns it never reads.
 *  2. `avatar_data` is a MEDIUMTEXT blob. Keeping it out of `users` keeps
 *     `select * from users` cheap — the avatar is only loaded when the profile
 *     or the sidebar actually needs it.
 *  3. It mirrors the existing shape of the domain: mentor identity already
 *     lives in its own table (`professional_profiles`), not on `users`.
 *
 * Why the avatar is a base64 blob and not a path: the deployed container's
 * filesystem is ephemeral. There is no `storage:link` in the Dockerfile, and
 * `FILESYSTEM_DISK` is unset, so a file written at runtime is gone on the next
 * deploy. Storing the bytes in the row is the only option that survives a
 * deploy without new infrastructure. The image is downscaled to 256x256 before
 * it is stored, which keeps a row in the tens of kilobytes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('admin_profiles')) {
            Schema::create('admin_profiles', function (Blueprint $table) {
                $table->id();

                // One profile per user. `cascadeOnDelete` so removing the account
                // cannot leave an orphaned avatar behind.
                $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();

                // Free-text job title shown under the name ("Platform
                // Administrator", "Career Mentor"). Purely presentational —
                // authorisation never reads it; the `user_role` pivot does.
                $table->string('display_title', 60)->nullable();

                $table->text('bio')->nullable();

                // Tinyint: a human age fits, and the range is validated at the
                // request layer rather than by the column.
                $table->unsignedTinyInteger('age')->nullable();

                // Base64 payload WITHOUT the `data:image/...;base64,` prefix.
                // The mime is stored beside it so the data URI can be rebuilt on
                // read and the prefix is never parsed out of the blob.
                $table->mediumText('avatar_data')->nullable();
                $table->string('avatar_mime', 40)->nullable();

                // Bumped on every avatar write so a client can cache-bust
                // without re-downloading the image to compare it.
                $table->timestamp('avatar_updated_at')->nullable();

                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_profiles');
    }
};
