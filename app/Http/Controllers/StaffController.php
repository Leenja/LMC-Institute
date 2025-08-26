<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\CourseSchedule;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\SelfTest;
use App\Models\SelfTestQuestion;
use App\Models\StaffInfo;
use App\Models\Test;
use App\Models\User;
use Illuminate\Http\Request;
use App\Services\StaffService;
use Carbon\Carbon;
use Exception;
use App\Services\RoomService;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class StaffController extends Controller
{
    protected $staffService;

    public function __construct(StaffService $staffService)
    {
        $this->staffService = $staffService;
    }

    public function getTeachers()
    {
        $teachers = User::role('Teacher')->with(['roles', 'staffInfo'])->get();

        if ($teachers->isEmpty()) {
            return response()->json([
                'message' => 'No teachers found.',
                'data'    => [],
            ], 200);
        }

        $data = $teachers->map(function ($teacher) {
            return [
                'id'          => $teacher->id,
                'name'        => $teacher->name,
                'email'       => $teacher->email,
                'roles'       => $teacher->roles->pluck('name'),
                'photo'       => $teacher->staffInfo?->Photo,
                'description' => $teacher->staffInfo?->Description,
            ];
        });

        return response()->json([
            'data' => $data,
        ], 200);
    }

    public function getGuestStudent(Request $request)
    {
        $type = strtolower($request->query('type'));

        if ($type === 'student') {
            $students = User::role('Student')->get();

            $studentData = $students->map(function ($user) {
                return [
                    'id'    => $user->id,
                    'name'  => $user->name,
                    'email' => $user->email,
                    'roles' => $user->roles->pluck('name'),
                ];
            });

            return response()->json([
                'students' => $studentData,
            ], 200);
        }

        if ($type === 'guest') {
            $guests = User::role('Guest')->get();

            $guestData = $guests->map(function ($user) {
                return [
                    'id'    => $user->id,
                    'name'  => $user->name,
                    'email' => $user->email,
                    'roles' => $user->roles->pluck('name'),
                ];
            });

            return response()->json([
                'guests' => $guestData,
            ], 200);
        }

        $students = User::role('Student')->get();
        $guests = User::role('Guest')->get();

        $studentData = $students->map(function ($user) {
            return [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'roles' => $user->roles->pluck('name'),
            ];
        });

        $guestData = $guests->map(function ($user) {
            return [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'roles' => $user->roles->pluck('name'),
            ];
        });

        return response()->json([
            'students' => $studentData,
            'guests'   => $guestData,
        ], 200);
    }

    public function editMyInfo(Request $request)
    {
        $user = auth()->id();

        $validated = $request->validate([
            'Photo' => 'nullable|file|mimes:jpeg,png,jpg,gif|max:2048',
            'Description' => 'nullable|string',
        ]);

        $staffInfo = StaffInfo::firstOrCreate(['UserId' => $user]);

        if ($request->hasFile('Photo')) {
            $image = $request->file('Photo');
            $new_name = time() . '_' . $image->getClientOriginalName();
            $image->move(public_path('storage/staff_photos'), $new_name);

            $staffInfo->Photo = url('storage/staff_photos/' . $new_name);
        }

        if ($request->has('Description')) {
            $staffInfo->Description = $validated['Description'];
        }

        $staffInfo->save();

        return response()->json([
            'message' => 'Staff info updated successfully.',
            'data' => $staffInfo->only(['Photo', 'Description']),
        ]);
    }

    public function removeMyInfo(Request $request)
    {
        $userId = auth()->id();

        $staffInfo = StaffInfo::where('UserId', $userId)->first();

        if (!$staffInfo) {
            return response()->json(['message' => 'Staff info not found.'], 404);
        }

        $validated = $request->validate([
            'Remove_Photo' => 'sometimes|boolean',
            'Remove_Description' => 'sometimes|boolean',
        ]);

        $changed = false;

        if (!empty($validated['Remove_Photo'])) {
            $staffInfo->Photo = null;
            $changed = true;
        }

        if (!empty($validated['Remove_Description'])) {
            $staffInfo->Description = null;
            $changed = true;
        }

        if (!$changed) {
            return response()->json(['message' => 'No data provided to remove.'], 400);
        }

        $staffInfo->save();

        return response()->json([
            'message' => 'Staff info cleared successfully.',
            'data' => $staffInfo->only(['Photo', 'Description']),
        ]);
    }

    public function getRoles(): JsonResponse
    {
        $roles = $this->staffService->getAllRoles();

        return response()->json([
            'roles' => $roles
        ]);
    }

    public function getUsersByRoleId($roleId): JsonResponse
    {
        try {
            $result = $this->staffService->getUsersByRoleId($roleId);

            $usersWithRole = $result['users']->map(function ($user) use ($result) {
                $user->role = [
                    'id' => $result['role']->id,
                    'name' => $result['role']->name,
                ];
                unset($user->pivot);
                return $user;
            });

            return response()->json([
                'users' => $usersWithRole,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Role not found'
            ], 404);
        }
    }

    public function destroyEmployee($id)
    {
        try {
            $result = $this->staffService->deleteEmployee($id);

            return response()->json([
                'message' => $result['message'],
                'deleted_at' => $result['deleted_at'] ?? null
            ], $result['status']);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage()
            ], (is_numeric($e->getCode()) && $e->getCode() >= 100 && $e->getCode() < 600) ? $e->getCode() : 500);
        }
    }

    public function showAllEmployees(Request $request): JsonResponse
    {
        $filter = $request->query('filter', 'active');

        $allowedFilters = ['all', 'only_deleted', 'active'];

        if (!in_array($filter, $allowedFilters)) {
            $filter = 'active';
        }

        $employees = $this->staffService->getAllEmployees($filter);

        return response()->json([
            'employees' => $employees
        ]);
    }

    public function showEmployee(Request $request, int $id): JsonResponse
    {
        $withTrashed = $request->query('with_trashed', false);

        $employee = $this->staffService->getEmployeeById($id, $withTrashed);

        if (!$employee) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        return response()->json([
            'employee' => $employee,
            'deleted_at' => $employee->deleted_at
        ]);
    }

    public function restoreEmployee($id): JsonResponse
    {
        try {
            $this->staffService->restoreEmployee($id);

            return response()->json([
                'message' => 'User restored successfully.'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage()
            ], is_numeric($e->getCode()) && $e->getCode() >= 100 && $e->getCode() < 600 ? $e->getCode() : 500);
        }
    }

    //Secretary--------------------------------------------------
    public function enrollStudent(Request $request)
    {
        $data = $request->validate([
            'StudentId' => 'required|exists:users,id',
            'CourseId' => 'required|exists:courses,id',
            'isPrivate' => 'required|boolean',
        ]);

        // Check if the student is already enrolled
        $alreadyEnrolled = Enrollment::where('StudentId', $data['StudentId'])
            ->where('CourseId', $data['CourseId'])->exists();

        if ($alreadyEnrolled) {
            return response()->json([
                'error' => 'The student is already enrolled in this course.'
            ], 400);
        }

        $schedule = CourseSchedule::where('CourseId', $data['CourseId'])->first();

        if ($schedule && $schedule->Enroll_Status === 'Full') {
            return response()->json([
                'error' => 'This course is already full. Enrollment is closed.'
            ], 400);
        }



        return response()->json(
            $this->staffService->enrollStudent($data)
        );
    }

    public function cancelEnrollment(Request $request)
    {
        $data = $request->validate([
            'StudentId' => 'required|exists:users,id',
            'CourseId' => 'required|exists:courses,id',
        ]);

        $schedule = CourseSchedule::where('CourseId', $data['CourseId'])->first();

        if (!$schedule || Carbon::now()->gte(Carbon::parse($schedule->Start_Date))) {
            return response()->json([
                'error' => 'You can only cancel before the course starts.'
            ], 400);
        }

        return response()->json(
            $this->staffService->cancelEnrollment($data)
        );
    }

    public function viewEnrolledStudentsInCourse($courseId)
    {
        return response()->json(
            $this->staffService->viewEnrolledStudentsInCourse($courseId)
        );
    }

    public function getAllEnrolledStudents()
    {
        return Enrollment::where('isPrivate', 0)
            ->get()
            ->map(function ($enrollment) {
                $student = User::find($enrollment->StudentId);
                return [
                    'EnrollmentId' => $enrollment->id,
                    'Student' => $student ? [
                        'id' => $student->id,
                        'name' => $student->name,
                        'email' => $student->email,
                    ] : null,
                ];
            });
    }

    public function getEnrolledStudentsForLanguage($languageId)
    {
        return response()->json(
            $this->staffService->viewEnrolledStudentsForLanguage($languageId)
        );
    }

    public function addCourse(Request $request)
    {

        if ($request->has('CourseDays') && is_string($request->input('CourseDays'))) {
            $request->merge([
                'CourseDays' => array_map('trim', explode(',', $request->input('CourseDays')))
            ]);
        }

        $data = $request->validate([
            'TeacherId' => 'required|exists:users,id',
            'LanguageId' => 'required|exists:languages,id',
            'RoomId' => 'exists:rooms,id',
            'Description' => 'required|string',
            'Photo' => 'required|file|mimes:jpeg,png,jpg,gif|max:2048',
            'Level' => 'required|string',
            'Start_Enroll' => 'required|date|after_or_equal:now()|before_or_equal:End_Enroll',
            'End_Enroll' => 'required|date|after_or_equal:now()|after_or_equal:Start_Enroll',
            'Start_Date' => 'required|date|after_or_equal:now()|after:Start_Enroll|after:End_Enroll',
            'Start_Time' => 'required|date_format:H:i',
            'End_Time' => 'required|date_format:H:i|after:Start_Time',
            'Number_of_lessons' => 'required|integer|min:1',
            'CourseDays' => 'required|array|min:1',
            'CourseDays.*' => 'in:Sun,Mon,Tue,Wed,Thu,Fri,Sat',
        ]);

        $startDate = Carbon::parse($data['Start_Date']);
        $courseDays = $data['CourseDays'];
        $startDayOfWeek = $startDate->format('D');

        if (!in_array($startDayOfWeek, $courseDays)) {
            return response()->json([
                'error' => "The Start Date doesn't match the selected Course Days. Please adjust the Start Date to match one of the selected days."
            ], 400);
        }

        if ($request->hasFile('Photo')) {
            $image = $request->file('Photo');
            $new_name = time() . '_' . $image->getClientOriginalName();
            $image->move(public_path('storage/course_photos'), $new_name);
            $imageUrl = url('storage/course_photos/' . $new_name);

            if (!file_exists(public_path('storage/course_photos/' . $new_name))) {
                throw new Exception('Failed to upload image', 500);
            }

            $data['Photo'] = $imageUrl;
        }

        $result = $this->staffService->createCourseWithSchedule($data);

        if ($result instanceof JsonResponse) {
            return $result;
        }

        $course   = $result['Course'];
        $schedule = $result['Schedule'];

        $title = 'New course added';
        $body  = sprintf(
            "Language: %s، Level: %s\nStart At: %s %s",
            optional($course->language ?? null)->Name ?? '-',
            $course->Level,
            Carbon::parse($schedule['Start_Date'] ?? $schedule->Start_Date ?? $data['Start_Date'])->toDateString(),
            $data['Start_Time']
        );

        $roles = Role::pluck('name')->all();

        $notification = \App\Models\Notification::create([
            'title'        => $title,
            'body'         => $body,
            'target_roles' => $roles,
        ]);

        \App\Jobs\BackfillUserNotificationsJob::dispatch($notification->id, $roles)
            ->onQueue('notifications');

        \App\Jobs\SendNotificationToTopicsJob::dispatch($notification->id, $roles)
            ->onQueue('notifications');

        return response()->json([
            'Course'     => $course,
            'Schedule'   => $schedule,
            'Lessons'    => $result['Lessons'],
            'message'    => 'Course created and notification queued.',
            'notification_id' => $notification->id,
        ], 201);
    }

    public function editCourse(Request $request)
    {

        if ($request->has('CourseDays') && is_string($request->input('CourseDays'))) {
            $request->merge([
                'CourseDays' => array_map('trim', explode(',', $request->input('CourseDays')))
            ]);
        }

        $data = $request->validate([
            'CourseId' => 'required|exists:courses,id',
            'Photo' => 'nullable|file|image|mimes:jpeg,png,jpg,gif|max:2048',
            'Start_Enroll' => 'required|date|after_or_equal:now()|before_or_equal:End_Enroll',
            'End_Enroll' => 'required|date|after_or_equal:now()|after_or_equal:Start_Enroll',
            'Start_Date' => 'required|date|after_or_equal:now()|after:Start_Enroll|after:End_Enroll',
            'Start_Time' => 'required|date_format:H:i',
            'End_Time' => 'required|date_format:H:i|after:Start_Time',
            'Number_of_lessons' => 'required|integer|min:1',
            'CourseDays' => 'required|array|min:1',
            'CourseDays.*' => 'in:Sun,Mon,Tue,Wed,Thu,Fri,Sat',
        ]);

        // Check if the Start_Date matches any of the CourseDays
        $startDate = Carbon::parse($data['Start_Date']);
        $courseDays = $data['CourseDays'];
        $startDayOfWeek = $startDate->format('D'); // Get the day of the week for Start_Date

        if (!in_array($startDayOfWeek, $courseDays)) {
            return response()->json([
                'error' => "The Start Date doesn't match the selected Course Days. Please adjust the Start Date to match one of the selected days."
            ], 400);
        }

        if ($request->hasFile('Photo')) {
            $image = $request->file('Photo');
            $new_name = time() . '_' . $image->getClientOriginalName();
            $image->move(public_path('storage/course_photos'), $new_name);
            $imageUrl = url('storage/course_photos/' . $new_name);

            if (!file_exists(public_path('storage/course_photos/' . $new_name))) {
                throw new Exception('Failed to upload image', 500);
            }

            $data['Photo'] = $imageUrl;
        }

        $editResult = $this->staffService->editCourse($data);

        //auto reserve after edit
        $schedule = CourseSchedule::where('CourseId', $data['CourseId'])->first();

        if ($schedule) {
            app(RoomService::class)->assignRoomToCourse($schedule);
            app(RoomService::class)->optimizeRoomAssignments();
        }

        return response()->json($editResult);
    }

    public function deleteCourse($courseId)
    {
        $course = Course::find($courseId);

        if (!$course) {
            return response()->json(['error' => 'Course not found'], 404);
        }

        return response()->json(
            $this->staffService->deleteCourseWithLessons($course)
        );
    }

    public function viewCourses()
    {
        $courses = $this->staffService->viewCourses();

        return response()->json([
            'Courses' => $courses
        ]);
    }

    public function viewCourse($courseId)
    {
        $course = $this->staffService->viewCourse($courseId);

        if (!$course) {
            return response()->json([
                'message' => 'Course not found.'
            ], 404);
        }

        return response()->json([
            'Course' => $course
        ]);
    }

    public function viewCourseDetails($courseId)
    {
        $schedule = $this->staffService->viewCourseDetails($courseId);

        return response()->json([
            'Course Details' => $schedule
        ]);
    }

    public function getCourseLessons($courseId)
    {
        $course = Course::find($courseId);

        if (!$course) {
            return response()->json(['error' => 'Course not found'], 404);
        }

        $lessons = $this->staffService->getCourseLessons($courseId);

        return response()->json([
            'CourseId' => $courseId,
            'Lessons' => $lessons
        ]);
    }

    //Teacher---------------------------------------------------
    public function reviewMyCourses()
    {
        $teacherId = auth()->user()->id;

        $courses = Course::where('TeacherId', $teacherId)
            ->with(['CourseSchedule.Room', 'Language', 'User'])
            ->get()
            ->map(function ($course) {
                $today = Carbon::now()->toDateString();
                $course->CourseSchedule->each(function ($schedule) use ($today, $course) {
                    if ($today < $schedule->Start_Date) {
                        $course->Status = 'Unactive';
                    } elseif ($today >= $schedule->Start_Date && $today <= $schedule->End_Date) {
                        $course->Status = 'Active';
                    } elseif ($today > $schedule->End_Date) {
                        $course->Status = 'Done';
                    }
                    $course->save();
                });

                $schedule = $course->CourseSchedule->first();

                return [
                    'id' => $course->id,
                    'TeacherName' => $course->User->name ?? null,
                    'LanguageName' => $course->Language->Name ?? null,
                    'Description' => $course->Description,
                    'Level' => $course->Level,
                    'Status' => $course->Status,
                    'Photo' => $course->Photo,
                    'course_schedule' => $schedule ? [
                        'id' => $schedule->id,
                        'Start_Date' => $schedule->Start_Date,
                        'End_Date' => $schedule->End_Date,
                        'Days' => $schedule->CourseDays,
                        'Start_Time' => $schedule->Start_Time,
                        'End_Time' => $schedule->End_Time,
                        'NumberOfRoom' => $schedule->Room->NumberOfRoom ?? null,
                    ] : null,
                ];
            });

        return response()->json([
            'My Courses' => $courses
        ]);
    }

    public function reviewCurrentCourses()
    {
        $teacherId = auth()->id();
        $today = Carbon::now()->toDateString();

        $courses = Course::where('TeacherId', $teacherId)
            ->with(['CourseSchedule'])
            ->get()
            ->filter(function ($course) use ($today) {
                $course->CourseSchedule->each(function ($schedule) use ($today, $course) {
                    if ($today < $schedule->Start_Date) {
                        $course->Status = 'Unactive';
                    } elseif ($today >= $schedule->Start_Date && $today <= $schedule->End_Date) {
                        $course->Status = 'Active';
                    } elseif ($today > $schedule->End_Date) {
                        $course->Status = 'Done';
                    }
                });

                return in_array($course->Status, ['Active', 'Unactive']);
            })
            ->values();

        return response()->json([
            'Current Courses' => $courses
        ]);
    }

    public function reviewSchedule(Request $request)
    {
        $date = $request->input('date');

        $result = $this->staffService->getScheduleByDate($date);

        return response()->json($result);
    }

    public function reviewStudentsNames($courseId)
    {
        $teacherId = auth()->user()->id;

        $course = Course::where('id', $courseId)->where('TeacherId', $teacherId)->first();

        if (!$course) {
            return response()->json(['message' => 'Course not found or not assigned to you'], 404);
        }

        $enrollments = $course->Enrollment()->with('User')->get();

        $students = $enrollments->map(function ($enrollment) {
            if ($enrollment->User) {
                return [
                    'id' => $enrollment->User->id,
                    'name' => $enrollment->User->name,
                ];
            }
            return null;
        })->filter();

        return response()->json([
            'students' => $students->values()
        ]);
    }

    public function enterBonus(Request $request)
    {
        $validated = $request->validate([
            'LessonId' => 'required|exists:lessons,id',
            'StudentId' => 'required|exists:users,id',
            'Bonus' => 'required|numeric|min:0',
        ]);

        $result = $this->staffService->enterBonus(
            $validated['LessonId'],
            $validated['StudentId'],
            $validated['Bonus']
        );

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], 404);
        }

        return response()->json(['message' => $result['success']]);
    }

    public function markAttendance(Request $request)
    {
        $validated = $request->validate([
            'LessonId' => 'required|exists:lessons,id',
            'StudentId' => 'required|exists:users,id',
        ]);

        $result = $this->staffService->markAttendance(
            $validated['LessonId'],
            $validated['StudentId']
        );

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], 404);
        }

        return response()->json(['message' => $result['success']]);
    }

    public function addSelfTest(Request $request)
    {
        $data = $request->validate([
            'LessonId' => 'required|exists:lessons,id',
            'Title' => 'required|string',
            'Description' => 'required|string',
        ]);

        $lesson = Lesson::with('Course')->find($data['LessonId']);
        $teacherId = auth()->user()->id;

        if (!$lesson || $lesson->Course->TeacherId !== $teacherId) {
            return response()->json(['message' => 'You are not authorized to add a self-test to this lesson'], 403);
        }

        try {
            $selfTest = $this->staffService->addSelfTest($data);
            return response()->json(['message' => 'Self test created successfully', 'Self Test' => $selfTest], 201);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function editSelfTest(Request $request)
    {
        $data = $request->validate([
            'SelfTestId' => 'required|exists:self_tests,id',
            'Title' => 'required|string|max:255',
            'Description' => 'required|string',
        ]);

        $selftest = SelfTest::with('Lesson.Course')->find($data['SelfTestId']);
        $teacherId = auth()->user()->id;

        if (!$selftest || $selftest->Lesson->Course->TeacherId !== $teacherId) {
            return response()->json(['message' => 'You are not authorized to edit a self-test to this lesson'], 403);
        }

        try {
            $selfTest = $this->staffService->editSelfTest($data);
            return response()->json(['message' => 'Self test updated successfully', 'Self Test' => $selfTest], 200);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function deleteSelfTest($id)
    {
        $selfTest = SelfTest::with('Lesson.Course')->find($id);
        $teacherId = auth()->user()->id;

        if (!$selfTest || $selfTest->Lesson->Course->TeacherId !== $teacherId) {
            return response()->json(['message' => 'You are not authorized to delete this self-test'], 403);
        }

        try {
            $this->staffService->deleteSelfTest($id);
            return response()->json(['message' => 'Self test deleted successfully'], 200);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function addSelfTestQuestion(Request $request)
    {
        $data = $request->validate([
            'SelfTestId' => 'required|exists:self_tests,id',
            'Media' => 'sometimes|file|mimes:m4v,webm,flv,wmv,mov,mkv,avi,mp4,mp3,wav,ogg,tif,tiff,heic,svg,bmp,webp,gif,png,jpeg,jpg',
            'QuestionText' => 'required|string',
            'Type' => 'required|in:MCQ,true_false,translate',
            'Choices' => 'required_if:Type,MCQ|nullable|json', // Only for MCQ
            'CorrectAnswer' => 'nullable|string',
        ]);

        $selfTest = SelfTest::with('Lesson.Course')->find($data['SelfTestId']);
        $teacherId = auth()->user()->id;

        if (!$selfTest || $selfTest->Lesson->Course->TeacherId !== $teacherId) {
            return response()->json(['message' => 'You are not authorized to add questions to this self test'], 403);
        }

        if ($request->hasFile('Media')) {
            $media = $request->file('Media');
            $new_name = time() . '_' . $media->getClientOriginalName();
            $media->move(public_path('storage/selfTestsMedia'), $new_name);
            $mediaUrl = url('storage/selfTestsMedia/' . $new_name);

            if (!file_exists(public_path('storage/selfTestsMedia/' . $new_name))) {
                throw new Exception('Failed to upload media', 500);
            }

            $data['Media'] = $mediaUrl;
        }

        try {
            $question = $this->staffService->addSelfTestQuestion($data);
            return response()->json(['message' => 'Self test question added successfully', 'question' => $question], 201);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function editSelfTestQuestion(Request $request)
    {
        $data = $request->validate([
            'SelfTestQuestionId' => 'required|exists:self_test_questions,id',
            'Media' => 'nullable|file|mimes:m4v,webm,flv,wmv,mov,mkv,avi,mp4,mp3,wav,ogg,tif,tiff,heic,svg,bmp,webp,gif,png,jpeg,jpg',
            'QuestionText' => 'nullable|string',
            'Type' => 'nullable|in:MCQ,true_false,translate',
            'Choices' => 'required_if:Type,MCQ|nullable|json', // Only for MCQ
            'CorrectAnswer' => 'nullable|string',
        ]);

        $question = SelfTestQuestion::with('SelfTest.Lesson.Course')->find($data['SelfTestQuestionId']);
        $teacherId = auth()->user()->id;

        if (!$question || $question->SelfTest->Lesson->Course->TeacherId !== $teacherId) {
            return response()->json(['message' => 'You are not authorized to edit this question'], 403);
        }

        if ($request->hasFile('Media')) {
            $media = $request->file('Media');
            $new_name = time() . '_' . $media->getClientOriginalName();
            $media->move(public_path('storage/selfTestsMedia'), $new_name);
            $mediaUrl = url('storage/selfTestsMedia/' . $new_name);

            if (!file_exists(public_path('storage/selfTestsMedia/' . $new_name))) {
                return response()->json(['message' => 'Failed to upload media'], 500);
            }

            $data['Media'] = $mediaUrl;
        }

        try {
            $updatedQuestion = $this->staffService->editSelfTestQuestion($data);
            return response()->json(['message' => 'Self test question updated successfully', 'question' => $updatedQuestion], 200);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function deleteSelfTestQuestion($id)
    {
        $question = SelfTestQuestion::with('SelfTest.Lesson.Course')->find($id);
        $teacherId = auth()->user()->id;

        if (!$question || $question->SelfTest->Lesson->Course->TeacherId !== $teacherId) {
            return response()->json(['message' => 'You are not authorized to delete this question'], 403);
        }

        try {
            $this->staffService->deleteSelfTestQuestion($id);
            return response()->json(['message' => 'Self test question deleted successfully'], 200);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function addFinalTest(Request $request)
    {
        $data = $request->validate([
            'CourseId' => 'required|exists:courses,id',
            'Title' => 'required|string|max:255',
            'Duration' => 'required|numeric|min:1',
            'Mark' => 'required|numeric|min:1',
        ]);

        $teacherId = auth()->user()->id;
        $course = Course::find($data['CourseId']);

        if (!$course || $course->TeacherId !== $teacherId) {
            return response()->json(['message' => 'You are not authorized to create a final test for this course'], 403);
        }

        $alreadyHasFinalTest = $course->tests()->exists();
        if ($alreadyHasFinalTest) {
            return response()->json(['message' => 'This course already has a final test'], 400);
        }

        try {
            $test = $this->staffService->addFinalTest($data, $teacherId);
            return response()->json(['message' => 'Final test created successfully', 'test' => $test], 201);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function editFinalTest(Request $request)
    {
        $data = $request->validate([
            'TestId' => 'required|exists:tests,id',
            'Title' => 'sometimes|string|max:255',
            'Duration' => 'sometimes|numeric|min:1',
            'Mark' => 'sometimes|numeric|min:1',
        ]);

        $teacherId = auth()->user()->id;

        try {
            $test = $this->staffService->editFinalTest($data, $teacherId);
            return response()->json(['message' => 'Final test updated successfully', 'test' => $test], 200);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function deleteFinalTest($id)
    {
        $teacherId = auth()->user()->id;
        try {
            $this->staffService->deleteFinalTest($id, $teacherId);
            return response()->json(['message' => 'Final test deleted successfully'], 200);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function addFinalTestQuestion(Request $request)
    {
        $data = $request->validate([
            'TestId' => 'required|exists:tests,id',
            'QuestionText' => 'required|string',
            'Type' => 'required|in:MCQ,true_false,translate',
            'Choices' => 'required_if:Type,MCQ|nullable|json',
            'CorrectAnswer' => 'nullable|string',
            'Point' => 'required|numeric|min:0.5',
            'Media' => 'sometimes|file|mimes:mp4,jpeg,jpg,png,gif,webp,mp3,wav|max:10240',
        ]);

        $teacherId = auth()->user()->id;
        try {
            $question = $this->staffService->addFinalTestQuestion($data, $request->file('Media'), $teacherId);
            return response()->json(['message' => 'Question added successfully', 'question' => $question], 201);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function editFinalTestQuestion(Request $request)
    {
        $data = $request->validate([
            'TestQuestionId' => 'required|exists:test_questions,id',
            'QuestionText' => 'sometimes|string',
            'Type' => 'sometimes|in:MCQ,true_false,translate',
            'Choices' => 'required_if:Type,MCQ|nullable|json',
            'CorrectAnswer' => 'nullable|string',
            'Point' => 'sometimes|numeric|min:0.5',
            'Media' => 'sometimes|file|mimes:mp4,jpeg,jpg,png,gif,webp,mp3,wav|max:10240',
        ]);

        $teacherId = auth()->user()->id;
        try {
            $question = $this->staffService->editFinalTestQuestion($data, $request->file('Media'), $teacherId);
            return response()->json(['message' => 'Test question updated successfully', 'question' => $question], 200);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function deleteFinalTestQuestion($id)
    {
        $teacherId = auth()->user()->id;
        try {
            $this->staffService->deleteFinalTestQuestion($id, $teacherId);
            return response()->json(['message' => 'Test question deleted successfully'], 200);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function viewFinalTestQuestions($testId)
    {
        $teacherId = auth()->user()->id;
        try {
            $questions = $this->staffService->getFinalTestQuestions($testId, $teacherId);
            return response()->json(['questions' => $questions], 200);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function viewFinalTestQuestion($questionId)
    {
        $teacherId = auth()->id();
        try {
            $question = $this->staffService->getFinalTestQuestion($questionId, $teacherId);
            return response()->json(['question' => $question], 200);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function getFinalTest($courseId)
    {
        $teacherId = auth()->user()->id;

        $test = Test::where('CourseId', $courseId)
                    ->where('TeacherId', $teacherId)
                    ->first();

        if (!$test) {
            return response()->json(['message' => 'Test not found for this course or not assigned to you'], 404);
        }

        return response()->json([
            'Final Test' => $test
        ]);
    }

    public function addFlashCard(Request $request)
    {
        $data = $request->validate([
            'LessonId' => 'required|exists:lessons,id',
            'Content' => 'required|string',
            'Translation' => 'required|string',
        ]);

        $flashcard = $this->staffService->addFlashCard($data);

        return response()->json([
            'message' => 'Flashcard added to lesson successfully.',
            'flashcard' => $flashcard,
        ]);
    }

    public function editFlashCard(Request $request)
    {
        $data = $request->validate([
            'FlashcardId' => 'required|exists:flash_cards,id',
            'Content' => 'required|string',
            'Translation' => 'required|string',
        ]);

        $flashcard = $this->staffService->editFlashCard($data);

        return response()->json([
            'message' => 'Flashcard updated successfully.',
            'Flashcard' => $flashcard,
        ]);
    }

    public function deleteFlashCard(Request $request)
    {
        $data = $request->validate([
            'FlashcardId' => 'required|exists:flash_cards,id',
        ]);

        $this->staffService->deleteFlashCard($data['FlashcardId']);

        return response()->json([
            'message' => 'Flashcard deleted successfully.',
        ]);
    }

    public function viewAllTeacherFlashCards()
    {
        $teacherId = auth()->user()->id;
        $flashCards = $this->staffService->getAllFlashCards($teacherId);

        return response()->json([
            'message' => 'All flashcards retrieved successfully.',
            'FlashCards' => $flashCards
        ]);
    }

    public function viewTeacherFlashCard($flashcardId)
    {
        $teacherId = auth()->user()->id;
        $flashCard = $this->staffService->getFlashCard($teacherId, $flashcardId);

        if (!$flashCard) {
            return response()->json([
                'message' => 'Flashcard not found or not accessible.',
            ], 404);
        }

        return response()->json([
            'message' => 'Flashcard retrieved successfully.',
            'FlashCard' => $flashCard
        ]);
    }

    public function viewLessonFlashCards($lessonId)
    {
        $teacherId = auth()->user()->id;

        $flashCards = $this->staffService->viewLessonFlashCards($teacherId, $lessonId);

        if ($flashCards === null) {
            return response()->json([
                'message' => 'Lesson not found or not accessible.',
            ], 404);
        }

        return response()->json([
            'message' => 'Flashcards for lesson retrieved successfully.',
            'FlashCards' => $flashCards
        ]);
    }

    public function viewCourseFlashCards($courseId)
    {
        $teacherId = auth()->user()->id;

        $flashCards = $this->staffService->viewCourseFlashCards($teacherId, $courseId);

        if ($flashCards === null) {
            return response()->json([
                'message' => 'Course not found or not accessible.',
            ], 404);
        }

        return response()->json([
            'message' => 'Flashcards for course retrieved successfully.',
            'FlashCards' => $flashCards
        ]);
    }

    public function teacherLog()
    {
        $teacherId = auth()->id();

        $courses = DB::table('course_schedules')
            ->join('courses', 'course_schedules.CourseId', '=', 'courses.id')
            ->where('courses.TeacherId', $teacherId)
            ->where('course_schedules.End_Date', '<', now())
            ->select(
                'courses.Description as CourseName',
                'course_schedules.Start_Date',
                'course_schedules.End_Date',
                'course_schedules.id as ScheduleId',
                'course_schedules.CourseId'
            )
            ->get();

        $log = [];

        foreach ($courses as $course) {
            $studentCount = DB::table('enrollments')
                ->where('CourseId', $course->CourseId)
                ->count();

            $avgGrade = DB::table('final_grades')
                ->where('CourseId', $course->CourseId)
                ->avg('FinalGrade');

            //top student
            $topStudent = DB::table('final_grades')
                ->join('users', 'final_grades.StudentId', '=', 'users.id')
                ->where('final_grades.CourseId', $course->CourseId)
                ->orderByDesc('FinalGrade')
                ->select('users.name as StudentName', 'FinalGrade')
                ->first();

            //attendance percentage
            $lessonCount = DB::table('lessons')
                ->where('CourseId', $course->CourseId)
                ->count();

            $maxAttendance = $studentCount * $lessonCount;

            $actualAttendance = DB::table('attendances')
                ->join('lessons', 'attendances.LessonId', '=', 'lessons.id')
                ->where('lessons.CourseId', $course->CourseId)
                ->count();

            $attendancePercentage = $maxAttendance > 0 ? round(($actualAttendance / $maxAttendance) * 100, 2) : 0;

            $log[] = [
                'Course Id' => $course->CourseId,
                'Course Name' => $course->CourseName,
                'Start Date' => $course->Start_Date,
                'End Date' => $course->End_Date,
                'Students Count' => $studentCount,
                'Average Grade' => round($avgGrade, 2),
                'Top Student' => $topStudent ? $topStudent->StudentName : null,
                'Top Student Grade' => $topStudent ? $topStudent->FinalGrade : null,
                'Attendance Percentage' => $attendancePercentage . '%',
            ];
        }

        $teacherName = auth()->user()->name;

        return response()->json([
            'message' => 'Teacher ' . $teacherName . ' log retrieved successfully.',
            'data' => $log,
        ]);
    }
}
