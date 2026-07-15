<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Batch;
use App\Models\ClassSession;
use App\Models\Department;
use App\Models\Program;
use App\Models\ProgramCourse;
use App\Models\Room;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TimeSlot;
use App\Models\Timetable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttendanceReportTest extends TestCase
{
    use RefreshDatabase;

    private const REPORT_DATE = '2026-07-15';

    /**
     * Builds one full department -> program -> course -> batch -> teacher ->
     * student -> room -> slot -> timetable -> session -> attendance chain,
     * so each call gives us an independently-scoped dataset.
     */
    private function buildDepartmentAttendance(string $suffix, string $status): array
    {
        $department = Department::create(['name' => "Department $suffix"]);

        $program = Program::create([
            'department_id' => $department->id,
            'name' => "Program $suffix",
            'code' => "PRG-$suffix",
        ]);

        $course = ProgramCourse::create([
            'program_id' => $program->id,
            'course_code' => "CS-$suffix",
            'course_title' => "Course $suffix",
            'credit_hours' => 3,
        ]);

        $batch = Batch::create([
            'program_id' => $program->id,
            'batch_name' => "Batch $suffix",
            'start_year' => 2024,
            'end_year' => 2028,
            'semester' => 1,
            'shift' => 'Morning',
        ]);

        $teacherUser = User::factory()->create(['role' => 'teacher']);
        $teacher = Teacher::create([
            'user_id' => $teacherUser->id,
            'department_id' => $department->id,
            'employee_no' => "EMP-$suffix",
            'designation' => 'Lecturer',
        ]);

        $studentUser = User::factory()->create(['role' => 'student']);
        $student = Student::create([
            'user_id' => $studentUser->id,
            'registration_no' => "REG-$suffix",
            'department_id' => $department->id,
            'batch_id' => $batch->id,
        ]);

        $room = Room::create([
            'room_no' => "R-$suffix",
            'wifi_name' => "wifi-$suffix",
            'wifi_mac' => strtoupper(substr(md5($suffix), 0, 12)),
        ]);

        $slot = TimeSlot::create([
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ]);

        $timetable = Timetable::create([
            'batch_id' => $batch->id,
            'program_course_id' => $course->id,
            'teacher_id' => $teacher->id,
            'room_id' => $room->id,
            'day' => 'Wednesday',
            'slot_id' => $slot->id,
        ]);

        $session = ClassSession::create([
            'timetable_id' => $timetable->id,
            'session_date' => self::REPORT_DATE,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'status' => 'ended',
        ]);

        $attendance = Attendance::create([
            'session_id' => $session->id,
            'student_id' => $student->id,
            'status' => $status,
            'marked_at' => now(),
        ]);

        return compact('department', 'teacher', 'teacherUser', 'student', 'attendance');
    }

    public function test_admin_sees_attendance_across_all_departments(): void
    {
        $deptA = $this->buildDepartmentAttendance('A', 'present');
        $deptB = $this->buildDepartmentAttendance('B', 'absent');

        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/attendance/report?date='.self::REPORT_DATE);

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($deptA['attendance']->id));
        $this->assertTrue($ids->contains($deptB['attendance']->id));
    }

    public function test_admin_report_includes_department_nesting(): void
    {
        $dept = $this->buildDepartmentAttendance('C', 'present');

        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/attendance/report?date='.self::REPORT_DATE);

        $response->assertOk();
        $response->assertJsonPath(
            'data.0.session.timetable.batch.program.department.name',
            $dept['department']->name
        );
    }

    public function test_teacher_only_sees_their_own_sessions(): void
    {
        $deptA = $this->buildDepartmentAttendance('D', 'present');
        $this->buildDepartmentAttendance('E', 'present');

        Sanctum::actingAs($deptA['teacherUser']);

        $response = $this->getJson('/api/attendance/report?date='.self::REPORT_DATE);

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');

        $this->assertCount(1, $ids);
        $this->assertTrue($ids->contains($deptA['attendance']->id));
    }

    public function test_hod_only_sees_their_own_department(): void
    {
        $deptA = $this->buildDepartmentAttendance('F', 'present');
        $this->buildDepartmentAttendance('G', 'present');

        $deptA['teacherUser']->update(['role' => 'hod']);
        $deptA['department']->update(['hod_teacher_id' => $deptA['teacher']->id]);

        Sanctum::actingAs($deptA['teacherUser']->fresh());

        $response = $this->getJson('/api/attendance/report?date='.self::REPORT_DATE);

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');

        $this->assertCount(1, $ids);
        $this->assertTrue($ids->contains($deptA['attendance']->id));
    }

    public function test_student_cannot_view_attendance_report(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        Sanctum::actingAs($student);

        $response = $this->getJson('/api/attendance/report?date='.self::REPORT_DATE);

        $response->assertStatus(403);
    }
}
