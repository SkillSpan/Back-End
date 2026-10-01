<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * نطبّع الإيميل (حروف صغيرة + إزالة مسافات) قبل الفاليديشن،
     * عشان قاعدة unique تكتشف التكرار حتى لو انكتب بحروف مختلفة.
     *
     * نسخ احتياطي للأسماء القديمة (بدون بادئة organization_):
     * الـ frontend كان يبعت seven حقول بأسماء بدون البادئة
     * (`organization_size`, `website`, `description`, `country`, `city`,
     * `address`, `postal_code`)، اللي ما كانت متطابقة مع أي قاعدة
     * فاليديشن، فكانت validation بتنجح مع `null` وما بتخزن شي —
     * ولهذا الـ admin panel كان يعرض "No description was provided"
     * حتى لو المستخدم كتب وصف. حالياً الـ patch جاهز في
     * `FRONTEND_ORG_FIELD_FIX.patch`، لكن لحد ما يُطبَّق على
     * الـ frontend بنقبل كلتا التسميتين — prefixed (canonical) ولّا
     * unprefixed (legacy). لما يُحدَّث الـ frontend، يصير الـ fallback
     * no-op بدون أي تغيير بالعقد.
     *
     * normalization of the email is also done here (lowercase + trim)
     * so the unique rule catches duplicates regardless of letter casing.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
        }

        // Canonical → legacy aliases. The prefixed name is the contract;
        // the unprefixed one is the older send that the frontend was using
        // before FRONTEND_ORG_FIELD_FIX landed. We only fall back when the
        // canonical value is empty, so a future client that sends both
        // correctly cannot be surprised by the wrong one winning.
        $legacyAliases = [
            'organization_company_size' => 'organization_size',
            'organization_website' => 'website',
            'organization_description' => 'description',
            'organization_country' => 'country',
            'organization_city' => 'city',
            'organization_address' => 'address',
            'organization_postal_code' => 'postal_code',
        ];

        foreach ($legacyAliases as $canonical => $legacy) {
            $canonicalValue = $this->input($canonical);
            $legacyValue = $this->input($legacy);

            if (($canonicalValue === null || $canonicalValue === '')
                && $legacyValue !== null && $legacyValue !== '') {
                $this->merge([$canonical => $legacyValue]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // Same disposable-email gate as the individual flow — this is the
            // other self-registration path. Deliberately NOT applied to
            // 'organization_contact_email' below: that is a public contact
            // address, not the account identity, and the account cannot log in
            // until an administrator approves the organisation.
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email', 'indisposable'],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'terms_accepted' => ['required', 'accepted'],
            'privacy_accepted' => ['required', 'accepted'],
            'locale' => ['nullable', 'string', 'max:10', 'in:en,ar'],

            'organization_name' => ['required', 'string', 'max:255'],
            'organization_type' => ['required', Rule::in(['company', 'university', 'training_partner'])],
            'organization_contact_email' => ['required', 'email', 'max:255'],
            'organization_contact_phone' => ['nullable', 'string', 'max:20'],
            'organization_website' => ['nullable', 'url', 'max:255'],
            'organization_description' => ['nullable', 'string', 'max:1000'],
            'organization_industry' => ['nullable', 'string', 'max:255'],
            'organization_company_size' => ['nullable', 'string', 'max:50'],
            'organization_country' => ['nullable', 'string', 'max:255'],
            'organization_city' => ['nullable', 'string', 'max:255'],
            'organization_address' => ['nullable', 'string', 'max:500'],
            'organization_postal_code' => ['nullable', 'string', 'max:20'],

            'proof_file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'This email is already registered.',
            'email.indisposable' => 'Disposable or temporary email addresses are not allowed.',
            'terms_accepted.accepted' => 'You must accept the Terms and Conditions.',
            'privacy_accepted.accepted' => 'You must accept the Privacy Policy.',
            'proof_file.required' => 'Please upload a document proving the organization\'s identity (company or university registration certificate).',
            'proof_file.mimes' => 'The file must be one of the following types: jpg, jpeg, png, pdf.',
            'proof_file.max' => 'The file size must not exceed 5 MB.',
            'organization_name.required' => 'The organization name is required.',
            'organization_type.required' => 'Please specify the organization type.',
        ];
    }
}
