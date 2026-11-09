<?php

namespace App\Http\Requests;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isSuperAdmin();
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('cnpj')) {
            $this->merge(['cnpj' => preg_replace('/\D/', '', (string) $this->input('cnpj'))]);
        }
    }

    public function rules(): array
    {
        $company = $this->route('company');

        return [
            'cnpj' => ['required', 'digits:14', Rule::unique('companies', 'cnpj')->ignore($company?->id)],
            'legal_name' => ['required', 'string', 'max:160'],
            'trade_name' => ['nullable', 'string', 'max:120'],
            'plan' => ['required', Rule::in(array_keys(Company::PLANS))],
            'status' => ['required', Rule::in(['active', 'blocked'])],
            'owner_name' => [$company ? 'nullable' : 'required', 'string', 'max:120'],
            'owner_email' => [$company ? 'nullable' : 'required', 'email', 'unique:users,email'],
            'owner_password' => [$company ? 'nullable' : 'required', 'string', 'min:8'],
        ];
    }
}
