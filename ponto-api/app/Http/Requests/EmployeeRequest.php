<?php

namespace App\Http\Requests;

use App\Rules\Cpf;
use App\Support\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canManage();
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['cpf' => preg_replace('/\D/', '', (string) $this->input('cpf'))]);
    }

    public function rules(): array
    {
        $companyId = app(Tenant::class)->id();
        $employee = $this->route('employee');

        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:160', Rule::unique('users', 'email')->ignore($employee?->user_id)],
            'password' => [$employee ? 'nullable' : 'required', 'string', 'min:8'],
            'role' => ['required', Rule::in(['funcionario', 'gestor', 'rh'])],
            'cpf' => ['required', new Cpf, Rule::unique('employees', 'cpf')->where('company_id', $companyId)->ignore($employee?->id)],
            'registration' => ['nullable', 'string', 'max:30'],
            'admission_date' => ['required', 'date'],
            'work_schedule_id' => ['required', Rule::exists('work_schedules', 'id')->where('company_id', $companyId)],
            'team_id' => ['nullable', Rule::exists('teams', 'id')->where('company_id', $companyId)],
            'active' => ['boolean'],
        ];
    }
}
