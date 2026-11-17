<?php

namespace Tests\Feature\Profile;

use App\Http\Requests\Profile\StoreProfileRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class GraduationYearValidationTest extends TestCase
{
    public function test_graduate_can_save_a_year_before_2024(): void
    {
        $data = ['university_name' => 'qena university', 'student_university_number' => 'aa',
            'specialization' => 'Custom Department', 'academic_level' => 'graduate', 'expected_graduation' => 2015];
        $this->assertTrue(Validator::make($data, (new StoreProfileRequest)->rules())->passes());
    }

    public function test_existing_profile_can_update_an_old_graduation_year(): void
    {
        $this->assertTrue(Validator::make(['expected_graduation' => 1950], (new UpdateProfileRequest)->rules())->passes());
    }

    public function test_out_of_range_year_is_rejected(): void
    {
        $this->assertFalse(Validator::make(['expected_graduation' => 1899], (new UpdateProfileRequest)->rules())->passes());
        $this->assertFalse(Validator::make(['expected_graduation' => 2151], (new UpdateProfileRequest)->rules())->passes());
    }
}
