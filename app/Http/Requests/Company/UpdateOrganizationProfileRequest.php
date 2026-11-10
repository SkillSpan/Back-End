<?php

namespace App\Http\Requests\Company;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateOrganizationProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:255'],
            'industry' => ['sometimes', 'nullable', 'string', 'max:120'],
            'company_size' => ['sometimes', 'nullable', 'string', 'max:50'],
            'website' => ['sometimes', 'nullable', 'string', 'max:255', $this->websiteRule()],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'country' => ['sometimes', 'nullable', 'string', 'max:100'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'contact_email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^\+?[0-9][0-9\s\-().]{5,30}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'The company name cannot be empty.',
            'name.min' => 'The company name is too short.',
            'contact_email.email' => 'The contact e-mail is not a valid e-mail address.',
            'contact_phone.regex' => 'The contact phone must be a phone number — digits, spaces, dashes, brackets and an optional leading +.',
            'website.regex' => 'The website must be a real address with a domain, for example https://acme.com.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $website = $this->input('website');

        if (is_string($website)) {
            $trimmed = trim($website);

            if ($trimmed === '') {
                $this->merge(['website' => null]);
            } elseif (! preg_match('#^https?://#i', $trimmed)) {
                $this->merge(['website' => 'https://'.$trimmed]);
            } else {
                $this->merge(['website' => $trimmed]);
            }
        }
    }

    private function websiteRule(): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }

            $pattern = '#^https?://[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*\.[a-z]{2,}(:\d{2,5})?(/[^\s]*)?$#i';

            if (! is_string($value) || preg_match($pattern, $value) !== 1) {
                $fail('The website must be a real address with a domain, for example https://acme.com.');
            }
        };
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'The profile could not be saved. Please check the highlighted fields.',
            'code' => 'PROFILE_VALIDATION_FAILED',
            'errors' => $validator->errors(),
        ], 422));
    }
}
