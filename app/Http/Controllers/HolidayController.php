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
            'StartDate' => 'required|date|after_or_equal:today|before_or_equal:EndDate',
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
                'AffectsClasses' => false,
            ]);

            //وتحويل التواريخ إلى كائنات Carbon لسهولة المعالجة
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
            $hasAffectedCourses = false;

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
                    if ($lessonsInHoliday->isNotEmpty()) {
                        $hasAffectedCourses = true; // ⬅️ فيه دروس تأثرت
                    }

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
                    $hasAffectedCourses = true;
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

                $holiday->update([
                    'AffectsClasses' => $hasAffectedCourses,
                ]);
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

        //نستخدم map لتطبيق منطق معين على كل إجازة: نربطها بالكورسات والدروس التي تأثرت بها
        $holidaysWithDetails = $holidays->map(function ($holiday) {
            //Get lessons affected by this holiday from backups
            $affectedLessons = DB::table('lesson_backups')
                ->where('holiday_id', $holiday->id)
                ->get(['CourseId', 'Title', 'Date']);

            $groupedByCourse = $affectedLessons->groupBy('CourseId');

            // لكل كورس متأثر، نستخرج معلوماته ونقارن بين الجدول القديم والجديد
            $affectedCourses = $groupedByCourse->map(function ($lessons, $courseId) {
                $course = Course::find($courseId);

                //Retrieve new lessons after modifying the schedule
                $newLessons = Lesson::where('CourseId', $courseId)
                    ->orderBy('Date')
                    ->get(['Title', 'Date', 'Start_Time', 'End_Time']);

                return [
                    'CourseId' => $courseId,

                    /*
                    ?->: تسمح لك بالوصول للخاصية إذا كان الكائن غير فارغ.

                     ??: تُستخدم لتوفير قيمة بديلة إذا كانت النتيجة nul

                    */
                    'CourseTitle' => $course?->Description ?? 'Unknown',

                    //حتى لا نحصل على خطأ إذا كانت $lessons فارغة
                    'OldStartDate' => optional($lessons)->min('Date'),
                    'OldEndDateBeforeHoliday' => optional($lessons)->max('Date'),
                    'NewStartDate' => optional($newLessons)->min('Date'),
                    'NewEndDate' => optional($newLessons)->max('Date'),
                    //استخراج أسماء الدروس المتأثرة pluck
                    'AffectedLessonTitles' => $lessons->pluck('Title')->unique()->values(),
                    'AffectedLessonCount' => $lessons->count(),
                    /*
                    إنشاء قائمة بجميع الدروس الجديدة بعد التعديل.

                    map(...): تحويل كل درس إلى شكل مبسط يحتوي على البيانات الأساسية.

                    values(): إعادة ترتيب الفهرسة.
                    */

                    'NewSchedule' => $newLessons->map(function ($lesson) {
                        return [
                            'Title' => $lesson->Title,
                            'Date' => $lesson->Date,
                            'StartTime' => $lesson->Start_Time,
                            'EndTime' => $lesson->End_Time,
                        ];
                        //values(): إعادة ترتيب الفهرسة (index).
                    })->values(),
                ];
            })->values();
            /*
            🔹 هذا return داخل map() على الإجازات:

            لكل إجازة نرجع:

             بياناتها العامة.

            عدد الدروس المتأثرة.

            عدد الكورسات المتأثرة.
 
            تفاصيل كل كورس  .
                 */

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

    public function deleteHoliday($id)
    {
        $holiday = Holiday::find($id);
        if (!$holiday) {
            return response()->json([
                'status' => 'error',
                'message' => 'Holiday not found',
            ], 404);
        }

        DB::transaction(function () use ($holiday) {
            $today = Carbon::today();
            $startHoliday = Carbon::parse($holiday->StartDate);
            $endHoliday = Carbon::parse($holiday->EndDate);

            // 1) حالة: الإجازة كلها ماضية -> حذف فوري لكل ما يتعلق بها
            if ($endHoliday->lt($today)) {
                DB::table('lesson_backups')->where('holiday_id', $holiday->id)->delete();
                ScheduleEnrollmentBackup::where('holiday_id', $holiday->id)->delete();
                $holiday->delete();
                return;
            }

            // 2) الإجازة مستقبلية أو جزء منها مستقبل -> نلتقط النسخ الاحتياطية المتعلقة بها
            $backups = DB::table('lesson_backups')
                ->where('holiday_id', $holiday->id)
                ->orderBy('Date')
                ->get();

            // إن لم توجد نسخ احتياطية -> نحذف الإجازة وننهي
            if ($backups->isEmpty()) {
                ScheduleEnrollmentBackup::where('holiday_id', $holiday->id)->delete();
                $holiday->delete();
                return;
            }

            // 3) نحسب أقدم تاريخ متأثر لكل كورس (anchor per course)
            $grouped = $backups->groupBy('CourseId');
            $courseAnchors = []; // [courseId => Carbon(earliestBackupDate)]
            foreach ($grouped as $courseId => $rows) {
                $courseAnchors[$courseId] = Carbon::parse($rows->min('Date'));
            }

            // 4) نحفظ سجلات الـenroll-backup المتعلقة بهذه الإجازة (قبل حذفها)
            $enrollBackups = ScheduleEnrollmentBackup::where('holiday_id', $holiday->id)
                ->get()
                ->keyBy('schedule_id');

            // 5) نحذف الإجازة الآن (حسب المطلوب)
            $holidayId = $holiday->id;
            $holiday->delete();

            // 6) نعتبر باقي الإجازات المتاحة كـ "إجازات أخرى" لتجنّبها أثناء إعادة الجدولة
            $otherHolidays = Holiday::all();

            // 7) لكل كورس متأثر: نعيد جدولة الدروس المستقبلية فقط
            foreach ($courseAnchors as $courseId => $earliestBackupDate) {
                $course = Course::find($courseId);
                if (!$course) continue;

                $schedule = CourseSchedule::where('CourseId', $courseId)->first();
                if (!$schedule) continue;

                // خصائص الجدولة
                $courseDays = $this->normalizeCourseDays($schedule->CourseDays ?? []);
                $roomId     = $schedule->RoomId;
                $teacherId  = $course->TeacherId; // ملاحظة: TeacherId في جدول courses

                // أول درس حالي إن وجد
                $firstLesson = Lesson::where('CourseId', $courseId)->orderBy('Date')->first();
                $firstLessonDate = $firstLesson ? Carbon::parse($firstLesson->Date) : null;

                // قاعدة اختيار الـanchor كما طلبت:
                // إذا كان earliestBackup قبل firstLessonDate -> نبدأ من earliestBackup
                // وإلا نبدأ من firstLessonDate
                if ($firstLessonDate) {
                    $startAnchor = $earliestBackupDate->lt($firstLessonDate) ? $earliestBackupDate->copy() : $firstLessonDate->copy();
                } else {
                    $startAnchor = $earliestBackupDate->copy();
                }

                // لكن نطبق التغيير *فقط* على الدروس المستقبلية (اليوم لا يتغير)
                $cursor = $startAnchor->gt($today) ? $startAnchor->copy() : $today->copy()->addDay();

                // جلب كل الدروس ذات التاريخ > اليوم (future lessons)
                $futureLessons = Lesson::where('CourseId', $courseId)
                    ->whereDate('Date', '>', $today->toDateString())
                    ->orderBy('Date')
                    ->orderBy('Start_Time')
                    ->get();

                if ($futureLessons->isEmpty()) continue;

                foreach ($futureLessons as $lesson) {
                    // نبحث عن أول تاريخ صالح يبدأ من $cursor
                    $target = $this->findNextValidDate(
                        $cursor->copy(),
                        $courseDays,
                        $otherHolidays,
                        $roomId,
                        $teacherId,
                        $lesson->Start_Time,
                        $lesson->End_Time,
                        $lesson->id
                    );

                    // fallback: محاولة يوم بيوم لغاية سنة إذا لم نعثر
                    if (!$target) {
                        $fallback = $cursor->copy();
                        $attempts = 0;
                        while ($attempts < 365) {
                            if (
                                $this->isAllowedCourseDay($fallback, $courseDays)
                                && !$this->isHolidayDate($fallback, $otherHolidays)
                                && !$this->hasSlotConflict($fallback->toDateString(), $lesson->Start_Time, $lesson->End_Time, $roomId, $teacherId, $lesson->id)
                            ) {
                                $target = $fallback->copy();
                                break;
                            }
                            $fallback->addDay();
                            $attempts++;
                        }
                        if (!$target) {
                            $target = $cursor->copy()->addDays(365); // حالة نادرة جداً
                        }
                    }

                    // ننقل الدرس للتاريخ الجديد
                    $lesson->update([
                        'Date' => $target->toDateString(),
                        'updated_at' => now(),
                    ]);

                    // نحرّك المؤشر لليوم التالي لضمان التسلسل
                    $cursor = $target->copy()->addDay();
                }

                // 8) تحديث Start_Date و End_Date في CourseSchedule
                $newFirstLesson = Lesson::where('CourseId', $courseId)->orderBy('Date')->first();
                $newLastLesson  = Lesson::where('CourseId', $courseId)->orderByDesc('Date')->first();
                if ($newFirstLesson && $newLastLesson) {
                    $schedule->update([
                        'Start_Date' => $newFirstLesson->Date,
                        'End_Date'   => $newLastLesson->Date,
                    ]);
                }

                // 9) إعادة جدولة فترة التسجيل إن وُجد enroll backup لهذا الـschedule
                $enrollBackup = $enrollBackups->get($schedule->id);
                if ($enrollBackup) {
                    $this->restoreAndRescheduleEnrollment($schedule, $enrollBackup, $otherHolidays, $newFirstLesson);
                    // احذف backup بعد استعادته (اختياري) — نفعّل الحذف هنا
                    ScheduleEnrollmentBackup::where('id', $enrollBackup->id)->delete();
                }
            }

            // 10) تنظيف: حذف lesson_backups الخاصة بهذه الإجازة (تم حذف الإجازة)
            DB::table('lesson_backups')->where('holiday_id', $holidayId)->delete();
        }); // end transaction

        return response()->json([
            'status' => 'success',
            'message' => 'Holiday deleted and affected future lessons rescheduled; enrollment updated when needed.',
        ], 200);
    }

    private function normalizeCourseDays($courseDays)
    {
        if (is_array($courseDays)) return $courseDays;
        if (is_string($courseDays)) {
            $decoded = json_decode($courseDays, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
            return array_values(array_filter(array_map('trim', explode(',', $courseDays))));
        }
        return [];
    }

    private function isAllowedCourseDay(Carbon $date, array $courseDays): bool
    {
        if (empty($courseDays)) return true;
        return in_array($date->format('D'), $courseDays, true);
    }

    private function isHolidayDate(Carbon $date, $otherHolidays): bool
    {
        foreach ($otherHolidays as $h) {
            if ($date->between(Carbon::parse($h->StartDate), Carbon::parse($h->EndDate))) {
                return true;
            }
        }
        return false;
    }

    /**
     * فحص التعارض: هل هناك درس بنفس التاريخ/الوقت ويشغل نفس الغرفة أو نفس المدرّس؟
     * نتجاهل درس محدد عبر $ignoreLessonId.
     * لاحظ: TeacherId موجود في جدول courses (alias c).
     */
    private function hasSlotConflict(string $date, string $startTime, string $endTime, $roomId, $teacherId, $ignoreLessonId = null): bool
    {
        $q = DB::table('lessons as l')
            ->join('courses as c', 'c.id', '=', 'l.CourseId')
            ->join('course_schedules as cs', 'cs.CourseId', '=', 'c.id')
            ->whereDate('l.Date', $date)
            ->where('l.Start_Time', $startTime)
            ->where('l.End_Time', $endTime)
            ->where(function ($q) use ($roomId, $teacherId) {
                $q->where('cs.RoomId', $roomId)
                    ->orWhere('c.TeacherId', $teacherId);
            });

        if ($ignoreLessonId) {
            $q->where('l.id', '!=', $ignoreLessonId);
        }

        return $q->exists();
    }

    /**
     * العثور على أول تاريخ صالح بدءًا من $startFrom (شامل) يوافق أيام الكورس، ليس في إجازة أخرى، ولا يتعارض.
     */
    private function findNextValidDate(
        Carbon $startFrom,
        array $courseDays,
        $otherHolidays,
        $roomId,
        $teacherId,
        string $startTime,
        string $endTime,
        $ignoreLessonId = null
    ): ?Carbon {
        $cursor = $startFrom->copy();
        $maxAttempts = 365 * 2;
        for ($i = 0; $i < $maxAttempts; $i++) {
            if (
                $this->isAllowedCourseDay($cursor, $courseDays)
                && !$this->isHolidayDate($cursor, $otherHolidays)
                && !$this->hasSlotConflict($cursor->toDateString(), $startTime, $endTime, $roomId, $teacherId, $ignoreLessonId)
            ) {
                return $cursor;
            }
            $cursor->addDay();
        }
        return null;
    }

    /**
     * استعادة وضبط فترة التسجيل بنَفْس منطق addHoliday
     */
    private function restoreAndRescheduleEnrollment($schedule, $enrollBackup, $otherHolidays, $newFirstLesson = null)
    {
        $originalStart = Carbon::parse($enrollBackup->original_start_enroll);
        $originalEnd = Carbon::parse($enrollBackup->original_end_enroll);
        $originalDuration = $originalStart->diffInDays($originalEnd) + 1;

        $firstLessonDateAfter = $newFirstLesson ? Carbon::parse($newFirstLesson->Date) : Carbon::parse($schedule->Start_Date);
        $targetEnd = $firstLessonDateAfter->copy()->subDay();

        $cursorEnroll = $originalStart->copy();
        $newEnrollDates = collect();
        $attempts = 0;
        $maxAttempts = 365 * 2;

        while ($attempts < $maxAttempts && $cursorEnroll->lte($targetEnd) && $newEnrollDates->count() < $originalDuration) {
            if (!$this->isHolidayDate($cursorEnroll, $otherHolidays)) {
                $newEnrollDates->push($cursorEnroll->copy());
            }
            $cursorEnroll->addDay();
            $attempts++;
        }

        $backCursor = $targetEnd->copy();
        while ($newEnrollDates->count() < $originalDuration && $attempts < $maxAttempts) {
            if (!$this->isHolidayDate($backCursor, $otherHolidays)) {
                $newEnrollDates->prepend($backCursor->copy());
            }
            $backCursor->subDay();
            $attempts++;
        }

        if ($newEnrollDates->isNotEmpty()) {
            $schedule->update([
                'Start_Enroll' => $newEnrollDates->first()->toDateString(),
                'End_Enroll'   => $newEnrollDates->last()->toDateString(),
            ]);
        }
    }



    /*ok
    public function deleteHoliday($id)
    {
        $holiday = Holiday::find($id);
        if (!$holiday) {
            return response()->json([
                'status' => 'error',
                'message' => 'Holiday not found',
            ], 404);
        }

        DB::transaction(function () use ($holiday) {
            $today = Carbon::today();
            $startHoliday = Carbon::parse($holiday->StartDate);
            $endHoliday = Carbon::parse($holiday->EndDate);

            // ====== 1) إذا كانت الإجازة كلها ماضية => حذف فوري لكل شيء مرتبط ======
            if ($endHoliday->lt($today)) {
                DB::table('lesson_backups')->where('holiday_id', $holiday->id)->delete();
                ScheduleEnrollmentBackup::where('holiday_id', $holiday->id)->delete();
                $holiday->delete();

                return;
            }

            // ====== 2) الإجازة مستقبلية أو جزء منها مستقبل => نجمع المتأثرين من lesson_backups ======
            // نجمع جميع النسخ الاحتياطية المرتبطة بهذه الإجازة
            $backups = DB::table('lesson_backups')
                ->where('holiday_id', $holiday->id)
                ->orderBy('Date')
                ->get();

            // لو لا توجد نسخ احتياطية => نحذف الإجازة وننهي
            if ($backups->isEmpty()) {
                ScheduleEnrollmentBackup::where('holiday_id', $holiday->id)->delete();
                $holiday->delete();
                return;
            }

            // نجمع أقدم تاريخ متأثر لكل كورس
            $grouped = $backups->groupBy('CourseId');
            $courseAnchors = []; // [courseId => earliestBackupDate(Carbon)]
            foreach ($grouped as $courseId => $rows) {
                $courseAnchors[$courseId] = Carbon::parse($rows->min('Date'));
            }

            // جمع سجلات ScheduleEnrollmentBackup (لنستخدمها لاحقاً)
            $enrollBackups = ScheduleEnrollmentBackup::where('holiday_id', $holiday->id)
                ->get()
                ->keyBy('schedule_id'); // لسهولة الوصول حسب schedule_id

            // الآن نحذف الإجازة نفسها (حسب المطلوب)
            $holidayId = $holiday->id;
            $holiday->delete();

            // نحضر باقي الإجازات المتاحة كـ "إجازات أخرى" لتجنّبها أثناء إعادة الجدولة
            $otherHolidays = Holiday::all();

            // ====== 3) لكل كورس متأثر: نعيد جدولة الدروس المستقبلية فقط ======
            foreach ($courseAnchors as $courseId => $earliestBackupDate) {
                $course = Course::find($courseId);
                if (!$course) continue;

                // نحصل على schedule الخاص بالكورس
                $schedule = CourseSchedule::where('CourseId', $courseId)->first();
                if (!$schedule) continue;

                // CourseDays، غرفة، ومعرف المدرّس من جدول courses (TeacherId موجود في courses)
                $courseDays = $this->normalizeCourseDays($schedule->CourseDays ?? []);
                $roomId = $schedule->RoomId;
                $teacherId = $course->TeacherId;

                // تاريخ أول درس حالي (من جدول lessons) إن وجد
                $firstLesson = Lesson::where('CourseId', $courseId)->orderBy('Date')->first();
                $firstLessonDate = $firstLesson ? Carbon::parse($firstLesson->Date) : null;

                // نقرر نقطة البداية لإعادة الجدولة حسب منطقك:
                // إذا كان تاريخ أقدم نسخة احتياطية قبل أول درس حالي => نبدأ من أقدم نسخة احتياطية
                // وإلا نبدأ من تاريخ أول درس حالي
                if ($firstLessonDate) {
                    $startAnchor = $earliestBackupDate->lt($firstLessonDate) ? $earliestBackupDate->copy() : $firstLessonDate->copy();
                } else {
                    // لو لا يوجد أول درس (نادر) نبدأ من أقدم نسخة احتياطية
                    $startAnchor = $earliestBackupDate->copy();
                }

                // لكن **نطبق التعديل فقط على الدروس المستقبلية** (لا نغيّر دروس اليوم)
                // إذن المؤشر الفعلي هو:
                $cursor = $startAnchor->gt($today) ? $startAnchor->copy() : $today->copy()->addDay();

                // نجيب كل الدروس التي تاريخها بعد اليوم (future lessons) ونرتّبها
                $futureLessons = Lesson::where('CourseId', $courseId)
                    ->whereDate('Date', '>', $today->toDateString())
                    ->orderBy('Date')
                    ->orderBy('Start_Time')
                    ->get();

                if ($futureLessons->isEmpty()) {
                    // لا شيء لإعادة جدولته
                    continue;
                }

                // نستخدم usedDates للتأكد من أننا لا نضع أكثر من درس لنفس التاريخ داخل نفس الكورس (اختياري)
                $usedDates = [];

                foreach ($futureLessons as $lesson) {
                    // نبحث أول تاريخ صالح بدءاً من $cursor
                    $target = $this->findNextValidDate(
                        $cursor->copy(),
                        $courseDays,
                        $otherHolidays,
                        $roomId,
                        $teacherId,
                        $lesson->Start_Time,
                        $lesson->End_Time,
                        $lesson->id
                    );

                    // fallback: إن لم نجد خلال حد المحاولات، نستمر يومًا بيوم حتى نجده أو نضعه بعد سنة كحل أخير
                    if (!$target) {
                        $fallback = $cursor->copy();
                        $attempts = 0;
                        while ($attempts < 365) {
                            if (
                                $this->isAllowedCourseDay($fallback, $courseDays)
                                && !$this->isHolidayDate($fallback, $otherHolidays)
                                && !$this->hasSlotConflict($fallback->toDateString(), $lesson->Start_Time, $lesson->End_Time, $roomId, $teacherId, $lesson->id)
                            ) {
                                $target = $fallback->copy();
                                break;
                            }
                            $fallback->addDay();
                            $attempts++;
                        }
                        if (!$target) {
                            $target = $cursor->copy()->addDays(365); // حالة نادرة جدًا
                        }
                    }

                    // اضبط الدرس للتاريخ الجديد
                    $lesson->update([
                        'Date' => $target->toDateString(),
                        'updated_at' => now(),
                    ]);

                    $usedDates[] = $target->toDateString();
                    // حرّك المؤشر لليوم التالي لتجنّب وضع درسين في نفس اليوم داخل نفس الكورس (إن أردت منطقًا آخر يمكنك تغييره)
                    $cursor = $target->copy()->addDay();
                }

                // ====== 4) تحديث Start_Date و End_Date للكورس بعد إعادة الجدولة ======
                $newFirstLesson = Lesson::where('CourseId', $courseId)->orderBy('Date')->first();
                $newLastLesson = Lesson::where('CourseId', $courseId)->orderByDesc('Date')->first();

                if ($newFirstLesson && $newLastLesson) {
                    $schedule->update([
                        'Start_Date' => $newFirstLesson->Date,
                        'End_Date' => $newLastLesson->Date,
                    ]);
                }

                // ====== 5) إعادة جدولة فترة التسجيل (إن وُجدت نسخة احتياطية لها) بنفس منطق addHoliday ======
                // نبحث سجل النسخة الاحتياطية الخاص بهذا schedule (لو كان موجودًا قبل الحذف)
                $enrollBackup = $enrollBackups->get($schedule->id);
                if ($enrollBackup) {
                    $originalStart = Carbon::parse($enrollBackup->original_start_enroll);
                    $originalEnd = Carbon::parse($enrollBackup->original_end_enroll);
                    $originalDuration = $originalStart->diffInDays($originalEnd) + 1;

                    // نريد وضع نافذة التسجيل بحيث تنتهي قبل أول درس (بـ1 يوم) ونحاول الحفاظ على نفس المدة متجاهلين الإجازات الأخرى
                    $firstLessonDateAfter = $newFirstLesson ? Carbon::parse($newFirstLesson->Date) : Carbon::parse($schedule->Start_Date);
                    $targetEnd = $firstLessonDateAfter->copy()->subDay();

                    $cursorEnroll = $originalStart->copy();
                    $newEnrollDates = collect();
                    $attempts = 0;
                    $maxAttempts = 365 * 2;

                    while ($attempts < $maxAttempts && $cursorEnroll->lte($targetEnd) && $newEnrollDates->count() < $originalDuration) {
                        if (!$this->isHolidayDate($cursorEnroll, $otherHolidays)) {
                            $newEnrollDates->push($cursorEnroll->copy());
                        }
                        $cursorEnroll->addDay();
                        $attempts++;
                    }

                    // إذا لم نكمل المدة، نملأ بالعكس من targetEnd للخلف
                    $backCursor = $targetEnd->copy();
                    while ($newEnrollDates->count() < $originalDuration && $attempts < $maxAttempts) {
                        if (!$this->isHolidayDate($backCursor, $otherHolidays)) {
                            $newEnrollDates->prepend($backCursor->copy());
                        }
                        $backCursor->subDay();
                        $attempts++;
                    }

                    if ($newEnrollDates->isNotEmpty()) {
                        $schedule->update([
                            'Start_Enroll' => $newEnrollDates->first()->toDateString(),
                            'End_Enroll' => $newEnrollDates->last()->toDateString(),
                        ]);
                    }
                    // بعد إعادة الضبط يمكنك حذف سجل الـenroll backup إن أردت:
                    ScheduleEnrollmentBackup::where('id', $enrollBackup->id)->delete();
                }

                // انتهينا من هذا الكورس
                // يمكنك حذف lesson_backups الخاصة بهذا الكورس+holiday إن رغبت (نحن حذفنا الإجازة أصلاً)
            }

            // ====== 6) تنظيف: حذف أي lesson_backups المتبقية الخاصة بهذه الإجازة (اختياري) ======
            DB::table('lesson_backups')->where('holiday_id', $holidayId)->delete();
        }); // نهاية المعاملة

        return response()->json([
            'status' => 'success',
            'message' => 'Holiday deleted and affected courses rescheduled (future lessons). Enrollment updated when needed.',
        ], 200);
    }

    private function normalizeCourseDays($courseDays)
    {
        if (is_array($courseDays)) return $courseDays;
        if (is_string($courseDays)) {
            $decoded = json_decode($courseDays, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
            return array_values(array_filter(array_map('trim', explode(',', $courseDays))));
        }
        return [];
    }

    private function isAllowedCourseDay(Carbon $date, array $courseDays): bool
    {
        if (empty($courseDays)) return true;
        return in_array($date->format('D'), $courseDays, true);
    }

    private function isHolidayDate(Carbon $date, $otherHolidays): bool
    {
        foreach ($otherHolidays as $h) {
            if ($date->between(Carbon::parse($h->StartDate), Carbon::parse($h->EndDate))) {
                return true;
            }
        }
        return false;
    }

    private function hasSlotConflict(string $date, string $startTime, string $endTime, $roomId, $teacherId, $ignoreLessonId = null): bool
    {
        $q = DB::table('lessons as l')
            ->join('courses as c', 'c.id', '=', 'l.CourseId')
            ->join('course_schedules as cs', 'cs.CourseId', '=', 'c.id')
            ->whereDate('l.Date', $date)
            ->where('l.Start_Time', $startTime)
            ->where('l.End_Time', $endTime)
            ->where(function ($q) use ($roomId, $teacherId) {
                $q->where('cs.RoomId', $roomId)
                    ->orWhere('c.TeacherId', $teacherId); // <-- انتبه: TeacherId في جدول courses (alias c)
            });

        if ($ignoreLessonId) {
            $q->where('l.id', '!=', $ignoreLessonId);
        }

        return $q->exists();
    }

    private function findNextValidDate(
        Carbon $startFrom,
        array $courseDays,
        $otherHolidays,
        $roomId,
        $teacherId,
        string $startTime,
        string $endTime,
        $ignoreLessonId = null
    ): ?Carbon {
        $cursor = $startFrom->copy();
        $maxAttempts = 365 * 2;

        for ($i = 0; $i < $maxAttempts; $i++) {
            if (
                $this->isAllowedCourseDay($cursor, $courseDays) &&
                !$this->isHolidayDate($cursor, $otherHolidays) &&
                !$this->hasSlotConflict($cursor->toDateString(), $startTime, $endTime, $roomId, $teacherId, $ignoreLessonId)
            ) {
                return $cursor;
            }
            $cursor->addDay();
        }

        return null; // لم نجد ضمن حد معقول
    }ok*/



    /*
    public function deleteHoliday($id)
    {
        $holiday = Holiday::find($id);
        if (!$holiday) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Holiday not found',
            ], 404);
        }

        DB::transaction(function () use ($holiday) {

            $today     = Carbon::today();
            $startDate = Carbon::parse($holiday->StartDate);
            $endDate   = Carbon::parse($holiday->EndDate);

            // ========== الحالة (1): الإجازة كلها ماضية ==========
            if ($endDate->lt($today)) {

                // امسح كل ما يتعلق بهذه الإجازة
                DB::table('lesson_backups')->where('holiday_id', $holiday->id)->delete();
                ScheduleEnrollmentBackup::where('holiday_id', $holiday->id)->delete();

                // حذف الإجازة
                $holiday->delete();

                return; // لا إعادة جدولة مطلوبة
            }

            // ========== الحالة (2): الإجازة مستقبلية أو مختلطة ==========
            // 1) جمع الكورسات المتأثرة من جدول النسخ الاحتياطية (أي كورس لديه نسخ احتياطية تخص هذه الإجازة)
            $affectedCourseIds = DB::table('lesson_backups')
                ->where('holiday_id', $holiday->id)
                ->pluck('CourseId')
                ->unique()
                ->values();

            // قد لا يوجد نسخ احتياطية (لا كورسات تأثرت) — في هذه الحالة فقط نحذف الإجازة وننهي
            // لكن إن وجد كورسات متأثرة فسنعيد جدولتها كما هو مطلوب.

            // 2) حذف الإجازة أولاً (ومن ثم سنقرأ بقية الإجازات كـ "إجازات أخرى")
            $holidayId = $holiday->id;
            $holiday->delete();

            // تنظيف النسخ الاحتياطية والسجل الاحتياطي للتسجيل لهذه الإجازة (حتى لا تبقى بيانات يتيمة)
            DB::table('lesson_backups')->where('holiday_id', $holidayId)->delete();
            ScheduleEnrollmentBackup::where('holiday_id', $holidayId)->delete();

            // جميع الإجازات الأخرى (بعد حذف هذه)
            $otherHolidays = Holiday::all();

            // 3) إعادة جدولة دروس الكورسات المتأثرة ابتداءً من بعد اليوم فقط (دروس اليوم تبقى كما هي)
            foreach ($affectedCourseIds as $courseId) {
                $schedule = CourseSchedule::where('CourseId', $courseId)->first();
                if (!$schedule) {
                    continue;
                }

                $courseDays = $this->normalizeCourseDays($schedule->CourseDays);
                $roomId     = $schedule->RoomId;
                $teacherId  = $schedule->TeacherId;

                // دروس الغد وما بعده فقط (دروس اليوم ثابتة)
                $futureLessons = Lesson::where('CourseId', $courseId)
                    ->whereDate('Date', '>', $today->toDateString())
                    ->orderBy('Date')
                    ->orderBy('Start_Time')
                    ->get();

                // مؤشّر إعادة الجدولة يبدأ من الغد
                $cursor = $today->copy()->addDay();

                foreach ($futureLessons as $lsn) {

                    // ابحث أول تاريخ صالح يطابق أيام الكورس ويخلو من الإجازات/التعارضات
                    $target = $this->findNextValidDate(
                        $cursor->copy(),
                        $courseDays,
                        $otherHolidays,
                        $roomId,
                        $teacherId,
                        $lsn->Start_Time,
                        $lsn->End_Time,
                        $lsn->id // تجاهل نفس الدرس
                    );

                    // إن لم نجد ضمن حد منطقي، ادفع يومًا بيوم حتى تجد خانة
                    if (!$target) {
                        $fallback = $cursor->copy();
                        $attempts = 0;
                        while ($attempts < 365) {
                            if (
                                $this->isAllowedCourseDay($fallback, $courseDays) &&
                                !$this->isHolidayDate($fallback, $otherHolidays) &&
                                !$this->hasSlotConflict(
                                    $fallback->toDateString(),
                                    $lsn->Start_Time,
                                    $lsn->End_Time,
                                    $roomId,
                                    $teacherId,
                                    $lsn->id
                                )
                            ) {
                                $target = $fallback->copy();
                                break;
                            }
                            $fallback->addDay();
                            $attempts++;
                        }
                        if (!$target) {
                            // كحل نهائي: ضع الدرس بعد سنة (حالة شبه مستحيلة مع الجداول الواقعية)
                            $target = $cursor->copy()->addDays(365);
                        }
                    }

                    // انقل الدرس للتاريخ الجديد
                    $lsn->update([
                        'Date'       => $target->toDateString(),
                        'updated_at' => now(),
                    ]);

                    // حرّك المؤشر لليوم التالي لضمان تسلسل الدروس بلا تزاحم داخل نفس الكورس
                    $cursor = $target->copy()->addDay();
                }

                // 4) تحديث Start_Date و End_Date بعد الجدولة
                $newFirstLesson = Lesson::where('CourseId', $courseId)->orderBy('Date')->first();
                $newLastLesson  = Lesson::where('CourseId', $courseId)->orderByDesc('Date')->first();

                if ($newFirstLesson && $newLastLesson) {
                    $schedule->update([
                        'Start_Date' => $newFirstLesson->Date,
                        'End_Date'   => $newLastLesson->Date,
                    ]);
                }

                // 5) إعادة جدولة فترة التسجيل "عند الحاجة" بمنطق addHoliday
                // معيار "الضرورة":
                // - إذا كانت فترة التسجيل الحالية تتقاطع مع أي إجازة من الإجازات الأخرى
                // - أو إن كانت تنتهي بعد/مع أول درس (يجب أن تسبق أول درس)
                $firstLessonOverall = $newFirstLesson ? Carbon::parse($newFirstLesson->Date) : ($schedule->Start_Date ? Carbon::parse($schedule->Start_Date) : null);
                if ($firstLessonOverall) {
                    $startEnroll = $schedule->Start_Enroll ? Carbon::parse($schedule->Start_Enroll) : null;
                    $endEnroll   = $schedule->End_Enroll ? Carbon::parse($schedule->End_Enroll) : null;

                    $enrollmentNeedsFix = false;

                    if ($startEnroll && $endEnroll) {
                        // هل تتقاطع مع أي إجازة؟
                        $rangeHasHoliday = false;
                        foreach ($otherHolidays as $h) {
                            $hs = Carbon::parse($h->StartDate);
                            $he = Carbon::parse($h->EndDate);
                            if (!($endEnroll->lt($hs) || $startEnroll->gt($he))) {
                                $rangeHasHoliday = true;
                                break;
                            }
                        }
                        // هل تنتهي بعد/مع أول درس؟
                        if ($endEnroll->gte($firstLessonOverall)) {
                            $enrollmentNeedsFix = true;
                        }
                        if ($rangeHasHoliday) {
                            $enrollmentNeedsFix = true;
                        }
                    }

                    if ($enrollmentNeedsFix) {
                        // استخدم عدد الأيام الأصلي من جدول enrollment_days لو متوفر (نفس منطق addHoliday)
                        $originalEnrollDays = DB::table('enrollment_days')
                            ->where('CourseId', $courseId)
                            ->orderBy('Enroll_Date')
                            ->pluck('Enroll_Date')
                            ->map(fn($d) => Carbon::parse($d));

                        $originalDuration = $originalEnrollDays->isNotEmpty()
                            ? $originalEnrollDays->count()
                            : (($startEnroll && $endEnroll) ? ($startEnroll->diffInDays($endEnroll) + 1) : 0);

                        // نبني نافذة تسجيل قبل أول درس وتنتهي قبله بيوم واحد، متجنبة الإجازات الأخرى
                        $targetEnd   = $firstLessonOverall->copy()->subDay();
                        $newDates    = collect();
                        $cursorEnroll = $today->copy(); // نبدأ من اليوم كي لا نضع تسجيل في الماضي قدر الإمكان
                        $attempts     = 0;
                        $maxAttempts = 365 * 2;

                        while (
                            $originalDuration > 0 &&
                            $cursorEnroll->lte($targetEnd) &&
                            $attempts < $maxAttempts
                        ) {
                            if (!$this->isHolidayDate($cursorEnroll, $otherHolidays)) {
                                $newDates->push($cursorEnroll->copy());
                            }
                            if ($newDates->count() >= $originalDuration) {
                                break;
                            }
                            $cursorEnroll->addDay();
                            $attempts++;
                        }

                        // إن ما قدرنا نوصل للعدد كامل، نُكمل بالسير للخلف قبل targetEnd
                        $backCursor = $targetEnd->copy();
                        while ($newDates->count() < $originalDuration && $attempts < $maxAttempts) {
                            if (!$this->isHolidayDate($backCursor, $otherHolidays)) {
                                $newDates->prepend($backCursor->copy());
                            }
                            $backCursor->subDay();
                            $attempts++;
                        }

                        if ($newDates->isNotEmpty()) {
                            $schedule->update([
                                'Start_Enroll' => $newDates->first()->toDateString(),
                                'End_Enroll'   => $newDates->last()->toDateString(),
                            ]);
                        } else {
                            // لو فشلنا كليًا، خلّيه كما هو دون تغيير
                        }
                    }
                }
            }
        });

        return response()->json([
            'status'  => 'success',
            'message' => 'Holiday deleted. Affected courses rescheduled (future-only), enrollment adjusted if needed.',
        ]);
    }

    private function normalizeCourseDays($courseDays)
    {
        if (is_array($courseDays)) return $courseDays;

        if (is_string($courseDays)) {
            $decoded = json_decode($courseDays, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
            return array_values(array_filter(array_map('trim', explode(',', $courseDays))));
        }
        return [];
    }

    private function isAllowedCourseDay(Carbon $date, array $courseDays): bool
    {
        if (empty($courseDays)) return true;
        return in_array($date->format('D'), $courseDays, true);
    }

    private function isHolidayDate(Carbon $date, $otherHolidays): bool
    {
        foreach ($otherHolidays as $h) {
            if ($date->between(Carbon::parse($h->StartDate), Carbon::parse($h->EndDate))) {
                return true;
            }
        }
        return false;
    }

    private function hasSlotConflict(string $date, string $startTime, string $endTime, $roomId, $teacherId, $ignoreLessonId = null): bool
    {
        $q = DB::table('lessons as l')
            ->join('courses as c', 'c.id', '=', 'l.CourseId')
            ->join('course_schedules as cs', 'cs.CourseId', '=', 'c.id')
            ->whereDate('l.Date', $date)
            ->where('l.Start_Time', $startTime)
            ->where('l.End_Time', $endTime)
            ->where(function ($q) use ($roomId, $teacherId) {
                $q->where('cs.RoomId', $roomId)
                    ->orWhere('c.TeacherId', $teacherId);
            });

        if ($ignoreLessonId) {
            $q->where('l.id', '!=', $ignoreLessonId);
        }

        return $q->exists();
    }

    private function findNextValidDate(
        Carbon $startFrom,
        array $courseDays,
        $otherHolidays,
        $roomId,
        $teacherId,
        string $startTime,
        string $endTime,
        $ignoreLessonId = null
    ): ?Carbon {
        $cursor = $startFrom->copy();
        $maxAttempts = 365 * 2;

        for ($i = 0; $i < $maxAttempts; $i++) {
            if (
                $this->isAllowedCourseDay($cursor, $courseDays) &&
                !$this->isHolidayDate($cursor, $otherHolidays) &&
                !$this->hasSlotConflict(
                    $cursor->toDateString(),
                    $startTime,
                    $endTime,
                    $roomId,
                    $teacherId,
                    $ignoreLessonId
                )
            ) {
                return $cursor;
            }
            $cursor->addDay();
        }
        return null;
    }*/


    //////////////////////////////////////////////////////////////////////////


    /**الدالة تستخدم return داخل الحلقات (map) لبناء 
     * هيكل بيانات مركّب، ثم تستخدم return واحد في النهاية لإرجاع كل شيء كـ JSON. */

    /**الموضع	السبب
return داخل map() الخاص بالكورسات	لإرجاع بيانات كل كورس داخل كل إجازة.
return داخل map() الخاص بالإجازات	لإرجاع بيانات كل إجازة مع الكورسات المتأثرة بها.
return النهائي في نهاية الدالة	لإرجاع النتيجة الكاملة على شكل استجابة JSON واحدة */

    //////////////////////////////////////////////////////////////////////////////////////////////////////////////
    /*
    public function deleteHoliday($id)
    {
        $holiday = Holiday::find($id);
        if (!$holiday) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Holiday not found'
            ], 404);
        }

        DB::transaction(function () use ($holiday) {

            $today        = Carbon::today();
            $restoreStart = Carbon::parse($holiday->StartDate)->greaterThan($today) ? Carbon::parse($holiday->StartDate) : $today;
            $restoreEnd   = Carbon::parse($holiday->EndDate);

            // لو الإجازة كلها ماضية، لا يوجد شيء لنسترجعه لكن سنحذف السجل.
            if ($restoreStart->gt($restoreEnd)) {
                $holiday->delete();
                return;
            }

            // جميع الإجازات الأخرى (لا نعتبر الإجازة الحالية)
            $otherHolidays = Holiday::where('id', '!=', $holiday->id)->get();

            // 1) جلب النسخ الاحتياطية ضمن النطاق
            $backups = DB::table('lesson_backups')
                ->where('holiday_id', $holiday->id)
                ->whereDate('Date', '>=', $restoreStart->toDateString())
                ->whereDate('Date', '<=', $restoreEnd->toDateString())
                ->orderBy('Date')
                ->get()
                ->groupBy('CourseId');

            foreach ($backups as $courseId => $courseBackups) {
                $schedule = CourseSchedule::where('CourseId', $courseId)->first();
                if (!$schedule) {
                    continue;
                }

                // تحويل CourseDays إلى مصفوفة أيام مثل ['Mon','Wed','Fri']
                $courseDays = $this->normalizeCourseDays($schedule->CourseDays);
                $roomId     = $schedule->RoomId;
                $teacherId  = $schedule->TeacherId;

                // أقل تاريخ مُستعاد لهذا الكورس
                $minRestoredDate = Carbon::parse($courseBackups->min('Date'));

                // 2) استرجاع كل درس لوقته الأصلي (أولوية للاسترجاع، وندفع المتعارضين للأمام)
                foreach ($courseBackups as $bkp) {
                    $targetDate  = Carbon::parse($bkp->Date)->toDateString();
                    $startTime   = $bkp->Start_Time;
                    $endTime     = $bkp->End_Time;
                    $title       = $bkp->Title;

                    // قبل التثبيت: حرّر هذا الحيز بدفع أي دروس أخرى متعارضة للأمام
                    $this->pushAwayConflicts(
                        $targetDate,
                        $startTime,
                        $endTime,
                        $roomId,
                        $teacherId,
                        $otherHolidays
                    );

                    // إن وُجد درس بنفس العنوان داخل الكورس -> ننقله لموعد النسخة الاحتياطية
                    $existingSameTitle = Lesson::where('CourseId', $courseId)
                        ->where('Title', $title)
                        ->first();

                    if ($existingSameTitle) {
                        // نقل الدرس لوقته الأصلي
                        $existingSameTitle->update([
                            'Date'       => $targetDate,
                            'Start_Time' => $startTime,
                            'End_Time'   => $endTime,
                            'updated_at' => now(),
                        ]);
                    } else {
                        // إدراج الدرس من النسخة الاحتياطية
                        Lesson::create([
                            'CourseId'   => $courseId,
                            'Title'      => $title,
                            'Date'       => $targetDate,
                            'Start_Time' => $startTime,
                            'End_Time'   => $endTime,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    // لو صار لدينا تكرار Title (نادرًا)، نحذف النسخة الأحدث خارج التاريخ الأصلي
                    $dupes = Lesson::where('CourseId', $courseId)
                        ->where('Title', $title)
                        ->orderBy('Date')
                        ->get();

                    if ($dupes->count() > 1) {
                        // أبقِ على الدرس في التاريخ الأصلي واحذف المتأخر
                        foreach ($dupes as $lsn) {
                            if ($lsn->Date !== $targetDate) {
                                $lsn->delete();
                            }
                        }
                    }
                }

                // 3) إعادة تسلسل كل دروس هذا الكورس بعد أقل تاريخ مُستعاد، لتجنّب التعارضات
                $lessonsAfter = Lesson::where('CourseId', $courseId)
                    ->whereDate('Date', '>', $minRestoredDate->toDateString())
                    ->orderBy('Date')
                    ->orderBy('Start_Time')
                    ->get();

                $cursor = $minRestoredDate->copy()->addDay();

                foreach ($lessonsAfter as $lsn) {
                    // ابقِ على نفس Start/End للدرس، وحرّك التاريخ فقط حتى يصبح صالحًا
                    $target = $this->findNextValidDate(
                        $cursor->copy(),
                        $courseDays,
                        $otherHolidays,
                        $schedule->RoomId,
                        $schedule->TeacherId,
                        $lsn->Start_Time,
                        $lsn->End_Time,
                        $lsn->id // تجاهل نفسه في فحص التعارض
                    );

                    // إن وُجد تاريخ صالح -> انقل الدرس
                    if ($target) {
                        $lsn->update([
                            'Date'       => $target->toDateString(),
                            'updated_at' => now(),
                        ]);
                        $cursor = $target->copy()->addDay();
                    } else {
                        // لا يوجد تاريخ صالح معقول (حالة نادرة جدًا) -> ادفعه يومًا بيوم حتى لا نهائي منطقي
                        $fallback = $cursor->copy();
                        $attempts = 0;
                        while ($attempts < 365) {
                            if (
                                $this->isAllowedCourseDay($fallback, $courseDays) &&
                                !$this->isHolidayDate($fallback, $otherHolidays) &&
                                !$this->hasSlotConflict($fallback->toDateString(), $lsn->Start_Time, $lsn->End_Time, $roomId, $teacherId, $lsn->id)
                            ) {
                                break;
                            }
                            $fallback->addDay();
                            $attempts++;
                        }
                        $lsn->update([
                            'Date'       => $fallback->toDateString(),
                            'updated_at' => now(),
                        ]);
                        $cursor = $fallback->copy()->addDay();
                    }
                }

                // 4) تحديث تاريخ بداية/نهاية الكورس
                $newFirstLesson = Lesson::where('CourseId', $courseId)->orderBy('Date')->first();
                $newLastLesson  = Lesson::where('CourseId', $courseId)->orderByDesc('Date')->first();

                if ($newFirstLesson && $newLastLesson) {
                    $schedule->update([
                        'Start_Date' => $newFirstLesson->Date,
                        'End_Date'   => $newLastLesson->Date,
                    ]);
                }

                // 5) استرجاع فترة التسجيل من النسخة الاحتياطية ثم مواءمتها مع أول درس
                $enrollBackup = ScheduleEnrollmentBackup::where('schedule_id', $schedule->id)
                    ->where('holiday_id', $holiday->id)
                    ->first();

                if ($enrollBackup) {
                    // نعيد البداية والنهاية الأصليين
                    $origStartEnroll = Carbon::parse($enrollBackup->original_start_enroll);
                    $origEndEnroll   = Carbon::parse($enrollBackup->original_end_enroll);

                    // مدة التسجيل الأصلية (عدد الأيام) — شاملة الطرفين
                    $originalDurationDays = $origStartEnroll->diffInDays($origEndEnroll) + 1;

                    // نريد جعل نهاية التسجيل تسبق أول درس بيوم، مع الحفاظ قدر الإمكان على نفس المدة وتخطّي الإجازات الأخرى
                    $firstLessonDate = $newFirstLesson ? Carbon::parse($newFirstLesson->Date) : Carbon::parse($schedule->Start_Date);
                    $targetEndEnroll = $firstLessonDate->copy()->subDay();

                    // نبني نافذة تسجيل بنفس المدة مع تخطّي الإجازات (قدر الإمكان)
                    $newEnrollDates = collect();
                    $cursorEnroll   = $origStartEnroll->copy(); // نبدأ من الأصل
                    $attempts       = 0;
                    $maxAttempts    = 365 * 2;

                    while ($attempts < $maxAttempts && $cursorEnroll->lte($targetEndEnroll)) {
                        if (!$this->isHolidayDate($cursorEnroll, $otherHolidays)) {
                            $newEnrollDates->push($cursorEnroll->copy());
                        }
                        if ($newEnrollDates->count() >= $originalDurationDays) {
                            break;
                        }
                        $cursorEnroll->addDay();
                        $attempts++;
                    }

                    // إن لم نصل للمدة كاملة، نتحرّك للخلف قبل targetEndEnroll لنكمل العدد
                    $backCursor = $targetEndEnroll->copy();
                    while ($newEnrollDates->count() < $originalDurationDays && $attempts < $maxAttempts) {
                        if (!$this->isHolidayDate($backCursor, $otherHolidays)) {
                            // أدخل في المقدمة
                            $newEnrollDates->prepend($backCursor->copy());
                        }
                        $backCursor->subDay();
                        $attempts++;
                    }

                    if ($newEnrollDates->isNotEmpty()) {
                        $newStart = $newEnrollDates->first()->toDateString();
                        $newEnd   = $newEnrollDates->last()->toDateString();

                        $schedule->update([
                            'Start_Enroll' => $newStart,
                            'End_Enroll'   => $newEnd,
                        ]);
                    } else {
                        // fallback: أعد الأصل كما هو
                        $schedule->update([
                            'Start_Enroll' => $origStartEnroll->toDateString(),
                            'End_Enroll'   => $origEndEnroll->toDateString(),
                        ]);
                    }
                }
            }

            // أخيرًا: حذف الإجازة
            $holiday->delete();
        });

        return response()->json([
            'status'  => 'success',
            'message' => 'Holiday deleted, lessons and enrollment restored & rescheduled without conflicts.'
        ]);
   
    }
    
    private function normalizeCourseDays($courseDays)
    {
        if (is_array($courseDays)) return $courseDays;

        if (is_string($courseDays)) {
            $decoded = json_decode($courseDays, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
            // احتمال تكون مفصولة بفواصل
            return array_values(array_filter(array_map('trim', explode(',', $courseDays))));
        }
        return [];
    }
    private function isAllowedCourseDay(Carbon $date, array $courseDays): bool
    {
        if (empty($courseDays)) return true; // لو غير معرّفة نتسامح
        return in_array($date->format('D'), $courseDays, true);
    }
    private function isHolidayDate(Carbon $date, $otherHolidays): bool
    {
        foreach ($otherHolidays as $h) {
            if ($date->between(Carbon::parse($h->StartDate), Carbon::parse($h->EndDate))) {
                return true;
            }
        }
        return false;
    }
    private function hasSlotConflict(string $date, string $startTime, string $endTime, $roomId, $teacherId, $ignoreLessonId = null): bool
    {
        $q = DB::table('lessons as l')
            ->join('courses as c', 'c.id', '=', 'l.CourseId')
            ->join('course_schedules as cs', 'cs.CourseId', '=', 'c.id')
            ->whereDate('l.Date', $date)
            ->where('l.Start_Time', $startTime)
            ->where('l.End_Time', $endTime)
            ->where(function ($q) use ($roomId, $teacherId) {
                $q->where('cs.RoomId', $roomId)
                    ->orWhere('cs.TeacherId', $teacherId);
            });

        if ($ignoreLessonId) {
            $q->where('l.id', '!=', $ignoreLessonId);
        }

        return $q->exists();
    }
    private function findNextValidDate(
        Carbon $startFrom,
        array $courseDays,
        $otherHolidays,
        $roomId,
        $teacherId,
        string $startTime,
        string $endTime,
        $ignoreLessonId = null
    ): ?Carbon {
        $cursor     = $startFrom->copy();
        $maxAttempts = 365 * 2;

        for ($i = 0; $i < $maxAttempts; $i++) {
            if (
                $this->isAllowedCourseDay($cursor, $courseDays) &&
                !$this->isHolidayDate($cursor, $otherHolidays) &&
                !$this->hasSlotConflict($cursor->toDateString(), $startTime, $endTime, $roomId, $teacherId, $ignoreLessonId)
            ) {
                return $cursor;
            }
            $cursor->addDay();
        }
        return null; // لم نجد ضمن حد منطقي
    }
    private function pushAwayConflicts(
        string $targetDate,
        string $startTime,
        string $endTime,
        $roomId,
        $teacherId,
        $otherHolidays
    ): void {
        // جلب كل الدروس المتعارضة في هذا التاريخ وهذا التوقيت
        $conflicting = DB::table('lessons as l')
            ->join('courses as c', 'c.id', '=', 'l.CourseId')
            ->join('course_schedules as cs', 'cs.CourseId', '=', 'c.id')
            ->whereDate('l.Date', $targetDate)
            ->where('l.Start_Time', $startTime)
            ->where('l.End_Time', $endTime)
            ->where(function ($q) use ($roomId, $teacherId) {
                $q->where('cs.RoomId', $roomId)
                    ->orWhere('cs.TeacherId', $teacherId);
            })
            ->select('l.id as lesson_id', 'l.CourseId', 'l.Start_Time', 'l.End_Time', 'cs.RoomId', 'cs.TeacherId', 'cs.CourseDays', 'l.Date')
            ->get();

        foreach ($conflicting as $row) {
            // احصل على CourseDays كـ array
            $theirDays = $this->normalizeCourseDays($row->CourseDays);

            // ابحث أول تاريخ صالح بعد الهدف بيوم
            $next = $this->findNextValidDate(
                Carbon::parse($targetDate)->addDay(),
                $theirDays,
                $otherHolidays,
                $row->RoomId,
                $row->TeacherId,
                $row->Start_Time,
                $row->End_Time,
                $row->lesson_id
            );

            if ($next) {
                Lesson::where('id', $row->lesson_id)->update([
                    'Date'       => $next->toDateString(),
                    'updated_at' => now(),
                ]);
            } else {
                // fallback: ادفع للأمام يومًا بيوم بشكل بسيط
                $cursor = Carbon::parse($targetDate)->addDay();
                $attempts = 0;
                while ($attempts < 365) {
                    if (
                        $this->isAllowedCourseDay($cursor, $theirDays) &&
                        !$this->isHolidayDate($cursor, $otherHolidays) &&
                        !$this->hasSlotConflict($cursor->toDateString(), $row->Start_Time, $row->End_Time, $row->RoomId, $row->TeacherId, $row->lesson_id)
                    ) {
                        break;
                    }
                    $cursor->addDay();
                    $attempts++;
                }
                Lesson::where('id', $row->lesson_id)->update([
                    'Date'       => $cursor->toDateString(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

*/



    ///////////////////////////////////////////////////////////////////////////////////////////////////////////////

    //test
    /*
    public function deleteHoliday($holidayId)
    {
        DB::transaction(function () use ($holidayId) {
            $holiday = Holiday::findOrFail($holidayId);
            $allHolidays = Holiday::where('id', '!=', $holidayId)->get(); // لحذف هذه الإجازة، لا نأخذها بالحسبان

            // === 1. استرجاع الدروس ===
            $backupLessons = DB::table('lesson_backups')
                ->where('holiday_id', $holiday->id)
                ->orderBy('Date')
                ->get();

            foreach ($backupLessons->groupBy('CourseId') as $courseId => $lessons) {
                $course = Course::find($courseId);
                if (!$course) continue;

                $schedule = $course->CourseSchedule;
                if (!$schedule) continue;

                $roomId = $schedule->RoomId;
                $courseDays = $schedule->CourseDays ?? [];

                $usedDates = $course->lessons()->pluck('Date')->toArray();

                foreach ($lessons as $backup) {
                    $date = Carbon::parse($backup->Date);
                    $dayName = $date->format('D');

                    $conflict = Lesson::whereDate('Date', $date->toDateString())
                        ->where('Start_Time', $backup->Start_Time)
                        ->where('End_Time', $backup->End_Time)
                        ->whereHas('course.CourseSchedule', function ($q) use ($roomId, $course) {
                            $q->where('RoomId', $roomId)
                                ->orWhere('TeacherId', $course->CourseSchedule->TeacherId);
                        })
                        ->exists();

                    $isHoliday = $allHolidays->contains(fn($h) => $date->between($h->StartDate, $h->EndDate));

                    if (!$conflict && !$isHoliday && in_array($dayName, $courseDays)) {
                        Lesson::create([
                            'CourseId' => $backup->CourseId,
                            'Title' => $backup->Title,
                            'Date' => $backup->Date,
                            'Start_Time' => $backup->Start_Time,
                            'End_Time' => $backup->End_Time,
                        ]);
                    }
                }

                // تحديث تواريخ بداية ونهاية الكورس
                $firstLesson = $course->lessons()->orderBy('Date')->first();
                $lastLesson = $course->lessons()->orderByDesc('Date')->first();

                if ($firstLesson && $lastLesson) {
                    $schedule->update([
                        'Start_Date' => $firstLesson->Date,
                        'End_Date' => $lastLesson->Date,
                    ]);
                }
            }

            // === 2. استرجاع بيانات التسجيل ===
            $backups = ScheduleEnrollmentBackup::where('holiday_id', $holiday->id)->get();
            foreach ($backups as $backup) {
                $schedule = CourseSchedule::find($backup->schedule_id);
                if (!$schedule) continue;

                // لا نسترجع إذا كانت الفترات الأصلية تصطدم بإجازة أخرى
                $hasConflict = $allHolidays->contains(
                    fn($h) =>
                    Carbon::parse($backup->original_start_enroll)->between($h->StartDate, $h->EndDate) ||
                        Carbon::parse($backup->original_end_enroll)->between($h->StartDate, $h->EndDate)
                );

                if (!$hasConflict) {
                    $schedule->update([
                        'Start_Enroll' => $backup->original_start_enroll,
                        'End_Enroll' => $backup->original_end_enroll,
                    ]);
                }
            }

            // === 3. حذف النسخ الاحتياطية بعد الاسترجاع ===
            DB::table('lesson_backups')->where('holiday_id', $holiday->id)->delete();
            ScheduleEnrollmentBackup::where('holiday_id', $holiday->id)->delete();

            // === 4. حذف الإجازة نفسها ===
            $holiday->delete();
        });

        return response()->json([
            'status' => 'success',
            'message' => 'تم حذف الإجازة واسترجاع الدروس والتسجيلات المرتبطة بها.',
        ]);
    }


    //or option 2 

    public function deleteHoliday(int $holidayId): void
    {
        DB::transaction(function () use ($holidayId) {
            $holiday = Holiday::findOrFail($holidayId);

            // 1. استرجع النسخ الاحتياطية للدروس والتسجيلات التي تم حذفها بسبب هذه الإجازة
            $lessonBackups = LessonBackup::where('holiday_id', $holidayId)->get();
            $enrollmentBackups = EnrollmentBackup::where('holiday_id', $holidayId)->get();

            // 2. حذف الإجازة
            $holiday->delete();

            // 3. استرجاع كل الإجازات الحالية بعد الحذف
            $remainingHolidays = Holiday::pluck('date')->toArray();

            // 4. استرجاع الدروس المحذوفة ولكن مع التحقق من تصادمها مع إجازات أخرى
            foreach ($lessonBackups as $backup) {
                if (!in_array($backup->date, $remainingHolidays)) {
                    // لا يوجد تصادم، أضف الدرس مباشرة
                    Lesson::create($backup->toArray());
                } else {
                    // يوجد تصادم، أعد جدولة الدرس
                    $this->rescheduleLesson($backup);
                }
            }

            // 5. استرجاع التسجيلات المحذوفة بنفس منطق الدروس
            foreach ($enrollmentBackups as $backup) {
                if (!in_array($backup->date, $remainingHolidays)) {
                    Enrollment::create($backup->toArray());
                } else {
                    $this->rescheduleEnrollment($backup);
                }
            }

            // 6. حذف النسخ الاحتياطية بعد الاسترجاع/إعادة الجدولة
            LessonBackup::where('holiday_id', $holidayId)->delete();
            EnrollmentBackup::where('holiday_id', $holidayId)->delete();
        });
    }

    private function rescheduleLesson(LessonBackup $backup): void
    {
        $newDate = $this->findNextAvailableLessonDate($backup->course_id, $backup->date);

        Lesson::create([
            'course_id' => $backup->course_id,
            'title' => $backup->title,
            'date' => $newDate,
            'start_time' => $backup->start_time,
            'end_time' => $backup->end_time,
        ]);
    }


    private function rescheduleEnrollment(EnrollmentBackup $backup): void
    {
        $newDate = $this->findNextAvailableEnrollmentDate($backup->course_id, $backup->date);

        Enrollment::create([
            'course_id' => $backup->course_id,
            'student_id' => $backup->student_id,
            'date' => $newDate,
            'status' => $backup->status,
        ]);
    }


    private function findNextAvailableLessonDate(int $courseId, string $startDate): string
    {
        $date = Carbon::parse($startDate)->addDay();
        $holidays = Holiday::pluck('date')->toArray();

        while (in_array($date->toDateString(), $holidays) || $this->isWeekend($date)) {
            $date->addDay();
        }

        return $date->toDateString();
    }

    private function findNextAvailableEnrollmentDate(int $courseId, string $startDate): string
    {
        $date = Carbon::parse($startDate)->addDay();
        $holidays = Holiday::pluck('date')->toArray();

        while (in_array($date->toDateString(), $holidays) || $this->isWeekend($date)) {
            $date->addDay();
        }

        return $date->toDateString();
    }

    private function isWeekend(Carbon $date): bool
    {
        return in_array($date->dayOfWeekIso, [6, 7]); // السبت والأحد
    }*/
}
