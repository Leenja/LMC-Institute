<?php

namespace App\Http\Controllers;

use App\Models\Course;
use Illuminate\Http\Request;

use App\Models\Lesson;
use App\Models\CourseSchedule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\Holiday;
use App\Models\ScheduleEnrollmentBackup;




class HolidayController extends Controller
{
    public function addHoliday(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'Name' => 'required|string|max:255',
            'Description' => 'required|string|max:1000',
            'StartDate' => 'required|date|before_or_equal:EndDate',
            'EndDate' => 'required|date|after_or_equal:StartDate',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::transaction(function () use ($request) {
            $holiday = Holiday::create([
                'Name' => $request['Name'],
                'Description' => $request['Description'],
                'StartDate' => $request['StartDate'],
                'EndDate' => $request['EndDate'],
            ]);

            $startHoliday = Carbon::parse($holiday->StartDate);
            $endHoliday = Carbon::parse($holiday->EndDate);
            $today = Carbon::today();

            $allHolidays = Holiday::all();

            $affectedSchedules = CourseSchedule::with('course.lessons')
                ->where(function ($query) use ($startHoliday, $endHoliday) {
                    $query->whereBetween('Start_Date', [$startHoliday, $endHoliday])
                        ->orWhereBetween('End_Date', [$startHoliday, $endHoliday])
                        ->orWhere(function ($query) use ($startHoliday, $endHoliday) {
                            $query->where('Start_Date', '<', $startHoliday)
                                ->where('End_Date', '>', $endHoliday);
                        })
                        ->orWhereBetween('Start_Enroll', [$startHoliday, $endHoliday])
                        ->orWhereBetween('End_Enroll', [$startHoliday, $endHoliday])
                        ->orWhere(function ($query) use ($startHoliday, $endHoliday) {
                            $query->where('Start_Enroll', '<', $startHoliday)
                                ->where('End_Enroll', '>', $endHoliday);
                        });
                })
                ->get();

            foreach ($affectedSchedules as $schedule) {
                $course = $schedule->course;
                $roomId = $schedule->RoomId;
                $courseDays = $schedule->CourseDays ?? [];
                $courseId = $course->id;

                $lessons = $course->lessons()->orderBy('Date')->get();
                $newFirstLessonDate = null;

                // ============== LESSONS PROCESSING ==============
                if ($lessons->isNotEmpty()) {
                    // Process lessons affected by holiday
                    $lessonsInHoliday = $lessons->filter(
                        fn($l) =>
                        Carbon::parse($l->Date)->between($startHoliday, $endHoliday)
                    );

                    foreach ($lessonsInHoliday as $lesson) {
                        DB::table('lesson_backups')->insert([
                            'CourseId' => $lesson->CourseId,
                            'Title' => $lesson->Title,
                            'Date' => $lesson->Date,
                            'Start_Time' => $lesson->Start_Time,
                            'End_Time' => $lesson->End_Time,
                            'holiday_id' => $holiday->id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    // Delete lessons in holiday period
                    $course->lessons()
                        ->whereDate('Date', '>=', $startHoliday)
                        ->whereDate('Date', '<=', $endHoliday)
                        ->delete();

                    // Reschedule lessons
                    $backupLessons = DB::table('lesson_backups')
                        ->where('CourseId', $courseId)
                        ->where('holiday_id', $holiday->id)
                        ->orderBy('Date')
                        ->get();

                    $usedDates = [];
                    $newLessons = [];
                    $newDate = $endHoliday->copy()->addDay();

                    foreach ($backupLessons as $backup) {
                        while (
                            in_array($newDate->toDateString(), $usedDates) ||
                            $allHolidays->contains(fn($h) => $newDate->between($h->StartDate, $h->EndDate)) ||
                            Lesson::whereDate('Date', $newDate->toDateString())
                            ->where('Start_Time', $backup->Start_Time)
                            ->where('End_Time', $backup->End_Time)
                            ->whereHas('course.CourseSchedule', function ($q) use ($roomId, $course) {
                                $q->where('RoomId', $roomId)
                                    ->orWhere('TeacherId', $course/*->CourseSchedule-*/->TeacherId); // 🔴 هذا الشرط الجديد
                            })
                            ->exists() ||
                            !in_array($newDate->format('D'), $courseDays)
                        ) {
                            $newDate->addDay();
                        }

                        $newLessons[] = [
                            'CourseId' => $backup->CourseId,
                            'Title' => $backup->Title,
                            'Date' => $newDate->toDateString(),
                            'Start_Time' => $backup->Start_Time,
                            'End_Time' => $backup->End_Time,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];

                        $usedDates[] = $newDate->toDateString();
                        $newDate->addDay();
                    }

                    // Insert new lessons
                    if (!empty($newLessons)) {
                        Lesson::insert($newLessons);
                    }

                    // Update course dates
                    $newFirstLesson = $course->fresh()->lessons()->orderBy('Date')->first();
                    $newFirstLessonDate = $newFirstLesson ? Carbon::parse($newFirstLesson->Date) : null;
                    $newLastLesson = $course->lessons()->orderByDesc('Date')->first();

                    $schedule->update([
                        'Start_Date' => $newFirstLesson->Date,
                        'End_Date' => $newLastLesson->Date
                    ]);
                }

                // ============== ENROLLMENT PROCESSING ==============
                $originalEnrollDays = DB::table('enrollment_days')
                    ->where('CourseId', $courseId)
                    ->orderBy('Enroll_Date')
                    ->pluck('Enroll_Date')
                    ->map(fn($d) => Carbon::parse($d));

                // Skip if no enrollment days exist
                if ($originalEnrollDays->isEmpty()) {
                    continue;
                }

                $originalDuration = $originalEnrollDays->count();
                $originalStart = $originalEnrollDays->first();
                $originalEnd = $originalEnrollDays->last();

                // Use updated first lesson date if available
                $firstLessonDate = $newFirstLessonDate ?? Carbon::parse($schedule->Start_Date);

                // Check for holidays in enrollment period
                $hasHoliday = $originalEnrollDays->contains(
                    fn($date) =>
                    $allHolidays->contains(fn($h) => $date->between($h->StartDate, $h->EndDate))
                );

                if ($hasHoliday) {
                    // Create backup if not exists
                    $alreadyBackedUp = ScheduleEnrollmentBackup::where('schedule_id', $schedule->id)
                        ->where('holiday_id', $holiday->id)
                        ->exists();

                    if (!$alreadyBackedUp) {
                        ScheduleEnrollmentBackup::create([
                            'schedule_id' => $schedule->id,
                            'holiday_id' => $holiday->id,
                            'original_start_enroll' => $originalStart->toDateString(),
                            'original_end_enroll' => $originalEnd->toDateString(),
                        ]);
                    }

                    // Collect new enrollment dates
                    $cursor = $originalStart->copy();
                    $newEnrollmentDates = collect();
                    $maxAttempts = 365 * 2; // Prevent infinite loops
                    $attempts = 0;

                    while (
                        $newEnrollmentDates->count() < $originalDuration &&
                        $cursor->lt($firstLessonDate) &&
                        $attempts < $maxAttempts
                    ) {
                        $isHoliday = $allHolidays->contains(fn($h) => $cursor->between($h->StartDate, $h->EndDate));
                        if (!$isHoliday) {
                            $newEnrollmentDates->push($cursor->copy());
                        }
                        $cursor->addDay();
                        $attempts++;
                    }

                    // Update with whatever duration we could get
                    if ($newEnrollmentDates->isNotEmpty()) {
                        $newStart = $newEnrollmentDates->first();
                        $newEnd = $newEnrollmentDates->last();

                        $schedule->update([
                            'Start_Enroll' => $newStart->toDateString(),
                            'End_Enroll' => $newEnd->toDateString(),
                        ]);

                        // Calculate duration difference
                        $achievedDuration = $newEnrollmentDates->count();
                        $durationDifference = $originalDuration - $achievedDuration;

                        // Notify secretaries
                        $message = "The enrollment period for the course '{$course->Name}' has been adjusted due to the holiday ({$holiday->Name}). " .
                                    "The new period: from {$newStart->toDateString()} to {$newEnd->toDateString()}";

                        if ($achievedDuration < $originalDuration) {
                            $message .= " (Note: The duration was shortened by {$durationDifference} days due to holiday conflicts)";
                        }

                    } else {

                        return response()->json([
                            'status' => 'Done',
                            'message' => 'Warning: No valid enrollment days were found for the course {$course->Name} after applying the holiday ({$holiday->Name})',
                        ], 200);
                    }
                }
            }
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Holiday added successfully, enrollment and lessons adjusted.',
        ]);
    }

    public function getHoliday()
    {
        $holidays = Holiday::all();

        $holidaysWithDetails = $holidays->map(function ($holiday) {
            //Get lessons affected by this holiday from backups
            $affectedLessons = DB::table('lesson_backups')
                ->where('holiday_id', $holiday->id)
                ->get(['CourseId', 'Title', 'Date']);

            $groupedByCourse = $affectedLessons->groupBy('CourseId');

            $affectedCourses = $groupedByCourse->map(function ($lessons, $courseId) {
                $course = Course::find($courseId);

                //Retrieve new lessons after modifying the schedule
                $newLessons = Lesson::where('CourseId', $courseId)
                    ->orderBy('Date')
                    ->get(['Title', 'Date', 'Start_Time', 'End_Time']);

                return [
                    'CourseId' => $courseId,
                    'CourseTitle' => $course?->Description ?? 'Unknown',
                    'OldStartDate' => optional($lessons)->min('Date'),
                    'OldEndDateBeforeHoliday' => optional($lessons)->max('Date'),
                    'NewStartDate' => optional($newLessons)->min('Date'),
                    'NewEndDate' => optional($newLessons)->max('Date'),
                    'AffectedLessonTitles' => $lessons->pluck('Title')->unique()->values(),
                    'AffectedLessonCount' => $lessons->count(),
                    'NewSchedule' => $newLessons->map(function ($lesson) {
                        return [
                            'Title' => $lesson->Title,
                            'Date' => $lesson->Date,
                            'StartTime' => $lesson->Start_Time,
                            'EndTime' => $lesson->End_Time,
                        ];
                    })->values(),
                ];
            })->values();

            return [
                'id' => $holiday->id,
                'Name' => $holiday->Name,
                'Description' => $holiday->Description,
                'StartDate' => $holiday->StartDate,
                'EndDate' => $holiday->EndDate,
                'AffectedLessonsCount' => $affectedLessons->count(),
                'AffectedCoursesCount' => $affectedCourses->count(),
                'AffectedCourses' => $affectedCourses,
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $holidaysWithDetails,
        ], 200);
    }
}
