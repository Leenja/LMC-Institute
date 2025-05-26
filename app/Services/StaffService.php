<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Course;
use App\Models\CourseSchedule;
use App\Models\Enrollment;
use App\Models\FlashCard;
use App\Repositories\StaffRepository;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\Lesson;
use App\Models\Room;
use App\Models\SelfTestQuestion;

class StaffService
{
    private $staffRepository;

    public function __construct(StaffRepository $staffRepository)
    {
        $this->staffRepository = $staffRepository;
    }

    //Secretary--------------------------------------------------

    //Enrollment

    public function enrollStudent($data)
    {
        return DB::transaction(function () use ($data) {

            $this->staffRepository->updateUserRole($data['StudentId'], 5);

            $enrollment = $this->staffRepository->createEnrollment($data);

            $schedule = CourseSchedule::where('CourseId', $data['CourseId'])->first();
            $this->changeEnrollStatus($schedule);

            app(RoomService::class)->assignRoomToCourse($schedule);
            app(RoomService::class)->optimizeRoomAssignments();

            return $enrollment;
        });
    }

    public function cancelEnrollment($data)
    {
        return DB::transaction(function () use ($data) {

            $this->staffRepository->deleteEnrollment($data['StudentId'], $data['CourseId']);

            // Check if the user is still enrolled in any other course
            $stillEnrolled = Enrollment::where('StudentId', $data['StudentId'])->exists();

            if (!$stillEnrolled) {
                $this->staffRepository->updateUserRole($data['StudentId'], 6);
            }

            // Recalculate enroll status and re-optimize room assignment
            $schedule = CourseSchedule::where('CourseId', $data['CourseId'])->first();
            $this->changeEnrollStatus($schedule);

            app(RoomService::class)->assignRoomToCourse($schedule);
            app(RoomService::class)->optimizeRoomAssignments();

            return ['message' => 'Enrollment cancelled successfully.'];
        });
    }

    public function changeEnrollStatus(CourseSchedule $schedule)
    {
        $now = Carbon::now();
        $studentCount = $schedule->course->Enrollment()->count();

        // Case 1: Enrollment time is over
        if ($schedule->End_Enroll && $now->gt(Carbon::parse($schedule->End_Enroll))) {
            $newStatus = 'Full';
        } else {
            // Case 2: No room can fit any more students
            $maxRoomCapacity = Room::max('Capacity');
            $newStatus = ($studentCount >= $maxRoomCapacity) ? 'Full' : 'Open';
        }

        if ($schedule->Enroll_Status !== $newStatus) {
            $schedule->Enroll_Status = $newStatus;
            $schedule->save();
        }
    }

    public function viewEnrolledStudentsInCourse($courseId)
    {
        return $this->staffRepository->getEnrolledStudentsInCourse($courseId);
    }

    //Add course
    public function createCourseWithSchedule($data)
    {
        return DB::transaction(function () use ($data) {

            $endDate = $this->staffRepository->calculateCourseEndDate(
                $data['Start_Date'],
                $data['CourseDays'],
                $data['Number_of_lessons']
            );

            $roomId = $data['RoomId'] ?? null;

            $conflict = null;

            if ($roomId !== null)
            {
                $conflict = $this->staffRepository->checkCourseScheduleConflict(
                    $roomId,
                    $data['Start_Date'],
                    $endDate,
                    $data['CourseDays'],
                    $data['Start_Time'],
                    $data['End_Time']
                );
            }


            if ($conflict) {
                return response()->json([
                    'Message' => 'The new course schedule conflicts with an existing course in the same room.'
                ], 400);
            }

            $teacherConflict = $this->staffRepository->checkTeacherScheduleConflict(
                $data['TeacherId'],
                $data['Start_Date'],
                $endDate,
                $data['CourseDays'],
                $data['Start_Time'],
                $data['End_Time']
            );

            if ($teacherConflict) {
                return response()->json([
                    'Message' => 'The teacher is already assigned to another course at this time.'
                ], 400);
            }

            $course = $this->staffRepository->createCourse($data);

            $schedule = $this->staffRepository->createSchedule($course->id, [
                'RoomId' => $roomId,
                'Start_Enroll' => $data['Start_Enroll'],
                'End_Enroll' => $data['End_Enroll'],
                'Start_Date' => Carbon::parse($data['Start_Date'])->setTimeFromTimeString($data['Start_Time']),
                'End_Date' => $endDate,
                'Start_Time' => $data['Start_Time'],
                'End_Time' => $data['End_Time'],
                'CourseDays' => $data['CourseDays'],
            ]);

            $lessons = $this->generateLessons($course->id, $data['Start_Date'], $data['Start_Time'], $data['End_Time'], $data['Number_of_lessons'], $data['CourseDays']);

            Lesson::insert($lessons);

            $enrollmentDays = $this->generateEnrollmentDays(
                $course->id,
                $data['Start_Enroll'],
                $data['End_Enroll']
            );

            DB::table('enrollment_days')->insert($enrollmentDays);

            return [
                'Course' => $course,
                'Schedule' => $schedule,
                'Lessons' => $lessons,
            ];
        });
    }

