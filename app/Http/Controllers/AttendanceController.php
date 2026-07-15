<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessException;
use App\Http\Requests\Attendance\MarkAttendanceRequest;
use App\Http\Resources\AttendanceResource;
use App\Models\ClassSession;
use App\Services\AttendanceReportService;
use App\Services\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function __construct(
        private AttendanceService $attendance,
        private AttendanceReportService $reports,
    ) {}

    public function mark(MarkAttendanceRequest $request): JsonResponse
    {
        $student = $request->user()->student;

        if (! $student) {
            return $this->fail('Only students can mark attendance.', 403);
        }

        $session = ClassSession::with('timetable.room')->findOrFail($request->validated('session_id'));

        $attendance = $this->attendance->mark(
            $session,
            $student,
            $request->validated('detected_uuid'),
            $request->validated('detected_major'),
            $request->validated('rssi'),
            $request->validated('latitude'),
            $request->validated('longitude'),
        );

        return $this->ok(AttendanceResource::make($attendance), 'Attendance marked as present');
    }

    public function report(Request $request): JsonResponse
    {
        $user = $request->user();

        $filters = $request->validate([
            'course_id' => ['nullable', 'integer', 'exists:program_courses,id'],
            'batch_id' => ['nullable', 'integer', 'exists:batches,id'],
            'date' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($user->isAdmin()) {
            $report = $this->reports->generateGlobal($filters);
        } elseif ($user->isHod()) {
            $departmentId = $user->teacher?->department_id;

            if (! $departmentId) {
                throw new BusinessException('Only HODs with a department can view attendance reports.', 403);
            }

            $report = $this->reports->generateForDepartment($departmentId, $filters);
        } else {
            $teacher = $user->teacher;

            if (! $teacher) {
                throw new BusinessException('Only teachers can view attendance reports.', 403);
            }

            $report = $this->reports->generate($teacher, $filters);
        }

        return $this->ok(AttendanceResource::collection($report));
    }
}
