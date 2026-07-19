<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\StoreStaffRequest;
use App\Http\Requests\Staff\UpdateStaffRequest;
use App\Http\Resources\StaffResource;
use App\Models\Staff;
use App\Services\StaffService;
use Illuminate\Http\JsonResponse;

class StaffController extends Controller
{
    public function __construct(private StaffService $staff) {}

    public function index(): JsonResponse
    {
        $staff = Staff::with(['user', 'adminDepartment'])->latest()->paginate(15);

        return $this->ok(StaffResource::collection($staff));
    }

    public function store(StoreStaffRequest $request): JsonResponse
    {
        $staff = $this->staff->create($request->validated());

        return $this->ok(StaffResource::make($staff->load(['user', 'adminDepartment'])), 'Staff member created', 201);
    }

    public function show(Staff $staff): JsonResponse
    {
        return $this->ok(StaffResource::make($staff->load(['user', 'adminDepartment'])));
    }

    public function update(UpdateStaffRequest $request, Staff $staff): JsonResponse
    {
        $staff = $this->staff->update($staff, $request->validated());

        return $this->ok(StaffResource::make($staff->load(['user', 'adminDepartment'])), 'Staff member updated');
    }
}
