<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDesignationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['sometimes', 'integer', 'exists:branches,id'],
            'department_id' => ['sometimes', 'integer', 'exists:departments,id'],
            'title' => ['sometimes', 'string', 'max:191'],
            'level' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
