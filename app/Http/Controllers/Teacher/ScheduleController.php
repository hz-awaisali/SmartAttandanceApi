<?php

namespace App\Http\Controllers\Teacher;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Resources\TimetableResource;
use App\Models\Timetable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScheduleController extends Controller
{
    public function schedule(Request $request): JsonResponse
    {
        $teacher = $request->user()->teacher;

        if (! $teacher) {
            throw new BusinessException('No teacher profile is linked to this account. Please contact the administrator.', 404);
        }

        $timetables = Timetable::where('teacher_id', $teacher->id)
            ->with(['course.program.department', 'batch.program.department', 'room', 'timeSlot'])
            ->orderByRaw("FIELD(day, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday')")
            ->get()
            ->groupBy('day');

        return $this->ok(
            $timetables->map(fn ($items) => TimetableResource::collection($items))
        );
    }
}
