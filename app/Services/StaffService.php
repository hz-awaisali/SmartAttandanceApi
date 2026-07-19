<?php

namespace App\Services;

use App\Models\Staff;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class StaffService
{
    public function create(array $data): Staff
    {
        return DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => 'staff',
                'avatar' => $data['avatar'] ?? null,
            ]);

            return Staff::create([
                'user_id' => $user->id,
                'admin_department_id' => $data['admin_department_id'],
                'employee_no' => $data['employee_no'],
                'designation' => $data['designation'],
                'phone' => $data['phone'] ?? null,
            ]);
        });
    }

    public function update(Staff $staff, array $data): Staff
    {
        return DB::transaction(function () use ($staff, $data) {
            $staff->user->fill(array_intersect_key($data, array_flip(['name', 'email', 'status'])))->save();
            $staff->fill(array_intersect_key($data, array_flip(['admin_department_id', 'employee_no', 'designation', 'phone'])))->save();

            return $staff;
        });
    }
}
