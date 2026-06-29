<?php

use App\Http\Controllers\Admin\BatchController;
use App\Http\Controllers\Admin\DepartmentController;
use App\Http\Controllers\Admin\ProgramController;
use App\Http\Controllers\Admin\ProgramCourseController;
use App\Http\Controllers\Admin\RoomController;
use App\Http\Controllers\Admin\StudentController as AdminStudentController;
use App\Http\Controllers\Admin\TeacherController;
use App\Http\Controllers\Admin\TimeSlotController;
use App\Http\Controllers\Admin\TimetableController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\Student\ScheduleController;
use App\Http\Controllers\Teacher\ScheduleController as TeacherScheduleController;
use App\Http\Controllers\Teacher\StudentBlockController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('user', [AuthController::class, 'user']);
    });
});

Route::middleware('auth:sanctum')->group(function () {

    // Student-only static paths must be registered before the admin
    // GET /students/{student} wildcard below, or the wildcard would swallow them.
    Route::middleware('role:student')->group(function () {
        Route::get('students/today-classes', [ScheduleController::class, 'todayClasses']);
        Route::get('students/schedule', [ScheduleController::class, 'schedule']);
        Route::get('students/attendance-history', [ScheduleController::class, 'attendanceHistory']);
        Route::post('attendance/mark', [AttendanceController::class, 'mark']);
        Route::get('dashboard/student', [DashboardController::class, 'student']);
    });

    Route::middleware('role:teacher')->group(function () {
        Route::post('students/{student}/block', [StudentBlockController::class, 'block']);
        Route::post('students/{student}/unblock', [StudentBlockController::class, 'unblock']);
        Route::post('sessions/{timetable}/start', [SessionController::class, 'start']);
        Route::post('sessions/{session}/end', [SessionController::class, 'end']);
        Route::get('sessions/{session}/attendance', [SessionController::class, 'attendance']);
        Route::get('attendance/report', [AttendanceController::class, 'report']);
        Route::get('dashboard/teacher', [DashboardController::class, 'teacher']);
        Route::get('teacher/schedule', [TeacherScheduleController::class, 'schedule']);
    });

    Route::middleware('role:admin')->group(function () {
        Route::get('departments', [DepartmentController::class, 'index']);
        Route::post('departments', [DepartmentController::class, 'store']);
        Route::get('departments/{department}', [DepartmentController::class, 'show']);
        Route::put('departments/{department}', [DepartmentController::class, 'update']);

        Route::get('programs', [ProgramController::class, 'index']);
        Route::post('programs', [ProgramController::class, 'store']);
        Route::get('programs/{program}', [ProgramController::class, 'show']);
        Route::put('programs/{program}', [ProgramController::class, 'update']);

        Route::get('program-courses', [ProgramCourseController::class, 'index']);
        Route::post('program-courses', [ProgramCourseController::class, 'store']);
        Route::get('program-courses/{programCourse}', [ProgramCourseController::class, 'show']);
        Route::put('program-courses/{programCourse}', [ProgramCourseController::class, 'update']);

        Route::get('batches', [BatchController::class, 'index']);
        Route::post('batches', [BatchController::class, 'store']);
        Route::get('batches/{batch}', [BatchController::class, 'show']);
        Route::put('batches/{batch}', [BatchController::class, 'update']);

        Route::get('rooms', [RoomController::class, 'index']);
        Route::post('rooms', [RoomController::class, 'store']);
        Route::get('rooms/{room}', [RoomController::class, 'show']);
        Route::put('rooms/{room}', [RoomController::class, 'update']);

        Route::get('time-slots', [TimeSlotController::class, 'index']);
        Route::post('time-slots', [TimeSlotController::class, 'store']);

        Route::get('teachers', [TeacherController::class, 'index']);
        Route::post('teachers', [TeacherController::class, 'store']);
        Route::get('teachers/{teacher}', [TeacherController::class, 'show']);
        Route::put('teachers/{teacher}', [TeacherController::class, 'update']);

        Route::get('students', [AdminStudentController::class, 'index']);
        Route::post('students', [AdminStudentController::class, 'store']);
        Route::get('students/{student}', [AdminStudentController::class, 'show']);
        Route::put('students/{student}', [AdminStudentController::class, 'update']);

        Route::get('timetables', [TimetableController::class, 'index']);
        Route::post('timetables', [TimetableController::class, 'store']);
        Route::get('timetables/{timetable}', [TimetableController::class, 'show']);
        Route::put('timetables/{timetable}', [TimetableController::class, 'update']);

        Route::get('dashboard/admin', [DashboardController::class, 'admin']);
    });

    Route::middleware('role:hod')->group(function () {
        Route::get('dashboard/hod', [DashboardController::class, 'hod']);
    });

    // Shared across all authenticated roles
    Route::get('sessions', [SessionController::class, 'index']);
    Route::get('sessions/{session}', [SessionController::class, 'show']);
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::put('notifications/{notification}/read', [NotificationController::class, 'markRead']);
    Route::delete('notifications/{notification}', [NotificationController::class, 'destroy']);
});
