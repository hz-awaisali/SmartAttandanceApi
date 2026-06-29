<?php

namespace App\Services;

use App\Models\Teacher;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TeacherService
{
    public function create(array $data, string $role = 'teacher'): Teacher
    {
        return DB::transaction(function () use ($data, $role) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => $role,
            ]);

            return Teacher::create([
                'user_id' => $user->id,
                'department_id' => $data['department_id'] ?? null,
                'employee_no' => $data['employee_no'],
                'designation' => $data['designation'],
                'phone' => $data['phone'] ?? null,
            ]);
        });
    }

    public function update(Teacher $teacher, array $data): Teacher
    {
        return DB::transaction(function () use ($teacher, $data) {
            $teacher->user->fill(array_intersect_key($data, array_flip(['name', 'email', 'status'])))->save();
            $teacher->fill(array_intersect_key($data, array_flip(['department_id', 'employee_no', 'designation', 'phone'])))->save();

            return $teacher;
        });
    }
}
