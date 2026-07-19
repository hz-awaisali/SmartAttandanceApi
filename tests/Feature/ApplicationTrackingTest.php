<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Department;
use App\Models\Program;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Modules\ApplicationTracking\Models\ApplicationCategory;
use App\Modules\ApplicationTracking\Models\Office;
use App\Modules\ApplicationTracking\Models\WorkflowStep;
use App\Modules\ApplicationTracking\Models\WorkflowTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationTrackingTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    private Teacher $hodTeacher;

    private User $hodUser;

    private User $studentUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->department = Department::create(['name' => 'Computer Science']);

        $this->hodUser = User::factory()->create(['email' => 'hod@example.com', 'role' => 'hod', 'status' => 'active']);
        $this->hodTeacher = Teacher::create([
            'user_id' => $this->hodUser->id,
            'department_id' => $this->department->id,
            'employee_no' => 'EMP-100',
            'designation' => 'Professor',
        ]);
        $this->department->update(['hod_teacher_id' => $this->hodTeacher->id]);

        $program = Program::create(['department_id' => $this->department->id, 'name' => 'BS Computer Science', 'code' => 'BSCS']);
        $batch = Batch::create(['program_id' => $program->id, 'batch_name' => 'BSCS-2024', 'start_year' => 2024, 'end_year' => 2028, 'semester' => 1, 'shift' => 'Morning']);

        $this->studentUser = User::factory()->create(['email' => 'student@example.com', 'role' => 'student', 'status' => 'active']);
        Student::create([
            'user_id' => $this->studentUser->id,
            'registration_no' => 'CS-24-001',
            'department_id' => $this->department->id,
            'batch_id' => $batch->id,
        ]);
    }

    /** @return array{0: Office, 1: User} */
    private function makeOffice(string $name, string $email): array
    {
        $office = Office::create(['name' => $name]);
        $user = User::factory()->create(['email' => $email, 'role' => 'teacher', 'status' => 'active']);
        $office->users()->attach($user->id);

        return [$office, $user];
    }

    private function twoStepTemplate(Office $secondStepOffice): WorkflowTemplate
    {
        $template = WorkflowTemplate::create(['name' => 'Two Step Workflow', 'is_active' => true]);

        $hodStep = WorkflowStep::create([
            'workflow_template_id' => $template->id,
            'step_order' => 1,
            'name' => 'HOD',
            'approver_type' => 'applicant_department_hod',
            'on_reject_action' => 'return_to_applicant',
        ]);

        $secondStep = WorkflowStep::create([
            'workflow_template_id' => $template->id,
            'step_order' => 2,
            'name' => 'Examination Officer',
            'approver_type' => 'office',
            'approver_office_id' => $secondStepOffice->id,
            'on_reject_action' => 'terminate',
        ]);

        $hodStep->update(['on_approve_next_step_id' => $secondStep->id]);

        return $template;
    }

    public function test_full_transcript_request_happy_path(): void
    {
        [$examOffice, $examUser] = $this->makeOffice('Examination Officer', 'exam.officer@example.com');
        $template = $this->twoStepTemplate($examOffice);

        $category = ApplicationCategory::create([
            'name' => 'Transcript Request',
            'form_schema' => [['key' => 'reason', 'label' => 'Reason', 'type' => 'textarea', 'required' => true]],
            'workflow_template_id' => $template->id,
            'applicant_roles' => ['student'],
        ]);

        $submit = $this->actingAs($this->studentUser)->postJson('/api/applications', [
            'application_category_id' => $category->id,
            'form_data' => ['reason' => 'Need transcript for a job application'],
        ]);

        $submit->assertCreated()->assertJsonPath('data.status', 'pending');
        $applicationId = $submit->json('data.id');

        $this->actingAs($this->hodUser)
            ->postJson("/api/applications/{$applicationId}/act", ['action' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.current_step.name', 'Examination Officer');

        $this->actingAs($examUser)
            ->postJson("/api/applications/{$applicationId}/act", ['action' => 'approve', 'remarks' => 'Transcript issued'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('applications', ['id' => $applicationId, 'status' => 'approved']);
        $this->assertDatabaseHas('notifications', ['user_id' => $this->studentUser->id, 'type' => 'application_approved']);
    }

    public function test_reject_returns_for_revision_then_resubmit_re_enters_same_step(): void
    {
        [$examOffice] = $this->makeOffice('Examination Officer', 'exam.officer@example.com');
        $template = $this->twoStepTemplate($examOffice);

        $category = ApplicationCategory::create([
            'name' => 'Revisable Application',
            'form_schema' => [['key' => 'reason', 'label' => 'Reason', 'type' => 'textarea', 'required' => true]],
            'workflow_template_id' => $template->id,
        ]);

        $submit = $this->actingAs($this->studentUser)->postJson('/api/applications', [
            'application_category_id' => $category->id,
            'form_data' => ['reason' => 'short'],
        ]);
        $applicationId = $submit->json('data.id');

        $this->actingAs($this->hodUser)
            ->postJson("/api/applications/{$applicationId}/act", ['action' => 'reject', 'remarks' => 'Please elaborate'])
            ->assertOk()
            ->assertJsonPath('data.status', 'returned_for_revision');

        $this->actingAs($this->studentUser)
            ->postJson("/api/applications/{$applicationId}/resubmit", [
                'form_data' => ['reason' => 'A much more detailed reason for the request'],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');

        // Re-enters the SAME step (the HOD) rather than restarting the chain.
        $this->actingAs($this->hodUser)
            ->postJson("/api/applications/{$applicationId}/act", ['action' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.current_step.name', 'Examination Officer');
    }

    public function test_direct_to_officer_application_has_no_hod_step(): void
    {
        [$transportOffice, $transportUser] = $this->makeOffice('Transport Officer', 'transport.officer@example.com');

        $template = WorkflowTemplate::create(['name' => 'Transport Pass Workflow', 'is_active' => true]);
        WorkflowStep::create([
            'workflow_template_id' => $template->id,
            'step_order' => 1,
            'name' => 'Transport Officer',
            'approver_type' => 'office',
            'approver_office_id' => $transportOffice->id,
            'on_reject_action' => 'terminate',
        ]);

        $category = ApplicationCategory::create([
            'name' => 'Transport Pass',
            'form_schema' => [['key' => 'pickup_point', 'label' => 'Pickup point', 'type' => 'text', 'required' => true]],
            'workflow_template_id' => $template->id,
        ]);

        $submit = $this->actingAs($this->studentUser)->postJson('/api/applications', [
            'application_category_id' => $category->id,
            'form_data' => ['pickup_point' => 'Main Gate'],
        ]);
        $applicationId = $submit->json('data.id');

        $this->actingAs($transportUser)
            ->postJson("/api/applications/{$applicationId}/act", ['action' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
    }

    public function test_user_without_the_required_office_cannot_act(): void
    {
        [$examOffice] = $this->makeOffice('Examination Officer', 'exam.officer@example.com');
        $template = $this->twoStepTemplate($examOffice);

        $category = ApplicationCategory::create([
            'name' => 'Transcript Request',
            'form_schema' => [['key' => 'reason', 'label' => 'Reason', 'type' => 'textarea', 'required' => true]],
            'workflow_template_id' => $template->id,
        ]);

        $submit = $this->actingAs($this->studentUser)->postJson('/api/applications', [
            'application_category_id' => $category->id,
            'form_data' => ['reason' => 'Need transcript'],
        ]);
        $applicationId = $submit->json('data.id');

        $outsider = User::factory()->create(['email' => 'outsider@example.com', 'role' => 'teacher', 'status' => 'active']);

        $this->actingAs($outsider)
            ->postJson("/api/applications/{$applicationId}/act", ['action' => 'approve'])
            ->assertForbidden();
    }

    public function test_workflow_step_can_target_one_specific_office_member(): void
    {
        [$examOffice, $targetedOfficer] = $this->makeOffice('Examination Officer', 'exam.officer@example.com');
        $otherOfficer = User::factory()->create(['email' => 'other.officer@example.com', 'role' => 'teacher', 'status' => 'active']);
        $examOffice->users()->attach($otherOfficer->id);

        $template = WorkflowTemplate::create(['name' => 'Targeted Workflow', 'is_active' => true]);
        WorkflowStep::create([
            'workflow_template_id' => $template->id,
            'step_order' => 1,
            'name' => 'Examination Officer',
            'approver_type' => 'office',
            'approver_office_id' => $examOffice->id,
            'approver_user_id' => $targetedOfficer->id,
            'on_reject_action' => 'terminate',
        ]);

        $category = ApplicationCategory::create([
            'name' => 'Transcript Request',
            'form_schema' => [['key' => 'reason', 'label' => 'Reason', 'type' => 'textarea', 'required' => true]],
            'workflow_template_id' => $template->id,
        ]);

        $submit = $this->actingAs($this->studentUser)->postJson('/api/applications', [
            'application_category_id' => $category->id,
            'form_data' => ['reason' => 'Need transcript'],
        ]);
        $applicationId = $submit->json('data.id');

        // The non-targeted office member neither sees it in their queue...
        $this->actingAs($otherOfficer)
            ->getJson('/api/applications?assigned=1')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // ...nor can act on it, even though they hold the same office.
        $this->actingAs($otherOfficer)
            ->postJson("/api/applications/{$applicationId}/act", ['action' => 'approve'])
            ->assertForbidden();

        // The targeted member sees it and can act on it.
        $this->actingAs($targetedOfficer)
            ->getJson('/api/applications?assigned=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $applicationId);

        $this->actingAs($targetedOfficer)
            ->postJson("/api/applications/{$applicationId}/act", ['action' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
    }

    public function test_targeted_approver_must_be_a_member_of_the_chosen_office(): void
    {
        [$examOffice] = $this->makeOffice('Examination Officer', 'exam.officer@example.com');
        $notAMember = User::factory()->create(['email' => 'not.a.member@example.com', 'role' => 'teacher', 'status' => 'active']);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson('/api/workflow-templates', [
                'name' => 'Invalid Targeted Workflow',
                'steps' => [
                    [
                        'name' => 'Examination Officer',
                        'approver_type' => 'office',
                        'approver_office_id' => $examOffice->id,
                        'approver_user_id' => $notAMember->id,
                    ],
                ],
            ])
            ->assertStatus(422);
    }

    public function test_duplicate_active_application_is_blocked(): void
    {
        [$examOffice] = $this->makeOffice('Examination Officer', 'exam.officer@example.com');
        $template = $this->twoStepTemplate($examOffice);

        $category = ApplicationCategory::create([
            'name' => 'Transcript Request',
            'form_schema' => [['key' => 'reason', 'label' => 'Reason', 'type' => 'textarea', 'required' => true]],
            'workflow_template_id' => $template->id,
        ]);

        $this->actingAs($this->studentUser)->postJson('/api/applications', [
            'application_category_id' => $category->id,
            'form_data' => ['reason' => 'First request'],
        ])->assertCreated();

        $this->actingAs($this->studentUser)->postJson('/api/applications', [
            'application_category_id' => $category->id,
            'form_data' => ['reason' => 'Second request'],
        ])->assertStatus(409);
    }

    public function test_applicant_can_cancel_a_pending_application(): void
    {
        [$examOffice] = $this->makeOffice('Examination Officer', 'exam.officer@example.com');
        $template = $this->twoStepTemplate($examOffice);

        $category = ApplicationCategory::create([
            'name' => 'Transcript Request',
            'form_schema' => [['key' => 'reason', 'label' => 'Reason', 'type' => 'textarea', 'required' => true]],
            'workflow_template_id' => $template->id,
        ]);

        $submit = $this->actingAs($this->studentUser)->postJson('/api/applications', [
            'application_category_id' => $category->id,
            'form_data' => ['reason' => 'Need transcript'],
        ]);
        $applicationId = $submit->json('data.id');

        $this->actingAs($this->studentUser)
            ->postJson("/api/applications/{$applicationId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_submission_is_blocked_when_the_reviewing_office_is_unstaffed(): void
    {
        $emptyOffice = Office::create(['name' => 'Unstaffed Office']);

        $template = WorkflowTemplate::create(['name' => 'Unstaffed Workflow', 'is_active' => true]);
        WorkflowStep::create([
            'workflow_template_id' => $template->id,
            'step_order' => 1,
            'name' => 'Nobody',
            'approver_type' => 'office',
            'approver_office_id' => $emptyOffice->id,
            'on_reject_action' => 'terminate',
        ]);

        $category = ApplicationCategory::create([
            'name' => 'Unstaffed Category',
            'form_schema' => [['key' => 'reason', 'label' => 'Reason', 'type' => 'textarea', 'required' => true]],
            'workflow_template_id' => $template->id,
        ]);

        $this->actingAs($this->studentUser)->postJson('/api/applications', [
            'application_category_id' => $category->id,
            'form_data' => ['reason' => 'Testing'],
        ])->assertStatus(422);
    }
}