    private function generateLessons($courseId, $startDate, $startTime, $endTime, $lessonCount, $daysOfWeek)
    {
        $lessons = [];
        $date = Carbon::parse($startDate);
        $count = 0;

        while ($count < $lessonCount) {
            if (in_array($date->format('D'), $daysOfWeek)) {
                $lessons[] = [
                    'CourseId' => $courseId,
                    'Title' => "Lesson " . ($count + 1),
                    'Date' => $date->format('Y-m-d'),
                    'Start_Time' => $startTime,
                    'End_Time' => $endTime,
                    'created_at' => now(),
                    'updated_at' => now()
                ];
                $count++;
            }
            $date->addDay();
        }

        return $lessons;
    }

    private function generateEnrollmentDays($courseId, $startEnroll, $endEnroll)
    {
        $days = [];
        $date = Carbon::parse($startEnroll);
        $end = Carbon::parse($endEnroll);

        while ($date->lte($end)) {
            $days[] = [
                'CourseId' => $courseId,
                'Enroll_Date' => $date->format('Y-m-d'),
                'created_at' => now(),
                'updated_at' => now()
            ];
            $date->addDay();
        }

        return $days;
    }

    //Edit course
    public function editCourse($data)
    {
        return DB::transaction(function () use ($data) {

            $endDate = $this->staffRepository->calculateCourseEndDate(
                $data['Start_Date'],
                $data['CourseDays'],
                $data['Number_of_lessons']
            );

            if(!empty($data['Photo'])){
                Course::where('id', $data['CourseId'])->update(['Photo' => $data['Photo']]);
            }

            $conflict = $this->staffRepository->checkCourseScheduleConflict(
                $data['RoomId'],
                $data['Start_Date'],
                $endDate,
                $data['CourseDays'],
                $data['Start_Time'],
                $data['End_Time']
            );


            if ($conflict) {
                return response()->json([
                    'Message' => 'The updated course schedule conflicts with an existing course in the same room.'
                ], 400);
            }

            $teacherId = Course::where('id', $data['CourseId'])->value('TeacherId');

            $teacherConflict = $this->staffRepository->checkTeacherScheduleConflict(
                $teacherId,
                $data['Start_Date'],
                $endDate,
                $data['CourseDays'],
                $data['Start_Time'],
                $data['End_Time']
            );

            if ($teacherConflict) {
                return response()->json([
                    'Message' => 'The teacher is already assigned to another course at this time.'
                ], 400);
            }

            // Update the schedule
            $this->staffRepository->updateCourseSchedule($data['CourseId'], [
                'RoomId' => $data['RoomId'],
                'Start_Enroll' => $data['Start_Enroll'],
                'End_Enroll' => $data['End_Enroll'],
                'Start_Date' => Carbon::parse($data['Start_Date'])->setTimeFromTimeString($data['Start_Time']),
                'End_Date' => $endDate,
                'Start_Time' => $data['Start_Time'],
                'End_Time' => $data['End_Time'],
                'CourseDays' => $data['CourseDays'],
            ]);

            // Delete old lessons
            Lesson::where('CourseId', $data['CourseId'])->delete();

            // Generate new lessons
            $lessons = $this->generateLessons(
                $data['CourseId'],
                $data['Start_Date'],
                $data['Start_Time'],
                $data['End_Time'],
                $data['Number_of_lessons'],
                $data['CourseDays']
            );

            Lesson::insert($lessons);

            //Delete old enrollment_days
            DB::table('enrollment_days')->where('CourseId', $data['CourseId'])->delete();

            //Generate new enrollment_days
            $enrollmentDays = $this->generateEnrollmentDays(
                $data['CourseId'],
                $data['Start_Enroll'],
                $data['End_Enroll']
            );
            DB::table('enrollment_days')->insert($enrollmentDays);

            return [
                'UpdatedSchedule' => true,
                'Lessons' => $lessons,
            ];
        });
    }

