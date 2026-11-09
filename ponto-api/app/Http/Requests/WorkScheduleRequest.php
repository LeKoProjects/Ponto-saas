<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WorkScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canManage();
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'daily_minutes' => ['required', 'integer', 'min:1', 'max:720'],
            'break_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'weekdays' => ['required', 'array', 'min:1'],
            'weekdays.*' => ['integer', 'between:1,7'],
            'tolerance_minutes' => ['required', 'integer', 'min:0', 'max:10'],
        ];
    }

    public function messages(): array
    {
        return ['tolerance_minutes.max' => 'A CLT (art. 58 §1º) limita a tolerância a 10 minutos diários.'];
    }
}
