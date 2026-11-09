<?php

namespace App\Http\Requests;

use App\Models\SkillEvidence;
use App\Rules\EvidenceUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape validation for POST /api/v1/evidence.
 *
 * The endpoint used to validate inline in the controller with one flat rule set
 * — "a URL or a file, any type, any size" — because there was only one kind of
 * evidence. The frontend now submits four kinds, and the difference between
 * them is not cosmetic: a GitHub repository is proven by a link, a document is
 * proven by a file. A flat rule set cannot express "a link is required HERE but
 * forbidden THERE", so the rules are built per declared type.
 *
 * WHAT IS DELIBERATELY NOT REQUIRED
 * ---------------------------------
 * `evidence_type` is optional. Existing clients (and the existing test suite)
 * post `skill_id` + `evidence_url` with no type at all, and breaking them to
 * satisfy a new field would be a contract change nobody asked for. When the
 * type is absent the legacy contract applies unchanged — a URL or a file, at
 * least one — and the row is stored with `evidence_type = NULL`, which the API
 * reports honestly as "type not recorded".
 *
 * WHAT IS STRICTLY ENFORCED
 * -------------------------
 *  - `evidence_type` must be one of SkillEvidence::EVIDENCE_TYPES. An unknown
 *    value is a 422, never a new column value.
 *  - A URL-based type requires `evidence_url` and refuses `evidence_file`;
 *    `document` requires `evidence_file` and refuses `evidence_url`. Refusing
 *    the unused half is intentional: the old code let a client send both and
 *    silently threw one away, so the learner saw a 201 for a proof that was
 *    never stored.
 *  - The upload allow-list (pdf, jpg, jpeg, png, webp) and the 10 MB cap are
 *    carried over verbatim. `mimes` inspects the file's bytes, not the
 *    client-supplied name, so a renamed .php/.svg is still rejected.
 *
 * Authorization is NOT decided here — the route middleware
 * (`auth:sanctum`, `account.active`, `role:learner`) and the student-profile
 * check in the controller own that, exactly as before.
 */
class StoreEvidenceRequest extends FormRequest
{
    /**
     * The maximum size of an evidence upload, in kilobytes.
     *
     * 10000 KB is the 10 MB the frontend displays, and it is unchanged from the
     * previous inline rule.
     */
    public const MAX_FILE_KILOBYTES = 10000;

    /** The document types the evidence flow accepts. */
    public const ALLOWED_MIMES = 'pdf,jpg,jpeg,png,webp';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalise the type before validating it.
     *
     * `evidence_type` is trimmed and lower-cased so `" Document "` is accepted
     * as `document`. This is a normalisation, not a loosening: the value still
     * has to be one of the enum members afterwards, so an arbitrary string is
     * still a 422.
     */
    protected function prepareForValidation(): void
    {
        $type = $this->input('evidence_type');

        if (is_string($type)) {
            $type = strtolower(trim($type));

            $this->merge([
                'evidence_type' => $type === '' ? null : $type,
            ]);
        }
    }

    public function rules(): array
    {
        $type = $this->declaredEvidenceType();

        $isUrlBased = in_array($type, SkillEvidence::URL_BASED_TYPES, true);
        $isDocument = $type === SkillEvidence::TYPE_DOCUMENT;

        /*
         * NOTE: no `sometimes` on `evidence_url` / `evidence_file`.
         *
         * `sometimes` short-circuits every rule that follows it when the key is
         * absent, which silently disables `required` — exactly the rule that has
         * to fire for a type whose proof is missing. `nullable` already covers
         * the "present but empty" case, and `required`/`required_without` still
         * fail on an absent or null value.
         */
        $urlRules = [
            'nullable',
            'string',
            /*
             * `reference` is a VARCHAR(255) and the URL is stored in it
             * verbatim. Capping the input at the column width turns a
             * would-be database error into a normal 422.
             */
            'max:255',
            'url',
        ];

        if ($isUrlBased) {
            $urlRules[] = 'required';
        } elseif ($isDocument) {
            $urlRules[] = 'prohibited';
        } else {
            /*
             * Legacy contract only (no `evidence_type` declared): a URL or a
             * file, at least one. When a type IS declared, the type-specific
             * rule in this branch already reports the real problem — "a URL is
             * required", or "this type is a file upload" — so adding this
             * vaguer message on top would only bury it.
             */
            $urlRules[] = 'required_without:evidence_file';
        }

        $urlRules[] = new EvidenceUrl($type);

        $fileRules = [
            'nullable',
            'file',
            'mimes:'.self::ALLOWED_MIMES,
            'max:'.self::MAX_FILE_KILOBYTES,
        ];

        if ($isDocument) {
            $fileRules[] = 'required';
        } elseif ($isUrlBased) {
            $fileRules[] = 'prohibited';
        } else {
            $fileRules[] = 'required_without:evidence_url';
        }

        return [
            'skill_id' => [
                'required',
                'integer',
                Rule::exists('skills', 'id')->where(function ($query) {
                    $query->where('status', 'active');
                }),
            ],

            'evidence_type' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in(SkillEvidence::EVIDENCE_TYPES),
            ],

            'evidence_url' => $urlRules,

            'evidence_file' => $fileRules,

            'description' => [
                'sometimes',
                'nullable',
                'string',
                'max:500',
            ],

            'evidence_date' => [
                'sometimes',
                'nullable',
                'date',
            ],

            /*
             * The learner's own claim of the level this evidence demonstrates,
             * on the same 0–5 scale the assessment uses.
             *
             * Optional, and it stays optional: an older client that sends
             * nothing keeps the previous behaviour. But without it the row is
             * stored with `normalized_value = 0`, and `SkillEvaluationService`
             * averages `normalized_value` across verified evidence — so five
             * accepted proofs of a skill produced a final level of exactly
             * 0.00 and the skills matrix looked empty. A proof has to carry a
             * value to move a level.
             */
            'level' => [
                'sometimes',
                'nullable',
                'numeric',
                'min:0',
                'max:5',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'evidence_type.in' => 'The selected evidence type is invalid. Allowed values: '
                .implode(', ', SkillEvidence::EVIDENCE_TYPES).'.',

            'evidence_url.required' => 'An evidence URL is required for the selected evidence type.',
            'evidence_url.prohibited' => 'This evidence type is submitted as a file upload, not a URL.',
            'evidence_url.required_without' => 'Provide either an evidence URL or an evidence file.',
            'evidence_url.url' => 'The evidence URL must be a valid URL.',

            'evidence_file.required' => 'An evidence file is required for document evidence.',
            'evidence_file.prohibited' => 'This evidence type is submitted as a URL, not a file upload.',
            'evidence_file.required_without' => 'Provide either an evidence URL or an evidence file.',
            'evidence_file.mimes' => 'The evidence file must be one of: pdf, jpg, jpeg, png, webp.',
            'evidence_file.max' => 'The evidence file may not be larger than 10 MB.',

            'skill_id.exists' => 'The selected skill is invalid or not active.',
        ];
    }

    /**
     * The evidence type the client DECLARED, or null when it declared none.
     *
     * Deliberately not inferred here: inference (a lone upload is a document)
     * happens in the controller, where it decides what gets stored, and must not
     * change which rules run. Otherwise a legacy request carrying both a URL and
     * a file would suddenly be validated as `document` and have its URL refused.
     */
    public function declaredEvidenceType(): ?string
    {
        $type = $this->input('evidence_type');

        return is_string($type) && $type !== '' ? $type : null;
    }
}
