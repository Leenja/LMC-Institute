<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Course;
use App\Models\CourseSchedule;
use App\Models\Enrollment;
use App\Models\FlashCard;
use App\Models\Holiday;
use App\Repositories\StaffRepository;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\Lesson;
use App\Models\Room;
use App\Models\SelfTestQuestion;
use App\Models\User;
use App\Repositories\RoleRepository;


class StaffService
{
    private $staffRepository;
    protected $roleRepository;


    public function __construct(StaffRepository $staffRepository, RoleRepository $roleRepository)
    {
        $this->staffRepository = $staffRepository;
        $this->roleRepository = $roleRepository;
    }

    //lana

    public function getAllRoles()
    {
        return $this->roleRepository->getAllRoles();
    }

    public function getUsersByRoleId($roleId)
    {
        return $this->roleRepository->getUsersByRoleId($roleId);
    }


    public function deleteEmployee($userId)
    {
        $user = User::withTrashed()->find($userId);

        if (!$user) {
            return [
                'status' => 404,
                'message' => 'User not found.'
            ];
        }

        if ($user->trashed()) {
            return [
                'status' => 200,
                'message' => 'User was already deleted before.',
                'deleted_at' => $user->deleted_at
            ];
        }

        $relatedTables = [
            ['table' => 'invoice_recipients', 'foreign_key' => 'UserId'],
            ['table' => 'invoices', 'foreign_key' => 'CreatorId'],
            ['table' => 'tasks', 'foreign_key' => 'CreatorId'],
            ['table' => 'student_progress', 'foreign_key' => 'StudentId'],
            ['table' => 'notes', 'foreign_key' => 'StudentId'],
            ['table' => 'tests', 'foreign_key' => 'TeacherId'],
            ['table' => 'attendances', 'foreign_key' => 'StudentId'],
            ['table' => 'announcements', 'foreign_key' => 'CreatorId'],
            ['table' => 'usertasks', 'foreign_key' => 'UserId'],
            ['table' => 'enrollments', 'foreign_key' => 'StudentId'],
            ['table' => 'complaints', 'foreign_key' => 'TeacherId'],
            ['table' => 'placement_tests', 'foreign_key' => 'GuestId'],
            ['table' => 'courses', 'foreign_key' => 'TeacherId'],
        ];

        foreach ($relatedTables as $relation) {
            $exists = DB::table($relation['table'])
                ->where($relation['foreign_key'], $userId)
                ->exists();

            if ($exists) {
                $user->delete();
                DB::table('staff_infos')->where('UserId', $userId)->update(['deleted_at' => now()]);

                return [
                    'status' => 200,
                    'message' => 'User soft deleted due to related data.'
                ];
            }
        }

        // No relations: hard delete
        DB::table('staff_infos')->where('UserId', $userId)->delete();
        $user->forceDelete();

        return [
            'status' => 200,
            'message' => 'User permanently deleted.'
        ];
    }

    //new
    public function getAllEmployees(?string $filter = 'active')
    {
        $roles = ['Teacher', 'Secretarya', 'Logistic'];
        $roleIds = $this->roleRepository->getRoleIdsByNames($roles);
        return $this->roleRepository->getUsersByRoleIdsWithStaffInfo($roleIds, $filter);
    }

    public function getEmployeeById(int $id, bool $withTrashed = false)
    {
        return $this->roleRepository->getUserById($id, $withTrashed);
    }

    public function restoreEmployee($userId)
    {
        // جلب المستخدم مع المحذوفين (soft deleted)
        $user = User::withTrashed()->find($userId);

        if (!$user) {
            throw new \Exception('User not found', 404);
        }

        if (!$user->trashed()) {
            throw new \Exception('User is not deleted.', 400);
        }

        // استرجاع المستخدم
        $user->restore();

        // استرجاع بيانات staff_infos المرتبطة (إذا تستخدم soft deletes)
        DB::table('staff_infos')
            ->where('UserId', $userId)
            ->update(['deleted_at' => null]);

        // لا نعيد response هنا، فقط بيانات أو true إذا تريد
        return true;
    }



