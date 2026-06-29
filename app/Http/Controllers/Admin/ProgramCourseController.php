<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProgramCourse\StoreProgramCourseRequest;
use App\Http\Requests\ProgramCourse\UpdateProgramCourseRequest;
use App\Http\Resources\ProgramCourseResource;
use App\Models\ProgramCourse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProgramCourseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $courses = ProgramCourse::with('program')
            ->when($request->filled('program_id'), fn ($query) => $query->where('program_id', $request->program_id))
            ->latest()
            ->paginate(15);

        return $this->ok(ProgramCourseResource::collection($courses));
    }

    public function store(StoreProgramCourseRequest $request): JsonResponse
    {
        $course = ProgramCourse::create($request->validated());

        return $this->ok(ProgramCourseResource::make($course), 'Course created', 201);
    }

    public function show(ProgramCourse $programCourse): JsonResponse
    {
        return $this->ok(ProgramCourseResource::make($programCourse->load('program')));
    }

    public function update(UpdateProgramCourseRequest $request, ProgramCourse $programCourse): JsonResponse
    {
        $programCourse->update($request->validated());

        return $this->ok(ProgramCourseResource::make($programCourse), 'Course updated');
    }
}
