<?php

namespace App\Http\Requests\AdminDepartment;

use Illuminate\Foundation\Http\FormRequest;

class StoreAdminDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:admin_departments,name'],
        ];
    }
}