    //Delete course
    public function deleteCourseWithLessons($course)
    {
        return DB::transaction(function () use ($course) {
            $this->staffRepository->deleteCourseAndLessons($course);
            return ['message' => 'Course and its lessons deleted successfully.'];
        });
    }

    public function viewCourses()
    {
        // BEFORE: Get today's date and time
        $today = Carbon::now()->toDateString();

        $courses = Course::with(['User', 'Language', 'CourseSchedule'])->get();

        $schedules = CourseSchedule::with('Course')->get();

        // AFTER: Update the status based on today's date
        foreach ($schedules as $schedule) {
            $course = $schedule->course;

            if(!$course) {
                continue;
            }

            if ($today < $schedule->Start_Date) {
                $course->Status = 'Unactive';
            } elseif ($today >= $schedule->Start_Date && $today <= $schedule->End_Date) {
                $course->Status = 'Active';
            } elseif ($today > $schedule->End_Date) {
                $course->Status = 'Done';
            }

            $course->save();
        }

        return $courses;
    }

    public function viewCourse($courseId)
    {
        $course = Course::with(['User', 'Language', 'CourseSchedule'])->find($courseId);

        return $course;
    }

    public function viewCourseDetails($courseId)
    {
        $schedule = CourseSchedule::with(['Course.Language', 'Course.User', 'Room'])->where('CourseId', $courseId)->first();

        return $schedule;
    }

    public function getCourseLessons($courseId)
    {
        return Lesson::with('Course.Language', 'Course.User', 'Course.User')->where('CourseId', $courseId)->get();
    }

    //Teacher---------------------------------------------------------

    //Review schedule for today

    public function getTodaysSchedule()
    {
        $teacherId = auth()->user()->id;
        $today = now()->toDateString();

        $lessons = $this->staffRepository->getScheduleByDay($teacherId, $today);

        if ($lessons->isEmpty()) {
            return ['message' => 'You do not have any lessons today.'];
        }

        return [
            'message' => 'You have lessons scheduled today.',
            'Lessons' => $lessons
        ];
    }

    //Flash card
    public function addFlashCard($data)
    {
        return DB::transaction(function () use ($data) {
            return $this->staffRepository->createFlashCard($data);
        });
    }

    public function editFlashCard($data)
    {
        return DB::transaction(function () use ($data) {
            return $this->staffRepository->updateFlashCard($data);
        });
    }

    public function deleteFlashCard($flashcardId)
    {
        return DB::transaction(function () use ($flashcardId) {
            return $this->staffRepository->deleteFlashCard($flashcardId);
        });
    }

    //View flash cards
    public function getAllFlashCards($teacherId)
    {
        $courseIds = Course::where('TeacherId', $teacherId)->pluck('id');

        return FlashCard::whereIn('CourseId', $courseIds)->get();
    }

    public function getFlashCard($teacherId, $flashCardId)
    {
        $flashCard = FlashCard::find($flashCardId);

        if (!$flashCard) {
            return null;
        }

        $isOwned = Course::where('id', $flashCard->CourseId)
        ->where('TeacherId', $teacherId)->exists();

        return $isOwned ? $flashCard : null;
    }

