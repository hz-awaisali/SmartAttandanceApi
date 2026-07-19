<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminDepartment\StoreAdminDepartmentRequest;
use App\Http\Requests\AdminDepartment\UpdateAdminDepartmentRequest;
use App\Http\Resources\AdminDepartmentResource;
use App\Models\AdminDepartment;
use Illuminate\Http\JsonResponse;

class AdminDepartmentController extends Controller
{
    public function index(): JsonResponse
    {
        $adminDepartments = AdminDepartment::withCount('staff')->latest()->paginate(15);

        return $this->ok(AdminDepartmentResource::collection($adminDepartments));
    }

    public function store(StoreAdminDepartmentRequest $request): JsonResponse
    {
        $adminDepartment = AdminDepartment::create($request->validated());

        return $this->ok(AdminDepartmentResource::make($adminDepartment), 'Admin department created', 201);
    }

    public function show(AdminDepartment $adminDepartment): JsonResponse
    {
        $adminDepartment->loadCount('staff');

        return $this->ok(AdminDepartmentResource::make($adminDepartment));
    }

    public function update(UpdateAdminDepartmentRequest $request, AdminDepartment $adminDepartment): JsonResponse
    {
        $adminDepartment->update($request->validated());

        return $this->ok(AdminDepartmentResource::make($adminDepartment), 'Admin department updated');
    }
}
