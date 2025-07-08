<?php

namespace App\Services;

use App\Models\CourseSchedule;
use App\Models\Room;
use App\Repositories\RoomRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class RoomService
{
    protected $roomRepository;

    public function __construct(RoomRepository $roomRepository)
    {
        $this->roomRepository = $roomRepository;
    }

    public function addRoom(array $data)
    {
        $validator = Validator::make($data, [
            'Capacity' => 'required|integer|min:1',
            'NumberOfRoom' => 'required|string|unique:rooms,NumberOfRoom',
        ]);

        if ($validator->fails()) {
            return [
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ];
        }

        // لا نُرسل 'Status' حتى لا نكسر القيمة الافتراضية
        unset($data['Status']);

        return $this->roomRepository->createRoom($data);
    }

    public function updateRoom(int $id, array $data)
    {
        $room = $this->roomRepository->findRoomById($id);
        if (!$room) {
            return [
                'status' => false,
                'message' => "Room with ID $id not found",
            ];
        }

        $validator = Validator::make($data, [
            'Capacity' => 'sometimes|integer|min:1',
            'NumberOfRoom' => 'sometimes|string|unique:rooms,NumberOfRoom,' . $id,
            'Status' => 'sometimes|in:Available,NotAvailable',
        ]);

        if (empty($data['Capacity']) && empty($data['NumberOfRoom']) && empty($data['Status'])) {
            return [
                'status' => false,
                'message' => 'No data provided to update.',
            ];
        }

        if ($validator->fails()) {
            return [
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ];
        }

        $updatedRoom = $this->roomRepository->updateRoom($id, $data);

        return [
            'status' => true,
            'message' => 'Room updated successfully',
            'data' => $updatedRoom,
        ];
    }

    public function getAvailableRooms($startDate, $startTime, $numberOfLessons, $courseDays)
    {
        $endDate = $this->roomRepository->calculateCourseEndDate($startDate, $courseDays, $numberOfLessons);

        // Get rooms with conflicts
        $conflictingRoomIds = DB::table('course_schedules')
            ->where(function ($query) use ($startDate, $endDate, $startTime, $courseDays) {
                $query->where(function ($q) use ($startDate, $endDate) {
                    $q->whereBetween('Start_Date', [$startDate, $endDate])
                    ->orWhereBetween('End_Date', [$startDate, $endDate])
                    ->orWhere(function ($q2) use ($startDate, $endDate) {
                        $q2->where('Start_Date', '<=', $endDate)
                            ->where('End_Date', '>=', $startDate);
                    });
                });

                $query->where(function ($q) use ($courseDays) {
                    foreach ($courseDays as $day) {
                        $q->orWhereRaw("JSON_CONTAINS(CourseDays, '\"$day\"')");
                    }
                });

                $query->where(function ($q) use ($startTime) {
                    $q->where('Start_Time', '<=', $startTime)
                    ->where('End_Time', '>', $startTime);
                });
            })
            ->pluck('RoomId');

        // Return available rooms not in conflict list
        return Room::whereNotIn('id', $conflictingRoomIds)->get();
    }

    public function assignRoomToCourse(CourseSchedule $schedule)
    {
        $studentCount = $schedule->course->Enrollment()->count();

        // Determine if the current room is still valid
        $currentRoom = $schedule->RoomId ? Room::find($schedule->RoomId) : null;

        $needsReassignment = true;

        if ($currentRoom) {
            $hasCapacity = $currentRoom->Capacity >= $studentCount;
            $hasNoConflict = !$this->hasConflict($currentRoom, $schedule);
            $needsReassignment = !($hasCapacity && $hasNoConflict);
        }

        if ($needsReassignment) {
            // Get rooms that can fit the student count
            $availableRooms = Room::where('Capacity', '>=', $studentCount)->get();

            // Filter out conflicting rooms
            $conflictFreeRooms = $availableRooms->filter(function ($room) use ($schedule) {
                return !$this->hasConflict($room, $schedule);
            });

            if ($conflictFreeRooms->isNotEmpty()) {
                // Assign the smallest room that fits
                $selectedRoom = $conflictFreeRooms->sortBy('Capacity')->first();
                $schedule->RoomId = $selectedRoom->id;
                $schedule->save();
            }
        }
    }

    private function hasConflict(Room $room, CourseSchedule $newSchedule)
    {
        // Check for overlapping courses in the same room
        return $room->CourseSchedule()
        ->where('id', '!=', $newSchedule->id)
        ->where(function ($query) use ($newSchedule) {
            $query->where(function ($q) use ($newSchedule) {
                $q->where('Start_Date', '<=', $newSchedule->End_Date)
                  ->where('End_Date', '>=', $newSchedule->Start_Date);
            })
            ->where(function ($q) use ($newSchedule) {
                $q->where('Start_Time', '<', $newSchedule->End_Time)
                  ->where('End_Time', '>', $newSchedule->Start_Time);
            })
            ->where(function ($q) use ($newSchedule) {
                foreach ($newSchedule->CourseDays as $day) {
                    $q->orWhereJsonContains('CourseDays', $day);
                }
            });
        })->exists();
    }

    public function optimizeRoomAssignments()
    {
        $upcomingCourses = CourseSchedule::with(['Course','Course.Enrollment'])
            ->whereDate('Start_Date', '>', now())
            ->whereNotNull('RoomId')->get();

        // Group schedules by overlapping time slots
        $groups = [];

        foreach ($upcomingCourses as $schedule) {
            $matchedGroup = null;

            foreach ($groups as &$group) {
                foreach ($group as $existing) {
                    if (
                        $this->hasTimeConflict($existing, $schedule) &&
                        !empty(array_intersect($existing->CourseDays, $schedule->CourseDays))
                    ) {
                        $matchedGroup = &$group;
                        break 2;
                    }
                }
            }

            if ($matchedGroup) {
                $matchedGroup[] = $schedule;
            } else {
                $groups[] = [$schedule];
            }
        }

        foreach ($groups as $group) {
            // Sort courses by student count ascending
            $sortedGroup = collect($group)->sortBy(function ($s) {
                return $s->Course->Enrollment()->count();
            });

            $rooms = Room::orderBy('Capacity')->get();
            $roomAssignments = [];

            foreach ($sortedGroup as $schedule) {
                $studentCount = $schedule->Course->Enrollment()->count();

                foreach ($rooms as $room) {
                    if (
                        $room->Capacity >= $studentCount &&
                        !in_array($room->id, $roomAssignments) &&
                        !$this->hasConflict($room, $schedule)
                    ) {
                        $schedule->RoomId = $room->id;
                        $schedule->save();

                        $roomAssignments[] = $room->id;
                        break;
                    }
                }
            }
        }
    }

    private function hasTimeConflict($a, $b)
    {
        return $a->Start_Time < $b->End_Time && $a->End_Time > $b->Start_Time;
    }

}
