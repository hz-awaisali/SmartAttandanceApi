<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['required', 'string', 'in:admin,hod,teacher,student'],
        ];

        if (in_array($this->input('role'), ['teacher', 'hod'], true)) {
            // A teacher isn't tied to one department — they may teach courses
            // across several departments, and the real assignment lives on
            // the timetable. An HOD does need a home department, though.
            $rules['department_id'] = $this->input('role') === 'hod'
                ? ['required', 'integer', 'exists:departments,id']
                : ['nullable', 'integer', 'exists:departments,id'];
            $rules['employee_no'] = ['required', 'string', 'max:50', 'unique:teachers,employee_no'];
            $rules['designation'] = ['required', 'string', 'max:100'];
            $rules['phone'] = ['nullable', 'string', 'max:20'];
        }

        if ($this->input('role') === 'student') {
            $rules['registration_no'] = ['required', 'string', 'max:50', 'unique:students,registration_no'];
            $rules['department_id'] = ['required', 'integer', 'exists:departments,id'];
            $rules['batch_id'] = ['required', 'integer', 'exists:batches,id'];
            $rules['phone'] = ['nullable', 'string', 'max:20'];
        }

        return $rules;
    }
}
