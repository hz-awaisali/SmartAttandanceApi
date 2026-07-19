<?php

namespace App\Modules\ApplicationTracking\Http\Requests\WorkflowTemplate;

use Illuminate\Foundation\Http\FormRequest;

class StoreWorkflowTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Steps are submitted as a plain ordered array — the engine wires each
     * step's "next step on approval" to the following array entry
     * automatically (linear chain, per design), so there's no need for the
     * caller to reference not-yet-created step IDs.
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'steps' => ['required', 'array', 'min:1'],
            'steps.*.name' => ['required', 'string', 'max:255'],
            'steps.*.approver_type' => ['required', 'string', 'in:office,applicant_department_hod'],
            'steps.*.approver_office_id' => ['required_if:steps.*.approver_type,office', 'nullable', 'integer', 'exists:offices,id'],
            // Optional: narrows an office-type step to one specific member
            // of that office instead of broadcasting to every holder.
            // Membership is validated in the controller (needs the office
            // context, not expressible as a plain rule here).
            'steps.*.approver_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'steps.*.on_reject_action' => ['sometimes', 'string', 'in:terminate,return_to_applicant'],
            'steps.*.allow_forward' => ['sometimes', 'boolean'],
        ];
    }
}
