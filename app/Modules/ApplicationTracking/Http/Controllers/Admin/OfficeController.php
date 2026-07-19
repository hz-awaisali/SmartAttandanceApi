<?php

namespace App\Modules\ApplicationTracking\Http\Controllers\Admin;

use App\Modules\ApplicationTracking\Http\Controllers\Controller;
use App\Modules\ApplicationTracking\Http\Requests\Office\StoreOfficeRequest;
use App\Modules\ApplicationTracking\Http\Requests\Office\UpdateOfficeRequest;
use App\Modules\ApplicationTracking\Http\Resources\OfficeResource;
use App\Modules\ApplicationTracking\Models\Office;
use Illuminate\Http\JsonResponse;

class OfficeController extends Controller
{
    public function index(): JsonResponse
    {
        $offices = Office::with(['adminDepartment', 'users'])->latest()->paginate(15);

        return $this->paginated(OfficeResource::collection($offices), $offices);
    }

    public function store(StoreOfficeRequest $request): JsonResponse
    {
        $office = Office::create($request->safe()->only(['name', 'admin_department_id']));

        if ($request->filled('user_ids')) {
            $office->users()->sync($request->input('user_ids'));
        }

        return $this->ok(OfficeResource::make($office->load(['adminDepartment', 'users'])), 'Office created', 201);
    }

    public function show(Office $office): JsonResponse
    {
        return $this->ok(OfficeResource::make($office->load(['adminDepartment', 'users'])));
    }

    public function update(UpdateOfficeRequest $request, Office $office): JsonResponse
    {
        $office->update($request->safe()->only(['name', 'admin_department_id']));

        if ($request->has('user_ids')) {
            $office->users()->sync($request->input('user_ids'));
        }

        return $this->ok(OfficeResource::make($office->load(['adminDepartment', 'users'])), 'Office updated');
    }
}
