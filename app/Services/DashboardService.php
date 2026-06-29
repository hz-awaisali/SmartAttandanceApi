<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Http\Resources\AuditLogResource;
use App\Http\Resources\DepartmentResource;
use App\Http\Resources\TimetableResource;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\ClassSession;
use App\Models\Department;
use App\Models\Program;
use App\Models\Room;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\Timetable;
use App\Models\User;

class DashboardService
{
    public function admin(): array
    {
        return [
            'counts' => [
                'departments' => Department::count(),
                'programs' => Program::count(),
                'batches' => Batch::count(),
                'teachers' => Teacher::count(),
                'students' => Student::count(),
                'rooms' => Room::count(),
                'active_sessions' => ClassSession::where('status', 'active')->count(),
            ],
            'recent_activities' => AuditLogResource::collection(AuditLog::latest()->limit(10)->get()),
        ];
    }

    public function hod(User $user): array
    {
        $department = $user->teacher?->department;

        if (! $department) {
            throw new BusinessException('No department is assigned to this HOD account.', 404);
        }

        $todaySessions = ClassSession::whereDate('session_date', today())
            ->whereHas('timetable.batch.program', fn ($query) => $query->where('department_id', $department->id))
            ->with('attendances')
            ->get();

        $studentTotal = $todaySessions->sum(fn (ClassSession $session) => $session->attendances->count());
        $presentTotal = $todaySessions->sum(fn (ClassSession $session) => $session->attendances->where('status', 'present')->count());

        return [
            'department' => DepartmentResource::make($department),
            'teachers_count' => Teacher::where('department_id', $department->id)->count(),
            'students_count' => Student::where('department_id', $department->id)->count(),
            'today_sessions_count' => $todaySessions->count(),
            'today_attendance_percentage' => $studentTotal > 0 ? round($presentTotal / $studentTotal * 100, 1) : 0.0,
        ];
    }

    public function teacher(User $user): array
    {
        $teacher = $user->teacher;

        if (! $teacher) {
            throw new BusinessException('No teacher profile is linked to this account. Please contact the administrator.', 404);
        }

        $todayName = now()->format('l');

        $todayTimetables = Timetable::where('teacher_id', $teacher->id)
            ->where('day', $todayName)
            ->with(['course.program.department', 'room', 'timeSlot', 'batch.program.department'])
            ->get();

        $todaySessionsStarted = ClassSession::whereDate('session_date', today())
            ->whereIn('timetable_id', $todayTimetables->pluck('id'))
            ->count();

        return [
            'today_classes' => TimetableResource::collection($todayTimetables),
            'today_classes_count' => $todayTimetables->count(),
            'today_sessions_started' => $todaySessionsStarted,
        ];
    }

    public function student(User $user): array
    {
        $student = $user->student;

        if (! $student) {
            throw new BusinessException('No student profile is linked to this account. Please contact the administrator.', 404);
        }

        $todayName = now()->format('l');

        $todayClassesCount = Timetable::where('batch_id', $student->batch_id)
            ->where('day', $todayName)
            ->count();

        return [
            'today_classes_count' => $todayClassesCount,
            'attendance_percentage' => $student->attendance_percentage,
        ];
    }
}
