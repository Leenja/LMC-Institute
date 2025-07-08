<?php

namespace App\Http\Controllers;

use App\Models\CourseSchedule;
use App\Models\Room;
use App\Services\RoomService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class RoomController extends Controller
{
    protected $roomService;

    public function __construct(RoomService $roomService)
    {
        $this->roomService = $roomService;
    }

    public function addRoom(Request $request)
    {
        $room = $this->roomService->addRoom($request->all());

        return response()->json([
            'message' => 'Room added successfully',
            'data' => $room,
        ], 201);
    }

    public function updateRoom(Request $request, $id)
    {
        $response = $this->roomService->updateRoom($id, $request->all());

        if (!$response['status']) {
            return response()->json([
                'message' => $response['message'],
                'errors' => $response['errors'] ?? null,
            ], 400); // Bad Request
        }

        return response()->json([
            'message' => $response['message'],
            'data' => $response['data'],
        ]);
    }

    public function showRooms()
    {
        $now = Carbon::now('Asia/Damascus');

        // Reset all rooms to Available
        Room::query()->update(['Status' => 'Available']);

        // Get all course schedules with rooms
        $schedules = CourseSchedule::whereNotNull('RoomId')->with('Course')->get();

        foreach ($schedules as $schedule) {
            // Check if course is active
            if ($now->between(Carbon::parse($schedule->Start_Date), Carbon::parse($schedule->End_Date))) {

                // Check if today is a course day
                $today = $now->format('D'); // ex:'Tue'
                if (in_array($today, $schedule->CourseDays)) {

                    // Check if now is within course hours
                    $startTime = Carbon::parse($schedule->Start_Time);
                    $endTime = Carbon::parse($schedule->End_Time);

                    if ($now->between($startTime, $endTime)) {
                        // Mark the room as not available
                        $room = Room::find($schedule->RoomId);
                        if ($room) {
                            $room->Status = 'NotAvailable';
                            $room->save();
                        }
                    }
                }
            }
        }
        return response()->json(Room::all());
    }

    public function viewReservedRooms()
    {

        $reservedRooms = Room::where('Status', 'NotAvailable')->get();

        return response()->json($reservedRooms);
    }

    public function viewAvailableRooms(Request $request)
    {
        $validated = $request->validate([
            'Start_Date' => 'required|date',
            'Start_Time' => 'required|date_format:H:i',
            'NumberOfLessons' => 'required|integer|min:1',
            'CourseDays' => 'required|array|min:1'
        ]);

        $availableRooms = $this->roomService->getAvailableRooms(
            $validated['Start_Date'],
            $validated['Start_Time'],
            $validated['NumberOfLessons'],
            $validated['CourseDays']
        );

        return response()->json([
            'AvailableRooms' => $availableRooms
        ]);
    }
}
