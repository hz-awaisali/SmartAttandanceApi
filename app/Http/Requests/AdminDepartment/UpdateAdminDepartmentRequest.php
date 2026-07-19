<?php

namespace App\Http\Requests\AdminDepartment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdminDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('admin_departments', 'name')->ignore($this->route('adminDepartment'))],
        ];
    }
}
