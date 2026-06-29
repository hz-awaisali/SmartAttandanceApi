<?php

namespace App\Http\Controllers;

use App\Http\Resources\AttendanceResource;
use App\Http\Resources\ClassSessionResource;
use App\Models\ClassSession;
use App\Models\Timetable;
use App\Services\SessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    public function __construct(private SessionService $sessions) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = ClassSession::with(['timetable.course', 'timetable.room', 'timetable.batch', 'timetable.teacher.user']);

        if ($user->isTeacher()) {
            $query->whereHas('timetable', fn ($q) => $q->where('teacher_id', $user->teacher?->id));
        } elseif ($user->isStudent()) {
            $query->whereHas('timetable', fn ($q) => $q->where('batch_id', $user->student?->batch_id));
        } elseif ($user->isHod()) {
            $query->whereHas('timetable.batch.program', fn ($q) => $q->where('department_id', $user->teacher?->department_id));
        }

        $sessions = $query->latest('session_date')->paginate(15);

        return $this->ok(ClassSessionResource::collection($sessions));
    }

    public function show(Request $request, ClassSession $session): JsonResponse
    {
        $this->authorize('view', $session);

        $session->load(['timetable.course', 'timetable.room', 'timetable.batch', 'timetable.teacher.user', 'attendances']);

        return $this->ok(ClassSessionResource::make($session));
    }

    public function start(Request $request, Timetable $timetable): JsonResponse
    {
        $this->authorize('start', $timetable);

        $session = $this->sessions->start($timetable, $request->user());

        return $this->ok(ClassSessionResource::make($session->load('attendances')), 'Session started', 201);
    }

    public function end(Request $request, ClassSession $session): JsonResponse
    {
        $this->authorize('end', $session);

        $session = $this->sessions->end($session, $request->user());

        return $this->ok(ClassSessionResource::make($session), 'Session ended');
    }

    public function attendance(Request $request, ClassSession $session): JsonResponse
    {
        $this->authorize('view', $session);

        $attendances = $session->attendances()->with('student.user')->get();

        return $this->ok([
            'session' => ClassSessionResource::make($session->load('timetable.course')),
            'present_count' => $attendances->where('status', 'present')->count(),
            'absent_count' => $attendances->where('status', 'absent')->count(),
            'students' => AttendanceResource::collection($attendances),
        ]);
    }
}
