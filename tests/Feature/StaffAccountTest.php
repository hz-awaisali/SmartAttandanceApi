<?php

namespace Tests\Feature;

use App\Models\AdminDepartment;
use App\Models\Batch;
use App\Models\ClassSession;
use App\Models\Department;
use App\Models\Program;
use App\Models\ProgramCourse;
use App\Models\Room;
use App\Models\Teacher;
use App\Models\TimeSlot;
use App\Models\Timetable;
use App\Models\User;
use App\Modules\ApplicationTracking\Models\ApplicationCategory;
use App\Modules\ApplicationTracking\Models\Office;
use App\Modules\ApplicationTracking\Models\WorkflowStep;
use App\Modules\ApplicationTracking\Models\WorkflowTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffAccountTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
    }

    public function test_admin_can_create_a_staff_account(): void
    {
        $transportDept = AdminDepartment::where('name', 'Transport Department')->firstOrFail();

        $response = $this->actingAs($this->admin)->postJson('/api/staff', [
            'name' => 'Nasir Iqbal',
            'email' => 'nasir.transport@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'admin_department_id' => $transportDept->id,
            'employee_no' => 'OFF-001',
            'designation' => 'Transport Officer',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.employee_no', 'OFF-001')
            ->assertJsonPath('data.designation', 'Transport Officer')
            ->assertJsonPath('data.admin_department_id', $transportDept->id);

        $this->assertDatabaseHas('users', ['email' => 'nasir.transport@example.com', 'role' => 'staff']);
        $this->assertDatabaseHas('staff', ['employee_no' => 'OFF-001', 'admin_department_id' => $transportDept->id]);
    }

    public function test_creating_a_staff_account_without_an_admin_department_fails_validation(): void
    {
        $this->actingAs($this->admin)->postJson('/api/staff', [
            'name' => 'Nasir Iqbal',
            'email' => 'nasir.transport@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'employee_no' => 'OFF-001',
            'designation' => 'Transport Officer',
        ])->assertStatus(422)->assertJsonValidationErrors('admin_department_id');
    }

    public function test_staff_can_hold_an_office_and_act_on_an_assigned_application(): void
    {
        $staffUser = User::factory()->create(['role' => 'staff', 'status' => 'active']);
        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);

        $office = Office::create(['name' => 'Transport Officer']);
        $office->users()->attach($staffUser->id);

        $template = WorkflowTemplate::create(['name' => 'Transport Pass Workflow', 'is_active' => true]);
        WorkflowStep::create([
            'workflow_template_id' => $template->id,
            'step_order' => 1,
            'name' => 'Transport Officer',
            'approver_type' => 'office',
            'approver_office_id' => $office->id,
            'on_reject_action' => 'terminate',
        ]);

        $category = ApplicationCategory::create([
            'name' => 'Transport Pass',
            'form_schema' => [['key' => 'pickup_point', 'label' => 'Pickup point', 'type' => 'text', 'required' => true]],
            'workflow_template_id' => $template->id,
        ]);

        $submit = $this->actingAs($student)->postJson('/api/applications', [
            'application_category_id' => $category->id,
            'form_data' => ['pickup_point' => 'Main Gate'],
        ]);
        $applicationId = $submit->json('data.id');

        $this->actingAs($staffUser)
            ->getJson('/api/applications?assigned=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $applicationId);

        $this->actingAs($staffUser)
            ->postJson("/api/applications/{$applicationId}/act", ['action' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
    }

    public function test_staff_dashboard_shows_identity_and_pending_queue(): void
    {
        $transportDept = AdminDepartment::where('name', 'Transport Department')->firstOrFail();

        $user = User::factory()->create(['role' => 'staff', 'status' => 'active']);
        \App\Models\Staff::create([
            'user_id' => $user->id,
            'admin_department_id' => $transportDept->id,
            'employee_no' => 'OFF-003',
            'designation' => 'Transport Officer',
        ]);

        $office = Office::create(['name' => 'Transport Officer', 'admin_department_id' => $transportDept->id]);
        $office->users()->attach($user->id);

        $template = WorkflowTemplate::create(['name' => 'Transport Pass Workflow 2', 'is_active' => true]);
        WorkflowStep::create([
            'workflow_template_id' => $template->id,
            'step_order' => 1,
            'name' => 'Transport Officer',
            'approver_type' => 'office',
            'approver_office_id' => $office->id,
            'on_reject_action' => 'terminate',
        ]);

        $category = ApplicationCategory::create([
            'name' => 'Transport Pass 2',
            'form_schema' => [['key' => 'pickup_point', 'label' => 'Pickup point', 'type' => 'text', 'required' => true]],
            'workflow_template_id' => $template->id,
        ]);

        $student = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $this->actingAs($student)->postJson('/api/applications', [
            'application_category_id' => $category->id,
            'form_data' => ['pickup_point' => 'Main Gate'],
        ])->assertCreated();

        $this->actingAs($user)
            ->getJson('/api/dashboard/staff')
            ->assertOk()
            ->assertJsonPath('data.staff.employee_no', 'OFF-003')
            ->assertJsonPath('data.staff.admin_department.name', 'Transport Department')
            ->assertJsonPath('data.offices.0.name', 'Transport Officer')
            ->assertJsonPath('data.pending_count', 1);
    }

    public function test_staff_cannot_access_any_attendance_module_route(): void
    {
        $staffUser = User::factory()->create(['role' => 'staff', 'status' => 'active']);

        $this->actingAs($staffUser)->getJson('/api/students/today-classes')->assertForbidden();
        $this->actingAs($staffUser)->postJson('/api/attendance/mark', [])->assertForbidden();
        $this->actingAs($staffUser)->getJson('/api/teacher/schedule')->assertForbidden();
        $this->actingAs($staffUser)->getJson('/api/dashboard/teacher')->assertForbidden();
        $this->actingAs($staffUser)->postJson('/api/departments', ['name' => 'x'])->assertForbidden();
    }

    public function test_staff_sees_no_sessions_in_the_shared_sessions_list(): void
    {
        $department = Department::create(['name' => 'Computer Science']);
        $program = Program::create(['department_id' => $department->id, 'name' => 'BSCS', 'code' => 'BSCS']);
        $course = ProgramCourse::create(['program_id' => $program->id, 'course_code' => 'CS101', 'course_title' => 'Intro', 'credit_hours' => 3]);
        $batch = Batch::create(['program_id' => $program->id, 'batch_name' => 'B1', 'start_year' => 2024, 'end_year' => 2028, 'semester' => 1, 'shift' => 'Morning']);

        $teacherUser = User::factory()->create(['role' => 'teacher']);
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'department_id' => $department->id, 'employee_no' => 'EMP-900', 'designation' => 'Lecturer']);

        $room = Room::create(['room_no' => 'R-900', 'beacon_major' => 900, 'beacon_uuid' => '6E400001-B5A3-F393-E0A9-E50E24DCCA9E']);
        $slot = TimeSlot::create(['start_time' => '08:00', 'end_time' => '09:00']);

        $timetable = Timetable::create([
            'batch_id' => $batch->id,
            'program_course_id' => $course->id,
            'teacher_id' => $teacher->id,
            'room_id' => $room->id,
            'day' => 'Monday',
            'slot_id' => $slot->id,
        ]);

        ClassSession::create([
            'timetable_id' => $timetable->id,
            'session_date' => now()->toDateString(),
            'start_time' => '08:00',
            'end_time' => '09:00',
            'status' => 'active',
        ]);

        $staffUser = User::factory()->create(['role' => 'staff', 'status' => 'active']);

        // A session genuinely exists in the DB - proving this is a real
        // filter, not just an empty database.
        $this->assertDatabaseCount('class_sessions', 1);

        $this->actingAs($staffUser)
            ->getJson('/api/sessions')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_staff_password_reset_uses_employee_no(): void
    {
        $staffUser = User::factory()->create(['email' => 'nasir@example.com', 'role' => 'staff', 'status' => 'active']);
        \App\Models\Staff::create([
            'user_id' => $staffUser->id,
            'admin_department_id' => AdminDepartment::where('name', 'Registrar Office')->firstOrFail()->id,
            'employee_no' => 'OFF-002',
            'designation' => 'Registrar',
        ]);

        $this->postJson('/api/auth/forgot-password', [
            'email' => 'nasir@example.com',
            'identity_no' => 'OFF-002',
        ])->assertOk()->assertJsonPath('message', 'Identity verified. You can now set a new password.');

        $this->postJson('/api/auth/forgot-password', [
            'email' => 'nasir@example.com',
            'identity_no' => 'WRONG-ID',
        ])->assertStatus(422);
    }
}
