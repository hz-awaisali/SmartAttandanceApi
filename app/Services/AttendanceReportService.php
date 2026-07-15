<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Teacher;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class AttendanceReportService
{
    public function generate(Teacher $teacher, array $filters): LengthAwarePaginator
    {
        $query = Attendance::query()
            ->with(['student.user', 'session.timetable.course', 'session.timetable.batch'])
            ->whereHas('session.timetable', function ($query) use ($teacher, $filters) {
                $query->where('teacher_id', $teacher->id);

                if (! empty($filters['course_id'])) {
                    $query->where('program_course_id', $filters['course_id']);
                }

                if (! empty($filters['batch_id'])) {
                    $query->where('batch_id', $filters['batch_id']);
                }
            });

        if (! empty($filters['date'])) {
            $query->whereHas('session', fn ($query) => $query->whereDate('session_date', $filters['date']));
        }

        return $query->latest('id')->paginate($filters['per_page'] ?? 15);
    }

    public function generateForDepartment(int $departmentId, array $filters): LengthAwarePaginator
    {
        $query = Attendance::query()
            ->with(['student.user', 'session.timetable.course', 'session.timetable.batch', 'session.timetable.teacher.user'])
            ->whereHas('session.timetable', function ($query) use ($departmentId, $filters) {
                $query->whereHas('batch.program', fn ($q) => $q->where('department_id', $departmentId));

                if (! empty($filters['course_id'])) {
                    $query->where('program_course_id', $filters['course_id']);
                }

                if (! empty($filters['batch_id'])) {
                    $query->where('batch_id', $filters['batch_id']);
                }
            });

        if (! empty($filters['date'])) {
            $query->whereHas('session', fn ($query) => $query->whereDate('session_date', $filters['date']));
        }

        return $query->latest('id')->paginate($filters['per_page'] ?? 15);
    }

    public function generateGlobal(array $filters): LengthAwarePaginator
    {
        $query = Attendance::query()
            ->with(['student.user', 'session.timetable.course', 'session.timetable.batch.program.department', 'session.timetable.teacher.user'])
            ->whereHas('session.timetable', function ($query) use ($filters) {
                if (! empty($filters['course_id'])) {
                    $query->where('program_course_id', $filters['course_id']);
                }

                if (! empty($filters['batch_id'])) {
                    $query->where('batch_id', $filters['batch_id']);
                }
            });

        if (! empty($filters['date'])) {
            $query->whereHas('session', fn ($query) => $query->whereDate('session_date', $filters['date']));
        }

        return $query->latest('id')->paginate($filters['per_page'] ?? 15);
    }
}
