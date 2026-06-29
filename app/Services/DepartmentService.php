<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Teacher;
use Illuminate\Support\Facades\DB;

class DepartmentService
{
    public function create(array $data): Department
    {
        return DB::transaction(function () use ($data) {
            $department = Department::create($data);

            if (! empty($data['hod_teacher_id'])) {
                $this->promoteToHod($data['hod_teacher_id'], $department->id);
            }

            return $department;
        });
    }

    public function update(Department $department, array $data): Department
    {
        return DB::transaction(function () use ($department, $data) {
            $previousHodTeacherId = $department->hod_teacher_id;

            $department->update($data);

            if (array_key_exists('hod_teacher_id', $data)) {
                if ($previousHodTeacherId && $data['hod_teacher_id'] !== $previousHodTeacherId) {
                    $this->demoteFromHod($previousHodTeacherId);
                }

                if (! empty($data['hod_teacher_id'])) {
                    $this->promoteToHod($data['hod_teacher_id'], $department->id);
                }
            }

            return $department;
        });
    }

    private function promoteToHod(int $teacherId, int $departmentId): void
    {
        $teacher = Teacher::find($teacherId);
        $teacher?->update(['department_id' => $departmentId]);
        $teacher?->user?->update(['role' => 'hod']);
    }

    /**
     * Only demote back to 'teacher' if this person isn't still the HOD of
     * some other department.
     */
    private function demoteFromHod(int $teacherId): void
    {
        $stillHod = Department::where('hod_teacher_id', $teacherId)->exists();

        if (! $stillHod) {
            Teacher::find($teacherId)?->user?->update(['role' => 'teacher']);
        }
    }
}
