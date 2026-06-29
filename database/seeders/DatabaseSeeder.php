<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\ClassSession;
use App\Models\Department;
use App\Models\Notification;
use App\Models\Program;
use App\Models\ProgramCourse;
use App\Models\Room;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TimeSlot;
use App\Models\Timetable;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::create([
            'name' => 'System Administrator',
            'email' => 'admin@university.edu',
            'password' => 'password',
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);

        $rooms = collect([
            ['room_no' => 'Room-101', 'wifi_name' => 'Lab-WiFi-101', 'wifi_mac' => 'AA:BB:CC:00:01:01'],
            ['room_no' => 'Room-102', 'wifi_name' => 'Lab-WiFi-102', 'wifi_mac' => 'AA:BB:CC:00:01:02'],
            ['room_no' => 'Room-103', 'wifi_name' => 'Lab-WiFi-103', 'wifi_mac' => 'AA:BB:CC:00:01:03'],
        ])->map(fn ($data) => Room::create($data));

        $timeSlots = collect([
            ['start_time' => '08:00', 'end_time' => '09:00'],
            ['start_time' => '09:00', 'end_time' => '10:00'],
            ['start_time' => '10:00', 'end_time' => '11:00'],
            ['start_time' => '11:00', 'end_time' => '12:00'],
        ])->map(fn ($data) => TimeSlot::create($data));

        $cs = Department::create(['name' => 'Computer Science']);
        $ee = Department::create(['name' => 'Electrical Engineering']);

        $csHod = $this->makeTeacher('Dr. Ayesha Khan', 'ayesha.khan@university.edu', $cs, 'EMP-001', 'Associate Professor', role: 'hod');
        $csTeacher1 = $this->makeTeacher('Bilal Ahmed', 'bilal.ahmed@university.edu', $cs, 'EMP-002', 'Lecturer');
        $csTeacher2 = $this->makeTeacher('Sara Malik', 'sara.malik@university.edu', $cs, 'EMP-003', 'Assistant Professor');

        $eeHod = $this->makeTeacher('Dr. Imran Sheikh', 'imran.sheikh@university.edu', $ee, 'EMP-004', 'Professor', role: 'hod');
        $eeTeacher1 = $this->makeTeacher('Hira Tariq', 'hira.tariq@university.edu', $ee, 'EMP-005', 'Lecturer');

        $cs->update(['hod_teacher_id' => $csHod->id]);
        $ee->update(['hod_teacher_id' => $eeHod->id]);

        $bscs = Program::create(['department_id' => $cs->id, 'name' => 'BS Computer Science', 'code' => 'BSCS']);
        $bsee = Program::create(['department_id' => $ee->id, 'name' => 'BS Electrical Engineering', 'code' => 'BSEE']);

        $csCourses = collect([
            ['course_code' => 'CS101', 'course_title' => 'Introduction to Programming', 'credit_hours' => 3],
            ['course_code' => 'CS102', 'course_title' => 'Data Structures', 'credit_hours' => 3],
            ['course_code' => 'CS201', 'course_title' => 'Database Systems', 'credit_hours' => 3],
            ['course_code' => 'CS202', 'course_title' => 'Computer Networks', 'credit_hours' => 3],
        ])->map(fn ($data) => ProgramCourse::create([...$data, 'program_id' => $bscs->id]));

        $eeCourses = collect([
            ['course_code' => 'EE101', 'course_title' => 'Circuit Analysis', 'credit_hours' => 3],
            ['course_code' => 'EE102', 'course_title' => 'Digital Logic Design', 'credit_hours' => 3],
        ])->map(fn ($data) => ProgramCourse::create([...$data, 'program_id' => $bsee->id]));

        $bscsBatch = Batch::create(['program_id' => $bscs->id, 'batch_name' => 'BSCS-2023-M', 'start_year' => 2023, 'end_year' => 2027, 'semester' => 3, 'shift' => 'Morning']);
        $bscsEveningBatch = Batch::create(['program_id' => $bscs->id, 'batch_name' => 'BSCS-2024-E', 'start_year' => 2024, 'end_year' => 2028, 'semester' => 1, 'shift' => 'Evening']);
        $bseeBatch = Batch::create(['program_id' => $bsee->id, 'batch_name' => 'BSEE-2023-M', 'start_year' => 2023, 'end_year' => 2027, 'semester' => 3, 'shift' => 'Morning']);

        $bscsStudents = collect(range(1, 8))->map(fn ($i) => $this->makeStudent(
            "BSCS Student {$i}", "bscs.student{$i}@university.edu", "CS-23-".str_pad($i, 3, '0', STR_PAD_LEFT), $cs, $bscsBatch,
        ));
        $bscsEveningStudents = collect(range(1, 5))->map(fn ($i) => $this->makeStudent(
            "BSCS Evening Student {$i}", "bscs.evening{$i}@university.edu", "CS-24-".str_pad($i, 3, '0', STR_PAD_LEFT), $cs, $bscsEveningBatch,
        ));
        $bseeStudents = collect(range(1, 6))->map(fn ($i) => $this->makeStudent(
            "BSEE Student {$i}", "bsee.student{$i}@university.edu", "EE-23-".str_pad($i, 3, '0', STR_PAD_LEFT), $ee, $bseeBatch,
        ));

        $cs101Timetable = Timetable::create([
            'batch_id' => $bscsBatch->id,
            'program_course_id' => $csCourses[0]->id,
            'teacher_id' => $csTeacher1->id,
            'room_id' => $rooms[0]->id,
            'day' => 'Monday',
            'slot_id' => $timeSlots[0]->id,
        ]);
        Timetable::create([
            'batch_id' => $bscsBatch->id,
            'program_course_id' => $csCourses[1]->id,
            'teacher_id' => $csTeacher2->id,
            'room_id' => $rooms[0]->id,
            'day' => 'Tuesday',
            'slot_id' => $timeSlots[1]->id,
        ]);
        Timetable::create([
            'batch_id' => $bscsBatch->id,
            'program_course_id' => $csCourses[2]->id,
            'teacher_id' => $csHod->id,
            'room_id' => $rooms[1]->id,
            'day' => 'Wednesday',
            'slot_id' => $timeSlots[0]->id,
        ]);
        Timetable::create([
            'batch_id' => $bscsBatch->id,
            'program_course_id' => $csCourses[3]->id,
            'teacher_id' => $csTeacher1->id,
            'room_id' => $rooms[1]->id,
            'day' => 'Thursday',
            'slot_id' => $timeSlots[1]->id,
        ]);
        Timetable::create([
            'batch_id' => $bseeBatch->id,
            'program_course_id' => $eeCourses[0]->id,
            'teacher_id' => $eeTeacher1->id,
            'room_id' => $rooms[2]->id,
            'day' => 'Monday',
            'slot_id' => $timeSlots[0]->id,
        ]);
        Timetable::create([
            'batch_id' => $bseeBatch->id,
            'program_course_id' => $eeCourses[1]->id,
            'teacher_id' => $eeHod->id,
            'room_id' => $rooms[2]->id,
            'day' => 'Tuesday',
            'slot_id' => $timeSlots[1]->id,
        ]);

        $this->seedDemoSession($cs101Timetable, $bscsStudents, $admin);
    }

    private function makeTeacher(string $name, string $email, Department $department, string $employeeNo, string $designation, string $role = 'teacher'): Teacher
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => 'password', 'role' => $role, 'email_verified_at' => now()]);

        return Teacher::create([
            'user_id' => $user->id,
            'department_id' => $department->id,
            'employee_no' => $employeeNo,
            'designation' => $designation,
            'phone' => '0300-'.fake()->numerify('#######'),
        ]);
    }

    private function makeStudent(string $name, string $email, string $registrationNo, Department $department, Batch $batch): Student
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => 'password', 'role' => 'student', 'email_verified_at' => now()]);

        return Student::create([
            'user_id' => $user->id,
            'registration_no' => $registrationNo,
            'department_id' => $department->id,
            'batch_id' => $batch->id,
            'phone' => '0300-'.fake()->numerify('#######'),
        ]);
    }

    private function seedDemoSession(Timetable $timetable, $students, User $admin): void
    {
        $session = ClassSession::create([
            'timetable_id' => $timetable->id,
            'session_date' => now()->subDay()->toDateString(),
            'start_time' => $timetable->timeSlot->start_time,
            'end_time' => $timetable->timeSlot->end_time,
            'status' => 'ended',
        ]);

        $room = $timetable->room;

        foreach ($students as $index => $student) {
            $present = $index < 5;

            Attendance::create([
                'session_id' => $session->id,
                'student_id' => $student->id,
                'wifi_mac_detected' => $present ? $room->wifi_mac : null,
                'status' => $present ? 'present' : 'absent',
                'marked_at' => $present ? $session->session_date->copy()->setTimeFromTimeString($session->start_time) : null,
            ]);

            Notification::create([
                'user_id' => $student->user_id,
                'title' => $present ? 'Attendance Marked' : 'Attendance Marked',
                'message' => $present
                    ? 'You have been marked present for this session.'
                    : 'You were marked absent for this session.',
                'type' => 'attendance_marked',
                'related_data' => ['session_id' => $session->id, 'status' => $present ? 'present' : 'absent'],
            ]);
        }

        AuditLog::create([
            'user_id' => $admin->id,
            'action' => 'database_seeded',
            'description' => 'Demo dataset seeded for development and testing.',
        ]);
    }
}
