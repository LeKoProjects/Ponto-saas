<?php

namespace App\Http\Requests;

use App\Support\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canManage();
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'manager_user_id' => ['nullable', Rule::exists('users', 'id')->where('company_id', app(Tenant::class)->id())],
        ];
    }
}
