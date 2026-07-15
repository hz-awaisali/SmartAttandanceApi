<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Attendance;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\Timetable;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SessionService
{
    public function __construct(
        private NotificationService $notifications,
        private AuditLogService $auditLog,
    ) {}

    public function start(Timetable $timetable, User $teacherUser): ClassSession
    {
        $this->assertWithinPeriodWindow($timetable);

        $today = now()->toDateString();

        $alreadyActive = ClassSession::where('timetable_id', $timetable->id)
            ->where('session_date', $today)
            ->where('status', 'active')
            ->exists();

        if ($alreadyActive) {
            throw new BusinessException('A session for this class is already active today.', 409);
        }

        return DB::transaction(function () use ($timetable, $teacherUser, $today) {
            $session = ClassSession::create([
                'timetable_id' => $timetable->id,
                'session_date' => $today,
                'start_time' => $timetable->timeSlot->start_time,
                'end_time' => $timetable->timeSlot->end_time,
                'status' => 'active',
            ]);

            $students = Student::where('batch_id', $timetable->batch_id)->get();

            if ($students->isNotEmpty()) {
                Attendance::insert($students->map(fn (Student $student) => [
                    'session_id' => $session->id,
                    'student_id' => $student->id,
                    'status' => 'absent',
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all());
            }

            $room = $timetable->room;

            $this->notifications->sendMany(
                $students->pluck('user_id'),
                'Attendance Started',
                "Attendance has started for {$timetable->course->course_title}. Open the app in Room {$room->room_no} to mark your attendance.",
                'attendance_started',
                ['session_id' => $session->id, 'room_id' => $room->id, 'room_no' => $room->room_no],
            );

            $this->auditLog->log($teacherUser->id, 'session_started', "Started session for timetable #{$timetable->id}", $session);

            return $session->load(['timetable.course', 'timetable.room', 'timetable.batch', 'timetable.teacher']);
        });
    }

    private function assertWithinPeriodWindow(Timetable $timetable): void
    {
        $slot = $timetable->timeSlot;
        $now = now();

        $start = Carbon::createFromTimeString($slot->start_time);
        $end = Carbon::createFromTimeString($slot->end_time);

        if ($now->lt($start)) {
            throw new BusinessException(
                "This period hasn't started yet. Attendance can be started at {$start->format('h:i A')}."
            );
        }

        if ($now->gt($end)) {
            throw new BusinessException(
                "This period has already ended at {$end->format('h:i A')}. Attendance can no longer be started."
            );
        }
    }

    public function end(ClassSession $session, User $teacherUser): ClassSession
    {
        if ($session->status !== 'active') {
            throw new BusinessException('This session is not active.', 409);
        }

        return DB::transaction(function () use ($session, $teacherUser) {
            $session->update(['status' => 'ended']);

            $absentees = $session->attendances()->where('status', 'absent')->with('student')->get();

            foreach ($absentees as $attendance) {
                $this->notifications->send(
                    $attendance->student->user_id,
                    'Attendance Marked',
                    'You were marked absent for this session.',
                    'attendance_marked',
                    ['session_id' => $session->id, 'status' => 'absent'],
                );
            }

            $presentCount = $session->attendances()->where('status', 'present')->count();
            $absentCount = $absentees->count();

            $this->notifications->send(
                $teacherUser->id,
                'Session Ended',
                "Session ended: {$presentCount} present, {$absentCount} absent.",
                'session_ended',
                ['session_id' => $session->id, 'present' => $presentCount, 'absent' => $absentCount],
            );

            $this->auditLog->log($teacherUser->id, 'session_ended', "Ended session #{$session->id}", $session);

            return $session->load(['attendances.student.user', 'timetable.course']);
        });
    }
}