    public function viewLessonFlashCards($teacherId, $lessonId)
    {
        $lesson = Lesson::find($lessonId);

        if (!$lesson) {
            return null;
        }

        $ownsLesson = Course::where('id', $lesson->CourseId)
            ->where('TeacherId', $teacherId)->exists();

        if (!$ownsLesson) {
            return null;
        }

        return FlashCard::where('LessonId', $lessonId)->get();
    }

    public function viewCourseFlashCards($teacherId, $courseId)
    {
        $isOwned = Course::where('id', $courseId)
            ->where('TeacherId', $teacherId)->exists();

        if (!$isOwned) {
            return null;
        }

        return FlashCard::where('CourseId', $courseId)->get();
    }

    //Check attendance, enter bonus
    public function enterBonus($lessonId, $studentId, $bonus)
    {
        $teacherId = auth()->user()->id;

        $lesson = Lesson::where('lessons.id', $lessonId)
        ->join('courses', 'lessons.CourseId', '=', 'courses.id')
        ->where('courses.TeacherId', $teacherId)
        ->select('lessons.*')
        ->first();

        if (!$lesson) {
            return ['error' => 'Lesson not found or not assigned to you'];
        }

        $attendance = Attendance::where('LessonId', $lessonId)
            ->where('StudentId', $studentId)
            ->first();

        if (!$attendance) {
            return ['error' => 'Attendance record not found'];
        }

        $attendance->Bonus = $bonus;
        $attendance->save();

        $this->staffRepository->updateStudentProgress($studentId, $lesson->CourseId);

        return ['success' => 'Bonus updated successfully'];
    }

    public function markAttendance($lessonId, $studentId)
    {
        $teacherId = auth()->user()->id;

        $lesson = Lesson::where('lessons.id', $lessonId)
        ->join('courses', 'lessons.CourseId', '=', 'courses.id')
        ->where('courses.TeacherId', $teacherId)
        ->select('lessons.*')
        ->first();

        if (!$lesson) {
            return ['error' => 'Lesson not found or not assigned to you'];
        }

        $isEnrolled = DB::table('enrollments')
        ->where('CourseId', $lesson->CourseId)
        ->where('StudentId', $studentId)
        ->exists();

        if (!$isEnrolled) {
            return ['error' => 'Student is not enrolled in this course'];
        }

        Attendance::create([
            'LessonId' => $lessonId,
            'StudentId' => $studentId,
            'Bonus' => 0,
        ]);

        $this->staffRepository->updateStudentProgress($studentId, $lesson->CourseId);

        return ['success' => 'Attendance record created'];
    }

    //Add,edit,delete Self Test
    public function addSelfTest($data)
    {
        return DB::transaction(function () use ($data) {
            return $this->staffRepository->createSelfTest($data);
        });
    }

    public function editSelfTest($data)
    {
        return DB::transaction(function () use ($data) {
            return $this->staffRepository->updateSelfTest($data);
        });
    }

    public function deleteSelfTest($selfTestId)
    {
        return DB::transaction(function () use ($selfTestId) {
            return $this->staffRepository->deleteSelfTest($selfTestId);
        });
    }

    public function addSelfTestQuestion(array $data)
    {
        return SelfTestQuestion::create([
            'SelfTestId' => $data['SelfTestId'],
            'Media' => $data['Media'] ?? null,
            'QuestionText' => $data['QuestionText'],
            'Type' => $data['Type'],
            'Choices' => $data['Choices'] ?? null,
            'CorrectAnswer' => $data['CorrectAnswer'] ?? null,
        ]);
    }

    public function editSelfTestQuestion(array $data)
    {
        $question = SelfTestQuestion::findOrFail($data['SelfTestQuestionId']);

        $question->update([
            'Media' => $data['Media'] ?? $question->Media,
            'QuestionText' => $data['QuestionText'],
            'Type' => $data['Type'],
            'Choices' => $data['Choices'] ?? null,
            'CorrectAnswer' => $data['CorrectAnswer'] ?? null,
        ]);

        return $question;
    }

    public function deleteSelfTestQuestion($id)
    {
        $question = SelfTestQuestion::findOrFail($id);
        $question->delete();

        return true;
    }

}
