<?php

namespace App\Policies;

use App\Models\AttendanceEvent;
use App\Models\User;

class AttendanceEventPolicy
{
    public function view(User $user, AttendanceEvent $event): bool
    {
        if ($user->isAdministrator()) {
            return true;
        }

        return $user->is_active
            && $user->employee?->id === $event->employee_id;
    }
}
