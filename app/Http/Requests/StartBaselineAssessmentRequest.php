<?php

namespace App\Http\Requests;

use App\Models\CareerRole;
use App\Models\Specialization;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/baseline-assessments
 *
 * Two independent rules, both about ACTIVATION rather than about the pivot:
 *
 *   career_role_id    — must exist, be approved AND be active.
 *   specialization_id — optional; when sent it must exist and be active.
 *
 * WHAT CHANGED IN THE MVP
 * -----------------------
 * This request used to cross-check that the role was linked to the given
 * specialization through `career_role_specialization`, which made the pivot an
 * access-control list. Under the MVP policy the specialization no longer
 * restricts the choice — a Computer Science learner may start an assessment
 * for a Data Science role — so that cross-check is gone. The specialization
 * still validates as a value (it must be a real, active track), it simply no
 * longer vetoes the role.
 *
 * A DEACTIVATED ROLE CANNOT START A NEW ASSESSMENT. This is the one place the
 * activation flag blocks a write: every read path (an assessment that already
 * exists, its snapshot, readiness, roadmaps) keeps working, because those rows
 * reference the role by id and are never re-validated here.
 */
class StartBaselineAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'career_role_id' => [
                'required',
                'integer',
                'exists:career_roles,id',
                function ($attribute, $value, $fail) {
                    $role = CareerRole::find($value);

                    if (! $role) {
                        return; // already reported by the exists rule
                    }

                    if ($role->status !== 'approved') {
                        $fail('The selected career role is not approved.');

                        return;
                    }

                    // Deactivated for the MVP: not offered, so not startable.
                    // Its history stays readable — only NEW assessments are
                    // refused.
                    if (! $role->is_active) {
                        $fail('The selected career role is not available.');
                    }
                },
            ],

            // Optional: when supplied, it must be a real, active track.
            // Omitting it preserves the original career_role_id-only contract
            // for existing consumers.
            'specialization_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                'exists:specializations,id',
                function ($attribute, $value, $fail) {
                    if (! is_numeric($value)) {
                        return;
                    }

                    $isActive = Specialization::query()
                        ->whereKey((int) $value)
                        ->where('is_active', true)
                        ->exists();

                    if (! $isActive) {
                        $fail('The selected specialization is not available.');
                    }
                },
            ],
        ];
    }

    /**
     * There is deliberately no `withValidator()` cross-field rule here.
     *
     * The one this request used to carry ("the role must belong to the
     * specialization") encoded the old pivot-as-access-list behaviour. Under
     * the MVP policy any active role may be assessed under any active
     * specialization, so the only remaining constraints are the two activation
     * checks above — and those belong in `rules()` so they run whether or not
     * the other id is present.
     */
    public function messages(): array
    {
        return [
            'career_role_id.required' => 'A career role ID is required to start a baseline assessment.',
            'career_role_id.integer' => 'The career role ID must be an integer.',
            'career_role_id.exists' => 'The selected career role does not exist.',
            'specialization_id.integer' => 'The specialization ID must be a positive integer.',
            'specialization_id.min' => 'The specialization ID must be a positive integer.',
            'specialization_id.exists' => 'The selected specialization does not exist.',
        ];
    }
}
