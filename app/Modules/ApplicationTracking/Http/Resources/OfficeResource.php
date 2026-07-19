<?php

namespace App\Modules\ApplicationTracking\Http\Resources;

use App\Http\Resources\AdminDepartmentResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OfficeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'admin_department_id' => $this->admin_department_id,
            'admin_department' => AdminDepartmentResource::make($this->whenLoaded('adminDepartment')),
            'users' => $this->when($this->relationLoaded('users'), fn () => $this->users->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ])),
            'created_at' => $this->created_at,
        ];
    }
}
