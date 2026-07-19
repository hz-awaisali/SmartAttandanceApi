<?php

namespace App\Modules\ApplicationTracking\Services;

use App\Exceptions\BusinessException;
use App\Models\Department;
use App\Models\User;
use App\Modules\ApplicationTracking\Models\Application;
use App\Modules\ApplicationTracking\Models\ApplicationAction;
use App\Modules\ApplicationTracking\Models\ApplicationAttachment;
use App\Modules\ApplicationTracking\Models\ApplicationCategory;
use App\Modules\ApplicationTracking\Models\Office;
use App\Modules\ApplicationTracking\Models\WorkflowStep;
use App\Services\AuditLogService;
use App\Services\NotificationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ApplicationWorkflowService
{
    public function __construct(
        private DynamicFormValidator $formValidator,
        private NotificationService $notifications,
        private AuditLogService $auditLog,
    ) {}

    public function submit(User $applicant, ApplicationCategory $category, array $formData, array $files = []): Application
    {
        if (! $category->is_active) {
            throw new BusinessException('This application type is not currently accepting submissions.');
        }

        if ($category->applicant_roles && ! in_array($applicant->role, $category->applicant_roles, true)) {
            throw new BusinessException('You are not eligible to submit this type of application.', 403);
        }

        if (! $category->allow_multiple_active) {
            $hasActive = Application::where('application_category_id', $category->id)
                ->where('applicant_user_id', $applicant->id)
                ->whereIn('status', ['pending', 'returned_for_revision'])
                ->exists();

            if ($hasActive) {
                throw new BusinessException('You already have an active application of this type.', 409);
            }
        }

        $validatedFormData = $this->formValidator->validate($category->form_schema, $formData, $files);

        $firstStep = $category->workflowTemplate->steps()->orderBy('step_order')->first();

        if (! $firstStep) {
            throw new BusinessException('This application type has no configured workflow. Contact the administrator.', 422);
        }

        $approvers = $this->resolveApprovers($firstStep, $applicant);

        if ($approvers->isEmpty()) {
            throw new BusinessException("The reviewing office for this application (\"{$firstStep->name}\") is not currently staffed. Please contact the administrator.", 422);
        }

        return DB::transaction(function () use ($applicant, $category, $validatedFormData, $files, $firstStep, $approvers) {
            $application = Application::create([
                'application_category_id' => $category->id,
                'applicant_user_id' => $applicant->id,
                'form_data' => $validatedFormData,
                'workflow_template_id' => $category->workflow_template_id,
                'current_step_id' => $firstStep->id,
                'status' => 'pending',
                'submitted_at' => now(),
            ]);

            $action = ApplicationAction::create([
                'application_id' => $application->id,
                'workflow_step_id' => $firstStep->id,
                'actor_user_id' => $applicant->id,
                'action' => 'submitted',
                'form_data_snapshot' => $validatedFormData,
            ]);

            $this->storeAttachments($application, $action, $category->form_schema, $files, $applicant);

            $this->notifyUsers(
                $approvers,
                'New Application Submitted',
                "A new \"{$category->name}\" application is awaiting your review.",
                'application_submitted',
                ['application_id' => $application->id],
            );

            $this->auditLog->log($applicant->id, 'application_submitted', "Submitted \"{$category->name}\" application #{$application->id}", $application);

            return $application->load(['category', 'currentStep', 'actions']);
        });
    }

    public function act(User $official, Application $application, string $action, ?string $remarks = null, ?int $forwardToOfficeId = null): Application
    {
        if (! in_array($action, ['approve', 'reject', 'forward', 'comment'], true)) {
            throw new BusinessException('Unknown action.');
        }

        if (! in_array($application->status, ['pending', 'returned_for_revision'], true)) {
            throw new BusinessException('This application has already been resolved.', 409);
        }

        $step = $application->currentStep;

        if (! $step) {
            throw new BusinessException('This application has no active step.', 422);
        }

        if ($action === 'forward') {
            if (! $step->allow_forward) {
                throw new BusinessException('This step does not allow forwarding to another office.', 422);
            }

            if (! $forwardToOfficeId) {
                throw new BusinessException('A destination office is required to forward this application.');
            }
        }

        // The request sends a present-tense command ("approve"); the stored
        // timeline/notification event is the resulting past-tense fact
        // ("approved") — application_actions.action is a completed-event log.
        $eventName = match ($action) {
            'approve' => 'approved',
            'reject' => 'rejected',
            'forward' => 'forwarded',
            'comment' => 'commented',
        };

        return DB::transaction(function () use ($official, $application, $action, $eventName, $remarks, $forwardToOfficeId, $step) {
            [$notifyTargets, $notifyTitle, $notifyMessage] = match ($action) {
                'approve' => $this->applyApprove($application, $step),
                'reject' => $this->applyReject($application, $step, $remarks),
                'forward' => $this->applyForward($application, $forwardToOfficeId),
                'comment' => $this->applyComment($application),
            };

            ApplicationAction::create([
                'application_id' => $application->id,
                'workflow_step_id' => $step->id,
                'actor_user_id' => $official->id,
                'action' => $eventName,
                'remarks' => $remarks,
                'forwarded_to_office_id' => $action === 'forward' ? $forwardToOfficeId : null,
            ]);

            $this->notifyUsers($notifyTargets, $notifyTitle, $notifyMessage, "application_{$eventName}", ['application_id' => $application->id]);

            $this->auditLog->log($official->id, "application_{$action}", ucfirst($action)." on application #{$application->id}", $application);

            return $application->fresh(['category', 'currentStep', 'actions']);
        });
    }

    public function resubmit(User $applicant, Application $application, array $formData, array $files = []): Application
    {
        if ($application->applicant_user_id !== $applicant->id) {
            throw new BusinessException('You can only resubmit your own applications.', 403);
        }

        if ($application->status !== 'returned_for_revision') {
            throw new BusinessException('This application is not awaiting revision.', 409);
        }

        $category = $application->category;
        $validatedFormData = $this->formValidator->validate($category->form_schema, $formData, $files);

        return DB::transaction(function () use ($applicant, $application, $validatedFormData, $category) {
            $application->update([
                'form_data' => $validatedFormData,
                'status' => 'pending',
            ]);

            $action = ApplicationAction::create([
                'application_id' => $application->id,
                'workflow_step_id' => $application->current_step_id,
                'actor_user_id' => $applicant->id,
                'action' => 'resubmitted',
                'form_data_snapshot' => $validatedFormData,
            ]);

            $this->storeAttachments($application, $action, $category->form_schema, $files ?? [], $applicant);

            $step = $application->currentStep;

            if ($step) {
                $this->notifyUsers(
                    $this->resolveApprovers($step, $applicant),
                    'Application Resubmitted',
                    "The \"{$category->name}\" application you requested changes on has been resubmitted.",
                    'application_resubmitted',
                    ['application_id' => $application->id],
                );
            }

            $this->auditLog->log($applicant->id, 'application_resubmitted', "Resubmitted application #{$application->id}", $application);

            return $application->fresh(['category', 'currentStep', 'actions']);
        });
    }

    public function cancel(User $applicant, Application $application): Application
    {
        if ($application->applicant_user_id !== $applicant->id) {
            throw new BusinessException('You can only cancel your own applications.', 403);
        }

        if (! in_array($application->status, ['pending', 'returned_for_revision'], true)) {
            throw new BusinessException('This application can no longer be cancelled.', 409);
        }

        return DB::transaction(function () use ($applicant, $application) {
            $step = $application->currentStep;

            $application->update([
                'status' => 'cancelled',
                'current_step_id' => null,
                'resolved_at' => now(),
            ]);

            ApplicationAction::create([
                'application_id' => $application->id,
                'workflow_step_id' => $step?->id,
                'actor_user_id' => $applicant->id,
                'action' => 'cancelled',
            ]);

            if ($step) {
                $this->notifyUsers(
                    $this->resolveApprovers($step, $applicant),
                    'Application Cancelled',
                    "An application (#{$application->id}) was cancelled by the applicant.",
                    'application_cancelled',
                    ['application_id' => $application->id],
                );
            }

            $this->auditLog->log($applicant->id, 'application_cancelled', "Cancelled application #{$application->id}", $application);

            return $application->fresh(['category', 'currentStep', 'actions']);
        });
    }

    /** @return array{0: Collection, 1: string, 2: string} */
    private function applyApprove(Application $application, WorkflowStep $step): array
    {
        if ($step->on_approve_next_step_id) {
            $nextStep = WorkflowStep::find($step->on_approve_next_step_id);
            $approvers = $this->resolveApprovers($nextStep, $application->applicant);

            if ($approvers->isEmpty()) {
                throw new BusinessException("The next office (\"{$nextStep->name}\") is not currently staffed. This approval cannot be recorded until the administrator assigns someone.", 422);
            }

            $application->update(['current_step_id' => $nextStep->id]);

            return [$approvers, 'Application Awaiting Your Review', "A \"{$application->category->name}\" application has moved to your desk for review."];
        }

        $application->update(['status' => 'approved', 'current_step_id' => null, 'resolved_at' => now()]);

        return [collect([$application->applicant]), 'Application Approved', "Your \"{$application->category->name}\" application has been approved."];
    }

    /** @return array{0: Collection, 1: string, 2: string} */
    private function applyReject(Application $application, WorkflowStep $step, ?string $remarks): array
    {
        if ($step->on_reject_action === 'return_to_applicant') {
            $application->update(['status' => 'returned_for_revision']);

            $suffix = $remarks ? ": {$remarks}" : '.';

            return [collect([$application->applicant]), 'Application Needs Revision', "Your application was returned for changes{$suffix} Please review and resubmit."];
        }

        $application->update(['status' => 'rejected', 'current_step_id' => null, 'resolved_at' => now()]);

        return [collect([$application->applicant]), 'Application Rejected', "Your \"{$application->category->name}\" application was rejected."];
    }

    /** @return array{0: Collection, 1: string, 2: string} */
    private function applyForward(Application $application, int $officeId): array
    {
        $office = Office::find($officeId);

        if (! $office) {
            throw new BusinessException('The destination office does not exist.');
        }

        $users = $office->users;

        if ($users->isEmpty()) {
            throw new BusinessException("\"{$office->name}\" is not currently staffed.", 422);
        }

        return [$users, 'Application Forwarded to You', "A \"{$application->category->name}\" application was forwarded to your office for input."];
    }

    /** @return array{0: Collection, 1: string, 2: string} */
    private function applyComment(Application $application): array
    {
        return [collect([$application->applicant]), 'New Comment on Your Application', 'An official added a comment to your application.'];
    }

    private function resolveApprovers(WorkflowStep $step, User $applicant): Collection
    {
        if ($step->approver_type === 'office') {
            if ($step->approver_user_id) {
                $approver = User::find($step->approver_user_id);

                return $approver ? collect([$approver]) : collect();
            }

            return $step->office?->users ?? collect();
        }

        if ($step->approver_type === 'applicant_department_hod') {
            $departmentId = $applicant->student?->department_id ?? $applicant->teacher?->department_id;

            if (! $departmentId) {
                return collect();
            }

            $hodUser = Department::find($departmentId)?->hod?->user;

            return $hodUser ? collect([$hodUser]) : collect();
        }

        return collect();
    }

    private function storeAttachments(Application $application, ApplicationAction $action, array $schema, array $files, User $uploader): void
    {
        foreach ($schema as $field) {
            if (($field['type'] ?? null) !== 'file') {
                continue;
            }

            $file = $files[$field['key']] ?? null;

            if (! $file) {
                continue;
            }

            $path = $file->store('application_attachments', 'public');

            ApplicationAttachment::create([
                'application_id' => $application->id,
                'application_action_id' => $action->id,
                'field_key' => $field['key'],
                'uploaded_by_user_id' => $uploader->id,
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
            ]);
        }
    }

    private function notifyUsers(Collection $users, string $title, string $message, string $type, array $relatedData): void
    {
        foreach ($users as $user) {
            $this->notifications->send($user->id, $title, $message, $type, $relatedData);
        }
    }
}
