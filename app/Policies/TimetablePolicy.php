<?php

namespace App\Policies;

use App\Models\Timetable;
use App\Models\User;

class TimetablePolicy
{
    public function start(User $user, Timetable $timetable): bool
    {
        return ($user->isTeacher() || $user->isHod()) && $timetable->teacher->user_id === $user->id;
    }
}
