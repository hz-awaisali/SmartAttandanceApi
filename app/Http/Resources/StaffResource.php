<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'admin_department_id' => $this->admin_department_id,
            'employee_no' => $this->employee_no,
            'designation' => $this->designation,
            'phone' => $this->phone,
            'name' => $this->whenLoaded('user', fn () => $this->user->name),
            'email' => $this->whenLoaded('user', fn () => $this->user->email),
            'status' => $this->whenLoaded('user', fn () => $this->user->status),
            'admin_department' => AdminDepartmentResource::make($this->whenLoaded('adminDepartment')),
            'created_at' => $this->created_at,
        ];
    }
}
