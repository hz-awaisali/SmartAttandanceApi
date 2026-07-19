<?php

namespace App\Modules\ApplicationTracking\Http\Controllers\Admin;

use App\Exceptions\BusinessException;
use App\Modules\ApplicationTracking\Http\Controllers\Controller;
use App\Modules\ApplicationTracking\Http\Requests\WorkflowTemplate\StoreWorkflowTemplateRequest;
use App\Modules\ApplicationTracking\Http\Requests\WorkflowTemplate\UpdateWorkflowTemplateRequest;
use App\Modules\ApplicationTracking\Http\Resources\WorkflowTemplateResource;
use App\Modules\ApplicationTracking\Models\Application;
use App\Modules\ApplicationTracking\Models\Office;
use App\Modules\ApplicationTracking\Models\WorkflowStep;
use App\Modules\ApplicationTracking\Models\WorkflowTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class WorkflowTemplateController extends Controller
{
    public function index(): JsonResponse
    {
        $templates = WorkflowTemplate::with(['steps.office', 'steps.approverUser'])->latest()->paginate(15);

        return $this->paginated(WorkflowTemplateResource::collection($templates), $templates);
    }

    public function store(StoreWorkflowTemplateRequest $request): JsonResponse
    {
        $template = DB::transaction(function () use ($request) {
            $template = WorkflowTemplate::create([
                'name' => $request->validated('name'),
                'is_active' => $request->boolean('is_active', true),
            ]);

            $this->replaceSteps($template, $request->validated('steps'));

            return $template;
        });

        return $this->ok(WorkflowTemplateResource::make($template->load(['steps.office', 'steps.approverUser'])), 'Workflow template created', 201);
    }

    public function show(WorkflowTemplate $workflowTemplate): JsonResponse
    {
        return $this->ok(WorkflowTemplateResource::make($workflowTemplate->load(['steps.office', 'steps.approverUser'])));
    }

    public function update(UpdateWorkflowTemplateRequest $request, WorkflowTemplate $workflowTemplate): JsonResponse
    {
        if ($request->has('steps')) {
            $this->assertNoInFlightApplications($workflowTemplate);
        }

        DB::transaction(function () use ($request, $workflowTemplate) {
            $workflowTemplate->update($request->safe()->only(['name', 'is_active']));

            if ($request->has('steps')) {
                $this->replaceSteps($workflowTemplate, $request->input('steps'));
            }
        });

        return $this->ok(WorkflowTemplateResource::make($workflowTemplate->load(['steps.office', 'steps.approverUser'])), 'Workflow template updated');
    }

    /**
     * Steps are submitted as a plain ordered array; each step's "next step
     * on approval" is wired to the following array entry automatically
     * (linear chain, per design) so callers never reference not-yet-created
     * step IDs.
     */
    private function replaceSteps(WorkflowTemplate $template, array $steps): void
    {
        $template->steps()->delete();

        $createdIds = [];

        foreach ($steps as $index => $stepData) {
            $approverOfficeId = $stepData['approver_type'] === 'office' ? ($stepData['approver_office_id'] ?? null) : null;
            $approverUserId = $stepData['approver_type'] === 'office' ? ($stepData['approver_user_id'] ?? null) : null;

            if ($approverUserId && $approverOfficeId) {
                $isMember = Office::whereKey($approverOfficeId)->whereHas('users', fn ($q) => $q->where('users.id', $approverUserId))->exists();

                if (! $isMember) {
                    throw new BusinessException("The chosen approver for step \"{$stepData['name']}\" is not a member of the selected office.");
                }
            }

            $step = WorkflowStep::create([
                'workflow_template_id' => $template->id,
                'step_order' => $index + 1,
                'name' => $stepData['name'],
                'approver_type' => $stepData['approver_type'],
                'approver_office_id' => $approverOfficeId,
                'approver_user_id' => $approverUserId,
                'on_reject_action' => $stepData['on_reject_action'] ?? 'terminate',
                'allow_forward' => $stepData['allow_forward'] ?? false,
            ]);

            $createdIds[] = $step->id;
        }

        for ($i = 0; $i < count($createdIds) - 1; $i++) {
            WorkflowStep::whereKey($createdIds[$i])->update(['on_approve_next_step_id' => $createdIds[$i + 1]]);
        }
    }

    private function assertNoInFlightApplications(WorkflowTemplate $workflowTemplate): void
    {
        $hasInFlight = Application::where('workflow_template_id', $workflowTemplate->id)
            ->whereIn('status', ['pending', 'returned_for_revision'])
            ->exists();

        if ($hasInFlight) {
            throw new BusinessException('This workflow template has in-flight applications and cannot have its steps changed. Wait until they resolve, or create a new template.', 409);
        }
    }
}
