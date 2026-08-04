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

            'proof_file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'This email is already registered.',
            'terms_accepted.accepted' => 'يجب قبول الشروط والأحكام.',
            'privacy_accepted.accepted' => 'يجب قبول سياسة الخصوصية.',
            'proof_file.required' => 'يرجى رفع ملف يثبت هوية المؤسسة (شهادة تسجيل الشركة أو الجامعة).',
            'proof_file.mimes' => 'يجب أن يكون الملف من نوع: jpg, jpeg, png, pdf.',
            'proof_file.max' => 'حجم الملف لا يتجاوز 5 ميجابايت.',
            'organization_name.required' => 'اسم المؤسسة مطلوب.',
            'organization_type.required' => 'يرجى تحديد نوع المؤسسة.',
        ];
    }
}
