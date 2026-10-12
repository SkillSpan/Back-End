<?php

namespace Tests\Feature\Admin;

use App\Models\AdminProfile;
use App\Models\ProfessionalProfile;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * The panel avatar — upload, processing, storage, serving and removal.
 *
 * Kept apart from AdminProfileTest because almost none of this is about the
 * profile *form*. The subject here is a pipeline: bytes arrive from a browser,
 * are decoded, centre-cropped, re-encoded at a fixed size and written into the
 * row as a base64 blob, then come back out through their own cacheable route.
 *
 * The properties worth pinning are the ones a naive implementation gets wrong:
 *
 *   - the crop is CENTRED, not a squash — a 3:1 source must not arrive distorted;
 *   - the stored format is the one WE produced, never the one the client claimed;
 *   - the row cannot be bloated, whatever is uploaded;
 *   - the bytes never travel in a JSON payload;
 *   - and a refused upload changes nothing at all.
 *
 * Every fixture is built with GD rather than downloaded, so the tests describe
 * the exact pixels they expect back.
 */
class AdminAvatarTest extends TestCase
{
    use RefreshDatabase;

    private Role $adminRole;

    private Role $learnerRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminRole = Role::create(['name' => 'Admin', 'slug' => 'admin', 'description' => '']);
        $this->learnerRole = Role::create(['name' => 'Learner', 'slug' => 'learner', 'description' => '']);
    }

    // =================================================== which formats we take

    public function test_a_png_is_stored_as_a_png(): void
    {
        $admin = $this->administrator();

        $this->uploadBytes($admin, 'me.png', $this->png(400, 400))
            ->assertOk()
            ->assertJsonPath('data.profile.has_avatar', true);

        $this->assertSame('image/png', $this->stored($admin)->avatar_mime);
        $this->assertSame('image/png', $this->storedInfo($admin)['mime']);
    }

    public function test_a_jpeg_is_stored_as_a_jpeg(): void
    {
        $admin = $this->administrator();

        $this->uploadBytes($admin, 'photo.jpg', $this->jpeg(400, 400))->assertOk();

        // A photograph re-encoded as PNG would be several times the size for no
        // visible gain, so the lossless branch is reserved for alpha formats.
        $this->assertSame('image/jpeg', $this->stored($admin)->avatar_mime);
    }

    public function test_a_webp_is_stored_as_a_png_so_transparency_survives(): void
    {
        $admin = $this->administrator();

        $this->uploadBytes($admin, 'shot.webp', $this->webp(400, 400))->assertOk();

        // The client said WebP and we stored PNG. That mismatch is the proof
        // that the stored mime is the one *we* produced, not the one the upload
        // claimed — which is what makes echoing it back on the image route safe.
        $this->assertSame('image/png', $this->stored($admin)->avatar_mime);
    }

    public function test_a_gif_is_stored_as_a_png(): void
    {
        $admin = $this->administrator();

        $this->uploadBytes($admin, 'anim.gif', $this->gif(400, 400))->assertOk();

        $this->assertSame('image/png', $this->stored($admin)->avatar_mime);
    }

    public function test_a_format_gd_can_read_but_we_do_not_accept_is_refused(): void
    {
        // AVIF decodes fine on this build of GD, so "GD can read it" is not the
        // rule — the accepted list is. A format that slipped in because the
        // library happened to support it would be an unbudgeted storage cost.
        $image = imagecreatetruecolor(400, 400);
        ob_start();
        imageavif($image, null);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        $this->actingAs($this->administrator())
            ->post('/admin/api/profile/avatar', [
                'avatar' => UploadedFile::fake()->createWithContent('shot.avif', $bytes),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('avatar');
    }

    // ============================================================ the geometry

    public function test_a_wide_image_is_centre_cropped_not_squashed(): void
    {
        $admin = $this->administrator();

        // Three vertical bands: red | green | blue, 100px each.
        $bytes = $this->png(300, 100, function ($image): void {
            imagefilledrectangle($image, 0, 0, 99, 99, imagecolorallocate($image, 255, 0, 0));
            imagefilledrectangle($image, 100, 0, 199, 99, imagecolorallocate($image, 0, 255, 0));
            imagefilledrectangle($image, 200, 0, 299, 99, imagecolorallocate($image, 0, 0, 255));
        });

        $this->uploadBytes($admin, 'bands.png', $bytes)->assertOk();

        $stored = $this->storedBytes($admin);

        // A centre crop keeps only the middle 100x100, so the whole result is
        // green. Squashing the 3:1 source instead would leave red at the left
        // edge and blue at the right — which is exactly the distortion this
        // asserts against.
        [$leftR, $leftG, $leftB] = $this->pixel($stored, 8, 128);
        $this->assertGreaterThan(200, $leftG, 'the left edge should still be the centre band');
        $this->assertLessThan(40, $leftR);
        $this->assertLessThan(40, $leftB);

        [$rightR, $rightG, $rightB] = $this->pixel($stored, 247, 128);
        $this->assertGreaterThan(200, $rightG, 'the right edge should still be the centre band');
        $this->assertLessThan(40, $rightR);
        $this->assertLessThan(40, $rightB);
    }

    public function test_a_tall_image_is_centre_cropped_to_a_square(): void
    {
        $admin = $this->administrator();

        // Same idea, rotated: the middle band is the one that must survive.
        $bytes = $this->png(100, 300, function ($image): void {
            imagefilledrectangle($image, 0, 0, 99, 99, imagecolorallocate($image, 255, 0, 0));
            imagefilledrectangle($image, 0, 100, 99, 199, imagecolorallocate($image, 0, 255, 0));
            imagefilledrectangle($image, 0, 200, 99, 299, imagecolorallocate($image, 0, 0, 255));
        });

        $this->uploadBytes($admin, 'tall.png', $bytes)->assertOk();

        $info = $this->storedInfo($admin);
        $this->assertSame(256, $info[0]);
        $this->assertSame(256, $info[1]);

        [$r, $g, $b] = $this->pixel($this->storedBytes($admin), 128, 8);
        $this->assertGreaterThan(200, $g, 'the top edge should still be the centre band');
        $this->assertLessThan(40, $r);
        $this->assertLessThan(40, $b);
    }

    public function test_a_square_image_is_still_normalised_to_the_configured_size(): void
    {
        $admin = $this->administrator();

        $this->uploadBytes($admin, 'square.png', $this->png(640, 640))->assertOk();

        // Already square, but not the stored size — every avatar has to come out
        // the same dimensions or the layout shifts per person.
        $this->assertSame(256, $this->storedInfo($admin)[0]);
        $this->assertSame(256, $this->storedInfo($admin)[1]);
    }

    public function test_a_small_but_valid_image_is_upscaled_to_the_configured_size(): void
    {
        $admin = $this->administrator();

        // 40x40 clears the `dimensions` floor, so it must be accepted — and then
        // normalised like everything else rather than stored at 40x40.
        $this->uploadBytes($admin, 'small.png', $this->png(40, 40))->assertOk();

        $this->assertSame(256, $this->storedInfo($admin)[0]);
    }

    public function test_a_large_upload_is_downscaled_so_the_row_stays_bounded(): void
    {
        $admin = $this->administrator();

        $bytes = $this->noisePng(1000);
        $this->assertGreaterThan(500 * 1024, strlen($bytes), 'the fixture should be a genuinely large upload');

        $this->uploadBytes($admin, 'huge.png', $bytes)->assertOk();

        $stored = $this->storedBytes($admin);

        // The bytes live in the row, so their size is a database cost that every
        // read pays. Re-encoding at a fixed size is what caps it.
        $this->assertSame(256, $this->storedInfo($admin)[0]);
        $this->assertLessThan(strlen($bytes), strlen($stored));
        $this->assertLessThan(400 * 1024, strlen($stored));
    }

    public function test_a_transparent_png_keeps_its_transparency(): void
    {
        $admin = $this->administrator();

        // No paint at all: the whole fixture is transparent.
        $this->uploadBytes($admin, 'clear.png', $this->png(200, 200))->assertOk();

        // A logo on a transparent background must not come back on a black
        // square, which is what happens if alpha is dropped through the resample.
        $this->assertGreaterThan(120, $this->alphaAt($this->storedBytes($admin), 128, 128));
    }

    // ============================================================== the storage

    public function test_uploading_creates_the_row_when_there_is_none(): void
    {
        $admin = $this->administrator();

        $this->assertDatabaseMissing('admin_profiles', ['user_id' => $admin->id]);

        // The photo may be the very first thing the person does, so the service
        // cannot assume the profile row already exists.
        $this->uploadBytes($admin, 'me.png', $this->png(400, 400))->assertOk();

        $this->assertDatabaseHas('admin_profiles', [
            'user_id' => $admin->id,
            'avatar_mime' => 'image/png',
        ]);
        $this->assertNotNull($this->stored($admin)->avatar_updated_at);
    }

    public function test_the_stored_blob_is_decodable_base64_holding_the_image(): void
    {
        $admin = $this->administrator();
        $this->uploadBytes($admin, 'me.png', $this->png(400, 400))->assertOk();

        $profile = $this->stored($admin);

        $this->assertIsString($profile->avatar_data);
        $this->assertNotSame('', $profile->avatar_data);

        $binary = base64_decode((string) $profile->avatar_data, true);
        $this->assertIsString($binary);
        $this->assertNotFalse(getimagesizefromstring($binary));
    }

    public function test_the_avatar_bytes_never_travel_in_a_payload(): void
    {
        $admin = $this->administrator();
        $this->uploadBytes($admin, 'me.png', $this->noisePng(600))->assertOk();

        $response = $this->actingAs($admin)->getJson('/admin/api/profile')->assertOk();

        // A blob of tens of kilobytes inside every profile read — and every log
        // line that dumps the model — is a cost with no benefit: the image has
        // its own cacheable URL.
        $this->assertArrayNotHasKey('avatar_data', $response->json('data.profile'));

        // Also absent from the model's own serialisation, not just this payload.
        $this->assertArrayNotHasKey('avatar_data', $this->stored($admin)->toArray());
    }

    public function test_uploading_does_not_disturb_the_other_profile_fields(): void
    {
        $admin = $this->administrator();

        $this->actingAs($admin)->patchJson('/admin/api/profile', [
            'display_title' => 'Operations Lead',
            'bio' => 'Still here afterwards.',
            'age' => 34,
        ])->assertOk();

        $this->uploadBytes($admin, 'me.png', $this->png(400, 400))->assertOk();

        // The photo write is a different code path from the details write; it
        // must not blank the row it is filling in.
        $profile = $this->stored($admin);
        $this->assertSame('Operations Lead', $profile->display_title);
        $this->assertSame('Still here afterwards.', $profile->bio);
        $this->assertSame(34, $profile->age);
    }

    // ====================================================== refused uploads

    public function test_a_file_that_is_not_an_image_is_refused(): void
    {
        $this->actingAs($this->administrator())
            ->post('/admin/api/profile/avatar', [
                'avatar' => UploadedFile::fake()->create('notes.pdf', 20, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('avatar');
    }

    public function test_an_svg_is_refused_because_it_is_a_script_container(): void
    {
        // SVG passes Laravel's `image` rule — it is a valid image mime — so the
        // accepted-extension list is the only thing stopping it. Rendering a
        // user-supplied SVG is an XSS vector, not a picture.
        $svg = UploadedFile::fake()->createWithContent(
            'logo.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        );

        $this->actingAs($this->administrator())
            ->post('/admin/api/profile/avatar', ['avatar' => $svg], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('avatar');
    }

    public function test_an_image_smaller_than_the_minimum_is_refused(): void
    {
        $this->actingAs($this->administrator())
            ->post('/admin/api/profile/avatar', [
                'avatar' => UploadedFile::fake()->image('tiny.png', 16, 16),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('avatar');
    }

    public function test_a_missing_file_is_refused(): void
    {
        $this->actingAs($this->administrator())
            ->post('/admin/api/profile/avatar', [], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('avatar');
    }

    public function test_an_image_over_the_configured_size_limit_is_refused(): void
    {
        // Lowered rather than uploaded around: the rule has to read the config,
        // and a 4 MB fixture would only prove that it does not read it.
        config(['services.profile.avatar_max_upload_kb' => 1]);

        $bytes = $this->noisePng(300);
        $this->assertGreaterThan(1024, strlen($bytes));

        $this->actingAs($this->administrator())
            ->post('/admin/api/profile/avatar', [
                'avatar' => UploadedFile::fake()->createWithContent('big.png', $bytes),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('avatar');
    }

    public function test_a_truncated_image_is_refused_with_the_project_envelope(): void
    {
        // The header survives, so `getimagesizefromstring` reads a size and the
        // validation rules pass — but there are no pixels left to decode. This
        // is the path that reaches the service's own refusal rather than
        // Laravel's validator, so it has to answer in the project envelope.
        $truncated = substr($this->png(200, 200), 0, 60);

        $this->actingAs($this->administrator())
            ->post('/admin/api/profile/avatar', [
                'avatar' => UploadedFile::fake()->createWithContent('broken.png', $truncated),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROFILE_AVATAR_INVALID');
    }

    public function test_a_refused_upload_leaves_the_previous_avatar_alone(): void
    {
        $admin = $this->administrator();
        $this->uploadBytes($admin, 'good.png', $this->png(400, 400))->assertOk();

        $before = $this->stored($admin)->only(['avatar_data', 'avatar_mime', 'avatar_updated_at']);

        $this->actingAs($admin)
            ->post('/admin/api/profile/avatar', [
                'avatar' => UploadedFile::fake()->create('notes.pdf', 20, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);

        // A rejected replacement must not destroy the photo that was already
        // there — the person still has a face on the panel.
        $this->assertEquals($before, $this->stored($admin)->only(['avatar_data', 'avatar_mime', 'avatar_updated_at']));
    }

    // ================================================== decompression bombs

    public function test_a_decompression_bomb_is_refused_before_it_is_decoded(): void
    {
        $bomb = $this->pngDeclaringSize(40000, 40000);

        // Precondition: the header really does declare 40000x40000, so the
        // refusal below comes from the size guard and not from the "not an
        // image" path — otherwise the test would pass for the wrong reason.
        $declared = getimagesizefromstring($bomb);
        $this->assertSame(40000, $declared[0]);
        $this->assertSame(40000, $declared[1]);

        // A few dozen bytes on the wire; ~6 GB once decoded.
        $this->assertLessThan(1024, strlen($bomb));

        $admin = $this->administrator();

        $this->uploadBytes($admin, 'bomb.png', $bomb)
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROFILE_AVATAR_INVALID');

        // Nothing was stored: the guard ran before `profileFor()` was reached.
        $this->assertNull(
            AdminProfile::where('user_id', $admin->id)->first()?->avatar_data
        );
    }

    public function test_a_bomb_leaves_the_existing_avatar_intact(): void
    {
        $admin = $this->administrator();
        $this->uploadBytes($admin, 'me.png', $this->png(400, 400))->assertOk();

        $before = $this->stored($admin)->only(['avatar_data', 'avatar_mime', 'avatar_updated_at']);

        $this->uploadBytes($admin, 'bomb.png', $this->pngDeclaringSize(50000, 50000))
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROFILE_AVATAR_INVALID');

        $this->assertEquals($before, $this->stored($admin)->only(['avatar_data', 'avatar_mime', 'avatar_updated_at']));
    }

    public function test_an_image_wider_than_the_dimension_ceiling_is_refused(): void
    {
        // 9000 x 100 is only 900 000 pixels — well under the pixel ceiling — so
        // this isolates the per-side rule.
        $this->uploadBytes($this->administrator(), 'wide.png', $this->pngDeclaringSize(9000, 100))
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROFILE_AVATAR_INVALID');
    }

    public function test_an_image_taller_than_the_dimension_ceiling_is_refused(): void
    {
        $this->uploadBytes($this->administrator(), 'tall.png', $this->pngDeclaringSize(100, 9000))
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROFILE_AVATAR_INVALID');
    }

    public function test_an_image_over_the_pixel_ceiling_is_refused(): void
    {
        // 6000 x 6000 = 36 MP: each side is inside the 8000 ceiling, so this
        // isolates the total-pixel rule.
        $this->uploadBytes($this->administrator(), 'dense.png', $this->pngDeclaringSize(6000, 6000))
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROFILE_AVATAR_INVALID');
    }

    // ============================================ the ceilings stay usable

    public function test_a_large_but_normal_photo_is_still_accepted(): void
    {
        // 2000 x 2000 = 4 MP, a perfectly ordinary phone photo. The guard must
        // not be so tight that real uploads start failing.
        $this->uploadBytes($this->administrator(), 'photo.png', $this->png(2000, 2000))
            ->assertOk();
    }

    public function test_the_pixel_ceiling_is_configurable_and_inclusive(): void
    {
        config(['services.profile.avatar_max_pixels' => 10000]);

        $admin = $this->administrator();

        // 100 x 100 = exactly 10 000 pixels: at the ceiling, accepted.
        $this->uploadBytes($admin, 'at-limit.png', $this->png(100, 100))->assertOk();

        // 101 x 101 = 10 201 pixels: one row over, refused.
        $this->uploadBytes($admin, 'over-limit.png', $this->png(101, 101))
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROFILE_AVATAR_INVALID');
    }

    public function test_the_dimension_ceiling_is_configurable(): void
    {
        config(['services.profile.avatar_max_dimension' => 300]);

        $admin = $this->administrator();

        $this->uploadBytes($admin, 'ok.png', $this->png(300, 300))->assertOk();

        $this->uploadBytes($admin, 'too-wide.png', $this->png(301, 301))
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROFILE_AVATAR_INVALID');
    }

    // ============================================================== the serving

    public function test_the_avatar_route_serves_the_stored_bytes_verbatim(): void
    {
        $admin = $this->administrator();
        $this->uploadBytes($admin, 'me.png', $this->png(400, 400))->assertOk();

        $stored = $this->storedBytes($admin);

        $response = $this->actingAs($admin)->get('/admin/profile/avatar')->assertOk();

        // Byte-for-byte: the route must not re-encode, resize or re-wrap what is
        // already in the row.
        $this->assertSame($stored, $response->getContent());
        $this->assertSame((string) strlen($stored), $response->headers->get('Content-Length'));
    }

    public function test_the_avatar_route_marks_the_response_cacheable_and_nosniff(): void
    {
        $admin = $this->administrator();
        $this->uploadBytes($admin, 'me.png', $this->png(400, 400))->assertOk();

        $response = $this->actingAs($admin)->get('/admin/profile/avatar')->assertOk();

        // A stable URL is the whole reason the image is not inlined into the
        // payload, so it has to actually be cacheable — and private, because it
        // is one person's photo.
        $cache = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cache);
        $this->assertStringContainsString('immutable', $cache);

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_the_content_type_follows_the_stored_format(): void
    {
        $jpegAdmin = $this->administrator('JPEG Admin');
        $this->uploadBytes($jpegAdmin, 'photo.jpg', $this->jpeg(400, 400))->assertOk();

        $this->actingAs($jpegAdmin)
            ->get('/admin/profile/avatar')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');

        $pngAdmin = $this->administrator('PNG Admin');
        $this->uploadBytes($pngAdmin, 'me.png', $this->png(400, 400))->assertOk();

        $this->actingAs($pngAdmin)
            ->get('/admin/profile/avatar')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_the_avatar_route_is_a_miss_when_there_is_no_photo(): void
    {
        // The page renders initials instead, so "no photo" is a miss rather than
        // a picture of a grey person.
        $this->actingAs($this->administrator())
            ->get('/admin/profile/avatar')
            ->assertStatus(404);
    }

    public function test_the_avatar_route_is_refused_to_an_unrelated_account(): void
    {
        $this->actingAs($this->learner())
            ->get('/admin/profile/avatar')
            ->assertStatus(403);
    }

    public function test_a_guest_cannot_reach_the_avatar_route(): void
    {
        $this->get('/admin/profile/avatar')->assertRedirect('/admin/login');
    }

    public function test_the_avatar_route_serves_the_callers_own_photo(): void
    {
        $admin = $this->administrator('First Admin');
        $other = $this->administrator('Second Admin');

        $this->uploadBytes($admin, 'first.png', $this->png(400, 400, function ($image): void {
            imagefilledrectangle($image, 0, 0, 399, 399, imagecolorallocate($image, 255, 0, 0));
        }))->assertOk();

        $this->uploadBytes($other, 'second.png', $this->png(400, 400, function ($image): void {
            imagefilledrectangle($image, 0, 0, 399, 399, imagecolorallocate($image, 0, 0, 255));
        }))->assertOk();

        // There is no id in the route, so the only thing it can possibly serve
        // is the caller's own row. This pins that from the outside.
        $mine = $this->actingAs($admin)->get('/admin/profile/avatar')->assertOk()->getContent();

        [$r, , $b] = $this->pixel($mine, 128, 128);
        $this->assertGreaterThan(200, $r);
        $this->assertLessThan(40, $b);

        $theirs = $this->actingAs($other)->get('/admin/profile/avatar')->assertOk()->getContent();

        [$r2, , $b2] = $this->pixel($theirs, 128, 128);
        $this->assertGreaterThan(200, $b2);
        $this->assertLessThan(40, $r2);

        $this->assertNotSame($mine, $theirs);
    }

    // ======================================================== replace & remove

    public function test_replacing_an_avatar_overwrites_it_without_orphan_rows(): void
    {
        $admin = $this->administrator();

        $this->uploadBytes($admin, 'first.png', $this->png(400, 400))->assertOk();
        $first = $this->stored($admin)->avatar_data;

        $this->uploadBytes($admin, 'second.jpg', $this->jpeg(500, 500))->assertOk();

        // One row per person, always: the table is keyed on user_id, so a second
        // upload replaces rather than accumulates.
        $this->assertSame(1, AdminProfile::where('user_id', $admin->id)->count());

        $profile = $this->stored($admin);
        $this->assertNotSame($first, $profile->avatar_data);
        $this->assertSame('image/jpeg', $profile->avatar_mime);
    }

    public function test_the_version_token_changes_when_the_photo_is_replaced(): void
    {
        $admin = $this->administrator();

        $first = $this->uploadBytes($admin, 'first.png', $this->png(400, 400))
            ->json('data.profile.avatar_url');

        // The stored URL is cached for a year, so the token is the only thing
        // that can make a browser fetch the new bytes.
        $this->travel(2)->seconds();

        // The payload hands the client a URL rather than bytes, so that URL has
        // to be the route that actually serves them — and it has to carry a
        // token, because the response is cached for a year.
        $this->assertIsString($first);
        $this->assertStringContainsString('/admin/profile/avatar', $first);
        $this->assertStringContainsString('?v=', $first);

        $second = $this->uploadBytes($admin, 'second.png', $this->png(500, 500))
            ->json('data.profile.avatar_url');

        $this->assertNotSame($first, $second);
    }

    public function test_removing_clears_every_avatar_column(): void
    {
        $admin = $this->administrator();
        $this->uploadBytes($admin, 'me.png', $this->png(400, 400))->assertOk();

        $this->actingAs($admin)
            ->deleteJson('/admin/api/profile/avatar')
            ->assertOk()
            ->assertJsonPath('data.profile.has_avatar', false)
            ->assertJsonPath('data.profile.avatar_url', null);

        // All three move together. Leaving `avatar_mime` behind would make a
        // later read claim a format for bytes that are no longer there.
        $profile = $this->stored($admin);
        $this->assertNull($profile->avatar_data);
        $this->assertNull($profile->avatar_mime);
        $this->assertNull($profile->avatar_updated_at);
    }

    public function test_removing_is_idempotent(): void
    {
        $admin = $this->administrator();

        // The caller's intent ("I want no photo") is already satisfied, so this
        // is a success rather than a 404.
        $this->actingAs($admin)->deleteJson('/admin/api/profile/avatar')->assertOk();

        $this->uploadBytes($admin, 'me.png', $this->png(400, 400))->assertOk();

        $this->actingAs($admin)->deleteJson('/admin/api/profile/avatar')->assertOk();
        $this->actingAs($admin)->deleteJson('/admin/api/profile/avatar')->assertOk();

        $this->assertNull($this->stored($admin)->avatar_data);
    }

    public function test_a_photo_can_be_added_again_after_removal(): void
    {
        $admin = $this->administrator();

        $this->uploadBytes($admin, 'me.png', $this->png(400, 400))->assertOk();
        $this->actingAs($admin)->deleteJson('/admin/api/profile/avatar')->assertOk();

        // Removal must clear the bytes without leaving the row in a state the
        // next upload cannot fill.
        $this->uploadBytes($admin, 'again.png', $this->png(400, 400))
            ->assertOk()
            ->assertJsonPath('data.profile.has_avatar', true);

        $this->assertSame('image/png', $this->stored($admin)->avatar_mime);
        $this->assertGreaterThan(0, strlen($this->storedBytes($admin)));
    }

    // ============================================================== the scoping

    public function test_one_users_upload_does_not_touch_anothers_row(): void
    {
        $admin = $this->administrator('First Admin');
        $other = $this->administrator('Second Admin');

        $this->uploadBytes($other, 'theirs.png', $this->png(400, 400))->assertOk();
        $theirs = $this->stored($other)->avatar_data;

        $this->uploadBytes($admin, 'mine.png', $this->png(400, 400))->assertOk();

        $this->assertSame($theirs, $this->stored($other)->avatar_data);
    }

    public function test_a_mentor_can_use_the_whole_pipeline(): void
    {
        $mentor = $this->mentor();

        $this->uploadBytes($mentor, 'mentor.png', $this->png(400, 400))
            ->assertOk()
            ->assertJsonPath('data.profile.has_avatar', true);

        // A mentor has no role slug, so a role-based gate would have refused
        // every one of these requests.
        $this->actingAs($mentor)
            ->get('/admin/profile/avatar')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        $this->actingAs($mentor)->deleteJson('/admin/api/profile/avatar')->assertOk();
    }

    // ============================================================== the sidebar

    public function test_the_sidebar_shows_the_photo_on_the_other_panel_pages(): void
    {
        $admin = $this->administrator();
        $this->uploadBytes($admin, 'me.png', $this->png(400, 400))->assertOk();

        // The sidebar footer is a shared partial, so the photo has to appear on
        // every panel page without each one being changed.
        foreach (['/admin/support', '/admin/projects', '/admin/organizations', '/admin/profile'] as $page) {
            $this->visitPanel($admin, $page)
                ->assertOk()
                ->assertSee('nav-avatar-img', false)
                ->assertSee('/admin/profile/avatar', false);
        }
    }

    public function test_the_sidebar_falls_back_to_initials_when_there_is_no_photo(): void
    {
        $admin = $this->administrator();

        $this->visitPanel($admin, '/admin/support')
            ->assertOk()
            ->assertSee('nav-avatar', false)
            ->assertDontSee('nav-avatar-img', false);

        // And it goes back to initials once the photo is removed.
        $this->uploadBytes($admin, 'me.png', $this->png(400, 400))->assertOk();

        $this->visitPanel($admin, '/admin/support')->assertSee('nav-avatar-img', false);

        $this->actingAs($admin)->deleteJson('/admin/api/profile/avatar')->assertOk();

        $this->visitPanel($admin, '/admin/support')->assertDontSee('nav-avatar-img', false);
    }

    // ================================================================ fixtures

    /**
     * A PNG built in memory, optionally painted by the callback.
     *
     * Starts fully transparent with alpha saved, so a fixture with no paint at
     * all is a clean transparency probe.
     */
    private function png(int $width, int $height, ?callable $paint = null): string
    {
        $image = imagecreatetruecolor($width, $height);

        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagealphablending($image, true);

        if ($paint !== null) {
            $paint($image);
        }

        ob_start();
        imagepng($image, null, 9);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    /**
     * A PNG that is only a header: the 8 byte signature plus an IHDR chunk
     * declaring the given dimensions, with no pixel data at all.
     *
     * This is a decompression bomb in its purest form. The file is a few dozen
     * bytes, yet `getimagesizefromstring` — and Laravel's own `dimensions`
     * rule — read the declared size straight out of the header, so nothing
     * upstream of the decode notices. Only a check sitting between the header
     * read and `imagecreatefromstring` can stop it, which is what these
     * fixtures exist to prove.
     */
    private function pngDeclaringSize(int $width, int $height): string
    {
        // 4 + 4 + (1 bit depth + 1 colour type + 3 reserved) = 13 byte payload.
        $ihdr = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n"
            .pack('N', 13)
            .'IHDR'.$ihdr
            .pack('N', crc32('IHDR'.$ihdr));
    }

    private function jpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 120, 140, 90));

        ob_start();
        imagejpeg($image, null, 90);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function webp(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 40, 90, 160));

        ob_start();
        imagewebp($image, null, 90);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function gif(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 200, 60, 60));

        ob_start();
        imagegif($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    /**
     * A genuinely incompressible PNG, for the size assertions.
     *
     * Solid colours would compress to a few hundred bytes and prove nothing
     * about whether the input was actually resized — so the fixture has to be
     * large on the way in, not just large in pixel count.
     *
     * The pixel field is handed to GD as a raw 24-bit BMP rather than painted
     * with `imagesetpixel`. That matters: PNG filters operate row by row, so
     * *any* pattern built from a coarser grid (blocks, tiles, a few random
     * colours) collapses to a few kilobytes and the size assertions become
     * vacuous — which is exactly how an earlier version of this helper managed
     * to produce a 52 KB "large" upload. Random bytes have no row correlation
     * for the filters to exploit, so the PNG that comes out stays ~3 MB at
     * 1000x1000 and the downscale claim means something.
     *
     * BMP is only an intermediate here; the fixture that gets uploaded is still
     * a PNG, which is the format the endpoint accepts.
     */
    private function noisePng(int $size): string
    {
        $rowBytes = $size * 3;

        // BMP rows are padded to a four-byte boundary, hence the `$stride` the
        // DIB header advertises. Getting this wrong shifts every row and the
        // decode fails.
        $padding = (4 - ($rowBytes % 4)) % 4;

        $pixels = '';
        for ($y = 0; $y < $size; $y++) {
            $pixels .= random_bytes($rowBytes).str_repeat("\0", $padding);
        }

        $header = 'BM'
            .pack('V', 54 + strlen($pixels))   // file size
            .pack('V', 0)                      // reserved
            .pack('V', 54)                     // pixel data offset
            .pack('V', 40)                     // DIB header size
            .pack('V', $size)                  // width
            .pack('V', $size)                  // height
            .pack('v', 1)                      // colour planes
            .pack('v', 24)                     // bits per pixel
            .pack('V', 0)                      // compression: none
            .pack('V', strlen($pixels))        // image size
            .pack('V', 2835).pack('V', 2835)   // 72 dpi
            .pack('V', 0).pack('V', 0);        // palette

        $image = imagecreatefromstring($header.$pixels);

        ob_start();
        imagepng($image, null, 6);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    // ================================================================= helpers

    private function uploadBytes(User $user, string $name, string $bytes)
    {
        return $this->actingAs($user)->post('/admin/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->createWithContent($name, $bytes),
        ], ['Accept' => 'application/json']);
    }

    /**
     * Open a panel page as this user, the way a real request would.
     *
     * `actingAs()` hands the guard the very model instance it is given and
     * keeps it for the rest of the test, so a relation loaded during an earlier
     * assertion stays cached — a later upload would then be invisible to the
     * view. That cannot happen over HTTP, where the session guard re-hydrates
     * the user from the database on every hit, so the stale relation is a test
     * artefact rather than a bug. Dropping it here is what makes the assertion
     * describe production behaviour instead of the harness.
     */
    private function visitPanel(User $user, string $path)
    {
        $user->unsetRelation('adminProfile');

        return $this->actingAs($user)->get($path);
    }

    private function stored(User $user): AdminProfile
    {
        return AdminProfile::where('user_id', $user->id)->firstOrFail();
    }

    private function storedBytes(User $user): string
    {
        return (string) base64_decode((string) $this->stored($user)->avatar_data, true);
    }

    /** @return array{0: int, 1: int, 2: string, mime: string} */
    private function storedInfo(User $user): array
    {
        return getimagesizefromstring($this->storedBytes($user));
    }

    /** @return array{0: int, 1: int, 2: int} red, green, blue */
    private function pixel(string $binary, int $x, int $y): array
    {
        $image = imagecreatefromstring($binary);
        $parts = imagecolorsforindex($image, imagecolorat($image, $x, $y));
        imagedestroy($image);

        return [$parts['red'], $parts['green'], $parts['blue']];
    }

    /** 0 is fully opaque, 127 fully transparent. */
    private function alphaAt(string $binary, int $x, int $y): int
    {
        $image = imagecreatefromstring($binary);
        $parts = imagecolorsforindex($image, imagecolorat($image, $x, $y));
        imagedestroy($image);

        return $parts['alpha'];
    }

    private function administrator(string $name = 'Panel Admin'): User
    {
        $user = $this->user($name);
        $user->roles()->attach($this->adminRole->id);

        return $user->fresh();
    }

    private function mentor(string $name = 'Panel Mentor'): User
    {
        $user = $this->user($name);

        // Mentor identity is a ProfessionalProfile attribute, not a role slug.
        ProfessionalProfile::create([
            'user_id' => $user->id,
            'type' => 'mentor',
            'expertise' => 'Backend engineering',
        ]);

        return $user->fresh();
    }

    private function learner(string $name = 'Panel Learner'): User
    {
        $user = $this->user($name);
        $user->roles()->attach($this->learnerRole->id);

        return $user->fresh();
    }

    private function user(string $name): User
    {
        // `example.com`, never `test.com`: test.com addresses are disposable
        // under the `indisposable` rule, so assertions about them can pass for
        // the wrong reason.
        return User::forceCreate([
            'name' => $name,
            'email' => uniqid().'@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }
}
