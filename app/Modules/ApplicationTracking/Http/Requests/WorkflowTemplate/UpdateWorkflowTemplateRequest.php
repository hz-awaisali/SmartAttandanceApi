<?php

namespace App\Modules\ApplicationTracking\Http\Requests\WorkflowTemplate;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWorkflowTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            // If provided, the entire step chain is replaced (guarded in the
            // controller against templates with in-flight applications).
            'steps' => ['sometimes', 'array', 'min:1'],
            'steps.*.name' => ['required_with:steps', 'string', 'max:255'],
            'steps.*.approver_type' => ['required_with:steps', 'string', 'in:office,applicant_department_hod'],
            'steps.*.approver_office_id' => ['required_if:steps.*.approver_type,office', 'nullable', 'integer', 'exists:offices,id'],
            'steps.*.approver_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'steps.*.on_reject_action' => ['sometimes', 'string', 'in:terminate,return_to_applicant'],
            'steps.*.allow_forward' => ['sometimes', 'boolean'],
        ];
    }
}
