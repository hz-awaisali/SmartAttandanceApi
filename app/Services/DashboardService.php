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
use App\Models\ProgramCourse;
use App\Models\Room;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\Timetable;
use App\Models\User;
use Illuminate\Support\Collection;

class DashboardService
{
    public function admin(): array
    {
        $todayStats = $this->attendanceStatsForDate(today());

        return [
            'counts' => [
                'departments' => Department::count(),
                'programs' => Program::count(),
                'courses' => ProgramCourse::count(),
                'batches' => Batch::count(),
                'teachers' => Teacher::count(),
                'students' => Student::count(),
                'rooms' => Room::count(),
                'active_sessions' => ClassSession::where('status', 'active')->count(),
            ],
            'today_present_count' => $todayStats['present'],
            'today_attendance_percentage' => $todayStats['percentage'],
            'monthly_growth_percentage' => $this->studentMonthlyGrowthPercentage(),
            'weekly_attendance' => $this->weeklyAttendance(),
            'monthly_attendance' => $this->monthlyAttendance(),
            'department_breakdown' => $this->departmentBreakdown(),
            'recent_activities' => AuditLogResource::collection(AuditLog::latest()->limit(10)->get()),
        ];
    }

    /** @return array{total: int, present: int, percentage: float} */
    private function attendanceStatsForDate(\DateTimeInterface $date): array
    {
        $sessions = ClassSession::whereDate('session_date', $date)->with('attendances')->get();

        $total = $sessions->sum(fn (ClassSession $session) => $session->attendances->count());
        $present = $sessions->sum(fn (ClassSession $session) => $session->attendances->where('status', 'present')->count());

        return [
            'total' => $total,
            'present' => $present,
            'percentage' => $total > 0 ? round($present / $total * 100, 1) : 0.0,
        ];
    }

    private function studentMonthlyGrowthPercentage(): float
    {
        $thisMonth = Student::whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])->count();
        $lastMonth = Student::whereBetween(
            'created_at',
            [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()]
        )->count();

        if ($lastMonth === 0) {
            return $thisMonth > 0 ? 100.0 : 0.0;
        }

        return round(($thisMonth - $lastMonth) / $lastMonth * 100, 1);
    }

    /** Attendance percentage for each of the last 7 days, oldest first. */
    private function weeklyAttendance(): Collection
    {
        return collect(range(6, 0))->map(function (int $daysAgo) {
            $date = today()->subDays($daysAgo);

            return [
                'date' => $date->toDateString(),
                'day' => $date->format('D'),
                'attendance_percentage' => $this->attendanceStatsForDate($date)['percentage'],
            ];
        })->values();
    }

    /** Attendance percentage for each of the last 6 months, oldest first. */
    private function monthlyAttendance(): Collection
    {
        return collect(range(5, 0))->map(function (int $monthsAgo) {
            // startOfMonth() first avoids Carbon's day-of-month overflow
            // (e.g. subtracting months from the 29th-31st can skip into the
            // wrong month when the target month is shorter).
            $month = now()->startOfMonth()->subMonths($monthsAgo);

            $sessions = ClassSession::whereYear('session_date', $month->year)
                ->whereMonth('session_date', $month->month)
                ->with('attendances')
                ->get();

            $total = $sessions->sum(fn (ClassSession $session) => $session->attendances->count());
            $present = $sessions->sum(fn (ClassSession $session) => $session->attendances->where('status', 'present')->count());

            return [
                'month' => $month->format('M'),
                'attendance_percentage' => $total > 0 ? round($present / $total * 100, 1) : 0.0,
            ];
        })->values();
    }

    /** Student headcount per department, for the dashboard's department pie chart. */
    private function departmentBreakdown(): Collection
    {
        return Department::withCount('students')
            ->get()
            ->map(fn (Department $department) => [
                'department_name' => $department->name,
                'students_count' => $department->students_count,
            ])
            ->values();
    }

    public function hod(User $user): array
    {
        $department = $user->teacher?->department()->with('hod')->first();

        if (! $department) {
            throw new BusinessException('No department is assigned to this HOD account.', 404);
        }

        $todaySessions = ClassSession::whereDate('session_date', today())
            ->whereHas('timetable.batch.program', fn ($query) => $query->where('department_id', $department->id))
            ->with(['attendances', 'timetable.batch'])
            ->get();

        $studentTotal = $todaySessions->sum(fn (ClassSession $session) => $session->attendances->count());
        $presentTotal = $todaySessions->sum(fn (ClassSession $session) => $session->attendances->where('status', 'present')->count());

        $programs = Program::where('department_id', $department->id)->get();

        $programBreakdown = $programs->map(function (Program $program) use ($todaySessions) {
            $programSessions = $todaySessions->filter(
                fn (ClassSession $session) => $session->timetable->batch->program_id === $program->id
            );

            $programStudentTotal = $programSessions->sum(fn (ClassSession $session) => $session->attendances->count());
            $programPresentTotal = $programSessions->sum(fn (ClassSession $session) => $session->attendances->where('status', 'present')->count());

            return [
                'program_id' => $program->id,
                'program_name' => $program->name,
                'program_code' => $program->code,
                'today_sessions_count' => $programSessions->count(),
                'today_attendance_percentage' => $programStudentTotal > 0
                    ? round($programPresentTotal / $programStudentTotal * 100, 1)
                    : 0.0,
            ];
        })->values();

        return [
            'department' => DepartmentResource::make($department),
            'teachers_count' => Teacher::where('department_id', $department->id)->count(),
            'students_count' => Student::where('department_id', $department->id)->count(),
            'today_sessions_count' => $todaySessions->count(),
            'today_attendance_percentage' => $studentTotal > 0 ? round($presentTotal / $studentTotal * 100, 1) : 0.0,
            'programs' => $programBreakdown,
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
