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

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
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
