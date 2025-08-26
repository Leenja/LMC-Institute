<?php

namespace App\Observers;

use App\Models\CourseSchedule;
use App\Services\RoomService;

class CourseScheduleObserver
{
    public function updated(CourseSchedule $schedule)
    {
        // Only trigger if Enroll_Status just changed to 'Full' and RoomId is still null
        if ($schedule->isDirty('Enroll_Status') && $schedule->Enroll_Status === 'Full' && $schedule->RoomId === null)
        {
            app(RoomService::class)->assignRoomToCourse($schedule);
        }
    }
}
