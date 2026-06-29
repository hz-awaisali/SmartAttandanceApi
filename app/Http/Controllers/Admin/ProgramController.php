<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Program\StoreProgramRequest;
use App\Http\Requests\Program\UpdateProgramRequest;
use App\Http\Resources\ProgramResource;
use App\Models\Program;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProgramController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $programs = Program::with('department')
            ->when($request->filled('department_id'), fn ($query) => $query->where('department_id', $request->department_id))
            ->latest()
            ->paginate(15);

        return $this->ok(ProgramResource::collection($programs));
    }

    public function store(StoreProgramRequest $request): JsonResponse
    {
        $program = Program::create($request->validated());

        return $this->ok(ProgramResource::make($program), 'Program created', 201);
    }

    public function show(Program $program): JsonResponse
    {
        return $this->ok(ProgramResource::make($program->load('department')));
    }

    public function update(UpdateProgramRequest $request, Program $program): JsonResponse
    {
        $program->update($request->validated());

        return $this->ok(ProgramResource::make($program), 'Program updated');
    }
}
