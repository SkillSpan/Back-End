<?php

namespace App\Http\Requests\Company;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * US-COMP-04 — BR-04 / BR-05: required fields and the e-mail, website and
 * phone formats are validated on the server, not trusted from the browser.
 *
 * Two details are deliberate:
 *
 *  - **`sometimes` on every rule.** The endpoint is a PATCH: a client that
 *    edits only the website must not be forced to resend the whole profile.
 *  - **A `code` on the failure response.** The story asks for clear errors and
 *    the frontend branches on codes; Laravel's default 422 body carries field
 *    messages but no code, so `failedValidation` adds one. `errors` keeps the
 *    field-level shape the form needs to highlight inputs (AC-05).
 */
class UpdateOrganizationProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route already gates on auth:sanctum + organization.approved +
        // role:company_admin, and the controller resolves the organization
        // from the caller's membership. There is nothing left to authorize
        // here — a second, weaker check would only give a false sense of
        // safety.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
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

    /**
     * @return array<string, string>
     */
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

    /**
     * A scheme is optional in what the client sends but not in what we store:
     * "acme.com" becomes "https://acme.com" so every stored website can be
     * rendered as a link without the client guessing.
     */
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

    /**
     * BR-05 — a bare host with no dot ("acme") is not a website, and the
     * previous `url` rule accepted it once a scheme was prepended. This
     * requires at least one dot in the host and a two-letter-plus TLD.
     */
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
