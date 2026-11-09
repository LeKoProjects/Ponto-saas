<?php

namespace App\Http\Requests;

use App\Support\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Tratamento de ponto lançado pelo RH (inclusão ou desconsideração). */
class AdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canManage();
    }

    public function rules(): array
    {
        $companyId = app(Tenant::class)->id();

        return [
            'employee_id' => ['required', Rule::exists('employees', 'id')->where('company_id', $companyId)],
            'type' => ['required', Rule::in(['inclusao', 'desconsideracao'])],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'time' => ['required_if:type,inclusao', 'nullable', 'date_format:H:i'],
            'time_entry_id' => ['required_if:type,desconsideracao', 'nullable', Rule::exists('time_entries', 'id')->where('company_id', $companyId)],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
