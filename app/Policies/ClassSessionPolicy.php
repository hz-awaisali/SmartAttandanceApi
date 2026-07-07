<?php

namespace App\Policies;

use App\Models\ClassSession;
use App\Models\User;

class ClassSessionPolicy
{
    public function view(User $user, ClassSession $session): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isTeacher()) {
            return $session->timetable->teacher->user_id === $user->id;
        }

        if ($user->isStudent()) {
            return $session->timetable->batch_id === $user->student?->batch_id;
        }

        if ($user->isHod()) {
            return $session->timetable->batch->program->department_id === $user->teacher?->department_id;
        }

        return false;
    }

    public function end(User $user, ClassSession $session): bool
    {
        return ($user->isTeacher() || $user->isHod()) && $session->timetable->teacher->user_id === $user->id;
    }
}
