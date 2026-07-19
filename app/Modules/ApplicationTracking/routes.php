<?php

// Routes for the Digital Application Tracking module. Mounted by
// ApplicationTrackingServiceProvider::boot() under the same `api`
// middleware group and `/api` prefix as routes/api.php — this file is
// never referenced from routes/api.php itself, keeping the module fully
// self-contained.

use App\Modules\ApplicationTracking\Http\Controllers\Admin\ApplicationCategoryController;
use App\Modules\ApplicationTracking\Http\Controllers\Admin\OfficeController;
use App\Modules\ApplicationTracking\Http\Controllers\Admin\WorkflowTemplateController;
use App\Modules\ApplicationTracking\Http\Controllers\ApplicationController;
use App\Modules\ApplicationTracking\Http\Controllers\ApplicationDashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {

    // Any authenticated user: browse categories (admins see everything,
    // everyone else only active categories they're eligible for), submit
    // an application, and manage their own applications.
    Route::get('application-categories', [ApplicationController::class, 'categories']);
    Route::post('applications', [ApplicationController::class, 'store']);

    // Static "dashboard" path registered before the {application} wildcard
    // below, or the wildcard would swallow it — same pattern the core
    // routes/api.php uses for students/today-classes vs students/{student}.
    Route::middleware('role:admin')->group(function () {
        Route::get('applications/dashboard', [ApplicationDashboardController::class, 'stats']);
    });

    // A staff member's own home screen: identity + their pending-approvals
    // queue. Registered here (module owns this data) even though every
    // other dashboard/{role} route lives in the core routes/api.php.
    Route::middleware('role:staff')->group(function () {
        Route::get('dashboard/staff', [ApplicationController::class, 'dashboard']);
    });

    Route::get('applications', [ApplicationController::class, 'index']);
    Route::get('applications/{application}', [ApplicationController::class, 'show']);
    Route::post('applications/{application}/act', [ApplicationController::class, 'act']);
    Route::post('applications/{application}/resubmit', [ApplicationController::class, 'resubmit']);
    Route::post('applications/{application}/cancel', [ApplicationController::class, 'cancel']);

    // Admin-only: officials, workflow templates, and application-category
    // mutations (the category *listing* above is shared with every role).
    Route::middleware('role:admin')->group(function () {
        Route::get('offices', [OfficeController::class, 'index']);
        Route::post('offices', [OfficeController::class, 'store']);
        Route::get('offices/{office}', [OfficeController::class, 'show']);
        Route::put('offices/{office}', [OfficeController::class, 'update']);

        Route::get('workflow-templates', [WorkflowTemplateController::class, 'index']);
        Route::post('workflow-templates', [WorkflowTemplateController::class, 'store']);
        Route::get('workflow-templates/{workflowTemplate}', [WorkflowTemplateController::class, 'show']);
        Route::put('workflow-templates/{workflowTemplate}', [WorkflowTemplateController::class, 'update']);

        Route::post('application-categories', [ApplicationCategoryController::class, 'store']);
        Route::get('application-categories/{applicationCategory}', [ApplicationCategoryController::class, 'show']);
        Route::put('application-categories/{applicationCategory}', [ApplicationCategoryController::class, 'update']);
    });
});
