<?php

namespace App\Http\Requests;

use App\Support\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Tratamento/solicitação de ponto. Usado pelo painel (RH lança para um
 * funcionário) e pela PWA (colaborador solicita para si — sem employee_id).
 */
class AdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->is('api/*')
            ? (bool) $this->user()?->employee
            : (bool) $this->user()?->canManage();
    }

    public function rules(): array
    {
        $companyId = app(Tenant::class)->id();

        return [
            'employee_id' => [$this->is('api/*') ? 'prohibited' : 'required', Rule::exists('employees', 'id')->where('company_id', $companyId)],
            'type' => ['required', Rule::in(['inclusao', 'desconsideracao', 'alteracao'])],
            'date' => ['required_if:type,inclusao', 'nullable', 'date', 'before_or_equal:today'],
            'time' => ['required_if:type,inclusao,alteracao', 'nullable', 'date_format:H:i'],
            'time_entry_id' => ['required_if:type,desconsideracao,alteracao', 'nullable', 'integer'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'time.required_if' => 'Informe o horário.',
            'time_entry_id.required_if' => 'Selecione a batida.',
            'date.required_if' => 'Informe a data.',
            'reason.min' => 'Descreva a justificativa (mínimo 5 caracteres).',
        ];
    }
}
