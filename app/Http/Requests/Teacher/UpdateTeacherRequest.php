<?php

namespace App\Http\Requests\Teacher;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTeacherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->route('teacher')?->user_id)],
            'department_id' => ['sometimes', 'nullable', 'integer', 'exists:departments,id'],
            'employee_no' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('teachers', 'employee_no')->ignore($this->route('teacher'))],
            'designation' => ['sometimes', 'required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
            'status' => ['sometimes', 'required', 'string', 'in:active,inactive'],
        ];
    }
}
