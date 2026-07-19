<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->route('staff')?->user_id)],
            'admin_department_id' => ['sometimes', 'required', 'integer', 'exists:admin_departments,id'],
            'employee_no' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('staff', 'employee_no')->ignore($this->route('staff'))],
            'designation' => ['sometimes', 'required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
            'status' => ['sometimes', 'required', 'string', 'in:active,inactive'],
        ];
    }
}
