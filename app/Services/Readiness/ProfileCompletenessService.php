<?php

namespace App\Services\Readiness;

use App\Models\StudentProfile;

class ProfileCompletenessService
{
    public function calculate(StudentProfile $studentProfile): array
    {
        $fields = config('readiness.profile_completeness.required_fields', []);
        $fields = array_values(array_filter($fields, 'is_string'));

        if ($fields === []) {
            return [
                'score' => 0.0,
                'filled_fields' => 0,
                'total_fields' => 0,
                'missing_fields' => [],
                'algorithm_version' => config('readiness.profile_completeness.algorithm_version', 'profile-completeness-v1'),
                'config_version' => config('readiness.profile_completeness.config_version', 'profile-completeness-config-v1'),
            ];
        }

        $filledFields = 0;
        $missingFields = [];

        foreach ($fields as $field) {
            $value = $studentProfile->{$field};
            $filled = is_array($value)
                ? $value !== []
                : $value !== null && trim((string) $value) !== '';

            if ($filled) {
                $filledFields++;
            } else {
                $missingFields[] = $field;
            }
        }

        return [
            'score' => round(($filledFields / count($fields)) * 100, 2),
            'filled_fields' => $filledFields,
            'total_fields' => count($fields),
            'missing_fields' => $missingFields,
            'algorithm_version' => config('readiness.profile_completeness.algorithm_version', 'profile-completeness-v1'),
            'config_version' => config('readiness.profile_completeness.config_version', 'profile-completeness-config-v1'),
        ];
    }
}
