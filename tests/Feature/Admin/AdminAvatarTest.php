<?php

namespace Tests\Feature\Admin;

use App\Models\AdminProfile;
use App\Models\ProfessionalProfile;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

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

        $this->assertSame('image/jpeg', $this->stored($admin)->avatar_mime);
    }

    public function test_a_webp_is_stored_as_a_png_so_transparency_survives(): void
    {
        $admin = $this->administrator();

        $this->uploadBytes($admin, 'shot.webp', $this->webp(400, 400))->assertOk();

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

    public function test_a_wide_image_is_centre_cropped_not_squashed(): void
    {
        $admin = $this->administrator();

        $bytes = $this->png(300, 100, function ($image): void {
            imagefilledrectangle($image, 0, 0, 99, 99, imagecolorallocate($image, 255, 0, 0));
            imagefilledrectangle($image, 100, 0, 199, 99, imagecolorallocate($image, 0, 255, 0));
            imagefilledrectangle($image, 200, 0, 299, 99, imagecolorallocate($image, 0, 0, 255));
        });

        $this->uploadBytes($admin, 'bands.png', $bytes)->assertOk();

        $stored = $this->storedBytes($admin);

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

        $this->assertSame(256, $this->storedInfo($admin)[0]);
        $this->assertSame(256, $this->storedInfo($admin)[1]);
    }

    public function test_a_small_but_valid_image_is_upscaled_to_the_configured_size(): void
    {
        $admin = $this->administrator();

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

        $this->assertSame(256, $this->storedInfo($admin)[0]);
        $this->assertLessThan(strlen($bytes), strlen($stored));
        $this->assertLessThan(400 * 1024, strlen($stored));
    }

    public function test_a_transparent_png_keeps_its_transparency(): void
    {
        $admin = $this->administrator();

        $this->uploadBytes($admin, 'clear.png', $this->png(200, 200))->assertOk();

        $this->assertGreaterThan(120, $this->alphaAt($this->storedBytes($admin), 128, 128));
    }

    public function test_uploading_creates_the_row_when_there_is_none(): void
    {
        $admin = $this->administrator();

        $this->assertDatabaseMissing('admin_profiles', ['user_id' => $admin->id]);

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

        $this->assertArrayNotHasKey('avatar_data', $response->json('data.profile'));

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

        $profile = $this->stored($admin);
        $this->assertSame('Operations Lead', $profile->display_title);
        $this->assertSame('Still here afterwards.', $profile->bio);
        $this->assertSame(34, $profile->age);
    }

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

        $this->assertEquals($before, $this->stored($admin)->only(['avatar_data', 'avatar_mime', 'avatar_updated_at']));
    }

    public function test_a_decompression_bomb_is_refused_before_it_is_decoded(): void
    {
        $bomb = $this->pngDeclaringSize(40000, 40000);

        $declared = getimagesizefromstring($bomb);
        $this->assertSame(40000, $declared[0]);
        $this->assertSame(40000, $declared[1]);

        $this->assertLessThan(1024, strlen($bomb));

        $admin = $this->administrator();

        $this->uploadBytes($admin, 'bomb.png', $bomb)
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROFILE_AVATAR_INVALID');

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
        $this->uploadBytes($this->administrator(), 'dense.png', $this->pngDeclaringSize(6000, 6000))
            ->assertStatus(422)
            ->assertJsonPath('code', 'PROFILE_AVATAR_INVALID');
    }

    public function test_a_large_but_normal_photo_is_still_accepted(): void
    {
        $this->uploadBytes($this->administrator(), 'photo.png', $this->png(2000, 2000))
            ->assertOk();
    }

    public function test_the_pixel_ceiling_is_configurable_and_inclusive(): void
    {
        config(['services.profile.avatar_max_pixels' => 10000]);

        $admin = $this->administrator();

        $this->uploadBytes($admin, 'at-limit.png', $this->png(100, 100))->assertOk();

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

    public function test_the_avatar_route_serves_the_stored_bytes_verbatim(): void
    {
        $admin = $this->administrator();
        $this->uploadBytes($admin, 'me.png', $this->png(400, 400))->assertOk();

        $stored = $this->storedBytes($admin);

        $response = $this->actingAs($admin)->get('/admin/profile/avatar')->assertOk();

        $this->assertSame($stored, $response->getContent());
        $this->assertSame((string) strlen($stored), $response->headers->get('Content-Length'));
    }

    public function test_the_avatar_route_marks_the_response_cacheable_and_nosniff(): void
    {
        $admin = $this->administrator();
        $this->uploadBytes($admin, 'me.png', $this->png(400, 400))->assertOk();

        $response = $this->actingAs($admin)->get('/admin/profile/avatar')->assertOk();

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

    public function test_replacing_an_avatar_overwrites_it_without_orphan_rows(): void
    {
        $admin = $this->administrator();

        $this->uploadBytes($admin, 'first.png', $this->png(400, 400))->assertOk();
        $first = $this->stored($admin)->avatar_data;

        $this->uploadBytes($admin, 'second.jpg', $this->jpeg(500, 500))->assertOk();

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

        $this->travel(2)->seconds();

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

        $profile = $this->stored($admin);
        $this->assertNull($profile->avatar_data);
        $this->assertNull($profile->avatar_mime);
        $this->assertNull($profile->avatar_updated_at);
    }

    public function test_removing_is_idempotent(): void
    {
        $admin = $this->administrator();

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

        $this->uploadBytes($admin, 'again.png', $this->png(400, 400))
            ->assertOk()
            ->assertJsonPath('data.profile.has_avatar', true);

        $this->assertSame('image/png', $this->stored($admin)->avatar_mime);
        $this->assertGreaterThan(0, strlen($this->storedBytes($admin)));
    }

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

        $this->actingAs($mentor)
            ->get('/admin/profile/avatar')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        $this->actingAs($mentor)->deleteJson('/admin/api/profile/avatar')->assertOk();
    }

    public function test_the_sidebar_shows_the_photo_on_the_other_panel_pages(): void
    {
        $admin = $this->administrator();
        $this->uploadBytes($admin, 'me.png', $this->png(400, 400))->assertOk();

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

        $this->uploadBytes($admin, 'me.png', $this->png(400, 400))->assertOk();

        $this->visitPanel($admin, '/admin/support')->assertSee('nav-avatar-img', false);

        $this->actingAs($admin)->deleteJson('/admin/api/profile/avatar')->assertOk();

        $this->visitPanel($admin, '/admin/support')->assertDontSee('nav-avatar-img', false);
    }

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

    private function pngDeclaringSize(int $width, int $height): string
    {
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

    private function noisePng(int $size): string
    {
        $rowBytes = $size * 3;

        $padding = (4 - ($rowBytes % 4)) % 4;

        $pixels = '';
        for ($y = 0; $y < $size; $y++) {
            $pixels .= random_bytes($rowBytes).str_repeat("\0", $padding);
        }

        $header = 'BM'
            .pack('V', 54 + strlen($pixels))
            .pack('V', 0)
            .pack('V', 54)
            .pack('V', 40)
            .pack('V', $size)
            .pack('V', $size)
            .pack('v', 1)
            .pack('v', 24)
            .pack('V', 0)
            .pack('V', strlen($pixels))
            .pack('V', 2835).pack('V', 2835)
            .pack('V', 0).pack('V', 0);

        $image = imagecreatefromstring($header.$pixels);

        ob_start();
        imagepng($image, null, 6);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function uploadBytes(User $user, string $name, string $bytes)
    {
        return $this->actingAs($user)->post('/admin/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->createWithContent($name, $bytes),
        ], ['Accept' => 'application/json']);
    }

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

    private function storedInfo(User $user): array
    {
        return getimagesizefromstring($this->storedBytes($user));
    }

    private function pixel(string $binary, int $x, int $y): array
    {
        $image = imagecreatefromstring($binary);
        $parts = imagecolorsforindex($image, imagecolorat($image, $x, $y));
        imagedestroy($image);

        return [$parts['red'], $parts['green'], $parts['blue']];
    }

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
        return User::forceCreate([
            'name' => $name,
            'email' => uniqid().'@example.com',
            'password' => 'password123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }
}
