<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['sometimes', 'integer', 'exists:branches,id'],
            'name' => ['sometimes', 'string', 'max:191'],
            'parent_department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ];
    }
}
