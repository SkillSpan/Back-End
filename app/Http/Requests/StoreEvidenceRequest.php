<?php

namespace App\Http\Requests;

use App\Models\SkillEvidence;
use App\Rules\EvidenceUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEvidenceRequest extends FormRequest
{
    public const MAX_FILE_KILOBYTES = 10000;

    public const ALLOWED_MIMES = 'pdf,jpg,jpeg,png,webp';

    public function authorize(): bool
    {
        return true;
    }

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

        $urlRules = [
            'nullable',
            'string',
            'max:255',
            'url',
        ];

        if ($isUrlBased) {
            $urlRules[] = 'required';
        } elseif ($isDocument) {
            $urlRules[] = 'prohibited';
        } else {
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

    public function declaredEvidenceType(): ?string
    {
        $type = $this->input('evidence_type');

        return is_string($type) && $type !== '' ? $type : null;
    }
}