    //end lana

    //Secretary--------------------------------------------------

    //Enrollment

    public function enrollStudent($data)
    {
        return DB::transaction(function () use ($data) {

            $this->staffRepository->updateUserRole($data['StudentId'], 5);

            // $this->staffRepository->updateUserRole($data['StudentId'], 'Student');


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

    //Add course
    //lana

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

            if ($roomId !== null) {
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

            /* $schedule = $this->staffRepository->createSchedule($course->id, [
                'RoomId' => $roomId,
                'Start_Enroll' => $data['Start_Enroll'],
                'End_Enroll' => $data['End_Enroll'],
                'Start_Date' => Carbon::parse($data['Start_Date'])->setTimeFromTimeString($data['Start_Time']),
                'End_Date' => $endDate,
                'Start_Time' => $data['Start_Time'],
                'End_Time' => $data['End_Time'],
                'CourseDays' => $data['CourseDays'],
            ]);*/
            //lana



            // جلب العطل//lana
            //  $holidays = Holiday::pluck('date')->map(fn($d) => Carbon::parse($d)->toDateString())->toArray();
            $holidays = Holiday::all()->flatMap(function ($holiday) {
                $start = Carbon::parse($holiday->StartDate);
                $end = $holiday->EndDate ? Carbon::parse($holiday->EndDate) : $start;

                return collect(range(0, $start->diffInDays($end)))->map(function ($i) use ($start) {
                    return $start->copy()->addDays($i)->toDateString();
                });
            })->toArray();


            $lessons = $this->generateLessons(
                $course->id,
                $data['Start_Date'],
                $data['Start_Time'],
                $data['End_Time'],
                $data['Number_of_lessons'],
                $data['CourseDays'],
                //lana
                $holidays
            );

            Lesson::insert($lessons);

            // 4) نحسب Start/End بناءً على أول وآخر درس فعلي
            $firstLessonDate = Carbon::parse($lessons[0]['Date'])->setTimeFromTimeString($data['Start_Time']);
            $lastLessonDate  = Carbon::parse(end($lessons)['Date'])->setTimeFromTimeString($data['End_Time']);

            $schedule = $this->staffRepository->createSchedule($course->id, [
                'RoomId'       => $roomId,
                'Start_Enroll' => $data['Start_Enroll'],
                'End_Enroll'   => $data['End_Enroll'],
                'Start_Date'   => $firstLessonDate,
                'End_Date'     => $lastLessonDate,
                'Start_Time'   => $data['Start_Time'],
                'End_Time'     => $data['End_Time'],
                'CourseDays'   => $data['CourseDays'],
            ]);

            $enrollmentDays = $this->generateEnrollmentDays(
                $course->id,
                $data['Start_Enroll'],
                $data['End_Enroll'],
                //lana
                $holidays,
                $data['Start_Date'], // أضف هذا الباراميتر الجديد
            );

            DB::table('enrollment_days')->insert($enrollmentDays);

            return [
                'Course' => $course,
                'Schedule' => $schedule,
                'Lessons' => $lessons,
            ];
        });
    }

    private function generateLessons($courseId, $startDate, $startTime, $endTime, $lessonCount, $daysOfWeek, /*lana*/ $holidays)
    {
        $lessons = [];
        $date = Carbon::parse($startDate);
        $count = 0;

        while ($count < $lessonCount) {
            if (
                in_array($date->format('D'), $daysOfWeek)
                //lana
                &&
                !in_array($date->toDateString(), $holidays)
            ) {
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

    /* private function generateEnrollmentDays($courseId, $startEnroll, $endEnroll,/*lana*/ /*$holidays)
      {
        $days = [];
        $date = Carbon::parse($startEnroll);
        $end = Carbon::parse($endEnroll);

        while ($date->lte($end)) {
            //lana
            if (!in_array($date->toDateString(), $holidays)) {
                $days[] = [
                    'CourseId' => $courseId,
                    'Enroll_Date' => $date->format('Y-m-d'),
                    'created_at' => now(),
                    'updated_at' => now()
                ];
            }
            $date->addDay();
        }

        return $days;
    }*/

    private function generateEnrollmentDays($courseId, $startEnroll, $endEnroll, $holidays, $courseStartDate)
    {
        $days = [];

        $date = Carbon::parse($startEnroll);
        $end = Carbon::parse($endEnroll);
        $startCourse = Carbon::parse($courseStartDate);

        $requiredCount = $date->diffInDays($end) + 1; // ← عدد الأيام الأصلية بين البداية والنهاية (شاملة العطل)

        $validDates = [];

        // اجمع الأيام الصالحة (غير العطل) داخل الفترة الأصلية
        $tempDate = $date->copy();
        while ($tempDate->lte($end)) {
            if (!in_array($tempDate->toDateString(), $holidays)) {
                $validDates[] = $tempDate->copy();
            }
            $tempDate->addDay();
        }

        // أضف الأيام الصالحة إلى القائمة
        foreach ($validDates as $validDate) {
            $days[] = [
                'CourseId' => $courseId,
                'Enroll_Date' => $validDate->format('Y-m-d'),
                'created_at' => now(),
                'updated_at' => now()
            ];
        }

        // كم تبقى لإكمال العدد المطلوب؟
        $missingCount = $requiredCount - count($days);

        // عوّض الأيام المفقودة بعد End_Enroll إلى ما قبل StartCourse
        $extraDate = $end->copy()->addDay();
        while ($missingCount > 0 && $extraDate->lt($startCourse)) {
            if (!in_array($extraDate->toDateString(), $holidays)) {
                $days[] = [
                    'CourseId' => $courseId,
                    'Enroll_Date' => $extraDate->format('Y-m-d'),
                    'created_at' => now(),
                    'updated_at' => now()
                ];
                $missingCount--;
            }
            $extraDate->addDay();
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

            if (!empty($data['Photo'])) {
                Course::where('id', $data['CourseId'])->update(['Photo' => $data['Photo']]);
            }

            $conflict = $this->staffRepository->checkCourseScheduleConflict(
                // $data['RoomId'],
                $roomId = $data['RoomId'] ?? null,
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
            /*$this->staffRepository->updateCourseSchedule($data['CourseId'], [
                'RoomId' => $data['RoomId'],
                'Start_Enroll' => $data['Start_Enroll'],
                'End_Enroll' => $data['End_Enroll'],
                'Start_Date' => Carbon::parse($data['Start_Date'])->setTimeFromTimeString($data['Start_Time']),
                'End_Date' => $endDate,
                'Start_Time' => $data['Start_Time'],
                'End_Time' => $data['End_Time'],
                'CourseDays' => $data['CourseDays'],
            ]);*/



            // جلب العطل//lana
            // $holidays = Holiday::pluck('date')->map(fn($d) => Carbon::parse($d)->toDateString())->toArray();
            $holidays = Holiday::all()->flatMap(function ($holiday) {
                $start = Carbon::parse($holiday->StartDate);
                $end = $holiday->EndDate ? Carbon::parse($holiday->EndDate) : $start;

                return collect(range(0, $start->diffInDays($end)))->map(function ($i) use ($start) {
                    return $start->copy()->addDays($i)->toDateString();
                });
            })->toArray();


            // Delete old lessons
            Lesson::where('CourseId', $data['CourseId'])->delete();

            // Generate new lessons
            $lessons = $this->generateLessons(
                $data['CourseId'],
                $data['Start_Date'],
                $data['Start_Time'],
                $data['End_Time'],
                $data['Number_of_lessons'],
                $data['CourseDays'],
                //lana
                $holidays
            );

            Lesson::insert($lessons);

            $firstLessonDate = Carbon::parse($lessons[0]['Date'])->setTimeFromTimeString($data['Start_Time']);
            $lastLessonDate  = Carbon::parse(end($lessons)['Date'])->setTimeFromTimeString($data['End_Time']);

            $this->staffRepository->updateCourseSchedule($data['CourseId'], [
                'RoomId'       => $data['RoomId'] ?? null,
                'Start_Enroll' => $data['Start_Enroll'],
                'End_Enroll'   => $data['End_Enroll'],
                'Start_Date'   => $firstLessonDate,
                'End_Date'     => $lastLessonDate,
                'Start_Time'   => $data['Start_Time'],
                'End_Time'     => $data['End_Time'],
                'CourseDays'   => $data['CourseDays'],
            ]);


            //Delete old enrollment_days
            DB::table('enrollment_days')->where('CourseId', $data['CourseId'])->delete();

            //Generate new enrollment_days
            $enrollmentDays = $this->generateEnrollmentDays(
                $data['CourseId'],
                $data['Start_Enroll'],
                $data['End_Enroll'],
                //lana
                $holidays,
                $data['Start_Date'], // أضف هذا الباراميتر الجديد
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

            if (!$course) {
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

    /*public function getTodaysSchedule()
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
    }*/

    public function getScheduleByDate($date = null)
    {
        $teacherId = auth()->user()->id;
        $targetDate = $date ?? now()->toDateString(); // إذا ما تم إرسال تاريخ، استخدم اليوم الحالي

        $lessons = $this->staffRepository->getScheduleByDay($teacherId, $targetDate);

        if ($lessons->isEmpty()) {
            return ['message' => 'You do not have any lessons on this day.', 'date' => $targetDate];
        }

        return [
            'message' => 'You have lessons scheduled on this day.',
            'date' => $targetDate,
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

    /*public function markAttendance($lessonId, $studentId)
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
    }*/

    public function markAttendance($lessonId, $studentId)
    {
        $teacherId = auth()->id(); // المعلّم الحالي

        // ➊ التأكّد أنّ الدرس يتبع هذا المعلّم
        $lesson = Lesson::where('lessons.id', $lessonId)
            ->join('courses', 'lessons.CourseId', '=', 'courses.id')
            ->where('courses.TeacherId', $teacherId)
            ->select('lessons.*')
            ->first();

        if (!$lesson) {
            return ['error' => 'Lesson not found or not assigned to you'];
        }

        // ✅ ➋ التحقّق من أن التاريخ الحالي >= تاريخ الدرس
        $today = now()->toDateString();
        if ($today < $lesson->Date) {
            return ['error' => 'You cannot mark attendance before the lesson date'];
        }

        // ➌ التحقق من أن الطالب مسجَّل بالكورس
        $isEnrolled = DB::table('enrollments')
            ->where('CourseId', $lesson->CourseId)
            ->where('StudentId', $studentId)
            ->exists();

        if (!$isEnrolled) {
            return ['error' => 'Student is not enrolled in this course'];
        }

         $isEnrolled = DB::table('enrollments')
            ->where('CourseId', $lesson->CourseId)
            ->where('StudentId', $studentId)
            ->exists();

        if (!$isEnrolled) {
            return ['error' => 'Student is not enrolled in this course'];
        }

        // ➍ التحقق من وجود سجل حضور
        $attendance = Attendance::where('LessonId', $lessonId)
            ->where('StudentId', $studentId)
            ->first();

        if ($attendance) {
            // الطالب حاضر بالفعل → حذف الحضور
            $attendance->delete();
            $this->staffRepository->updateStudentProgress($studentId, $lesson->CourseId);

            return ['success' => 'Attendance record deleted successfully for this lesson'];
        }

        // لا يوجد حضور سابق → إنشاء سجل جديد
        Attendance::create([
            'LessonId'  => $lessonId,
            'StudentId' => $studentId,
            'Bonus'     => 0,
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
