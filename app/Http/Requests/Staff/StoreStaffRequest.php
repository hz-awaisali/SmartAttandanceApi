<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

class StoreStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'admin_department_id' => ['required', 'integer', 'exists:admin_departments,id'],
            'employee_no' => ['required', 'string', 'max:50', 'unique:staff,employee_no'],
            'designation' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
        ];
    }
}
