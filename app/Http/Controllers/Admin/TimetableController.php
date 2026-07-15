<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Timetable\StoreTimetableRequest;
use App\Http\Requests\Timetable\UpdateTimetableRequest;
use App\Http\Resources\TimetableResource;
use App\Models\Timetable;
use App\Services\TimetableService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TimetableController extends Controller
{
    public function __construct(private TimetableService $timetables) {}

    public function index(Request $request): JsonResponse
    {
        $query = Timetable::with(['batch', 'course', 'teacher.user', 'room', 'timeSlot']);

        if ($request->user()->isHod()) {
            $query->whereHas('batch.program', function ($q) use ($request) {
                $q->where('department_id', $request->user()->teacher?->department_id);
            });
        }

        $timetables = $query->latest()->paginate(15);

        return $this->ok(TimetableResource::collection($timetables));
    }

    public function store(StoreTimetableRequest $request): JsonResponse
    {
        $data = $request->validated();
        $this->timetables->ensureNoConflict($data);

        $timetable = Timetable::create($data);

        return $this->ok(TimetableResource::make($timetable->load(['batch', 'course', 'teacher.user', 'room', 'timeSlot'])), 'Timetable created', 201);
    }

    public function show(Timetable $timetable): JsonResponse
    {
        return $this->ok(TimetableResource::make($timetable->load(['batch', 'course', 'teacher.user', 'room', 'timeSlot'])));
    }

    public function update(UpdateTimetableRequest $request, Timetable $timetable): JsonResponse
    {
        $data = $request->validated();

        $this->timetables->ensureNoConflict([
            'day' => $data['day'] ?? $timetable->day,
            'slot_id' => $data['slot_id'] ?? $timetable->slot_id,
            'teacher_id' => $data['teacher_id'] ?? $timetable->teacher_id,
            'room_id' => $data['room_id'] ?? $timetable->room_id,
            'batch_id' => $data['batch_id'] ?? $timetable->batch_id,
        ], $timetable->id);

        $timetable->update($data);

        return $this->ok(TimetableResource::make($timetable->load(['batch', 'course', 'teacher.user', 'room', 'timeSlot'])), 'Timetable updated');
    }
}
