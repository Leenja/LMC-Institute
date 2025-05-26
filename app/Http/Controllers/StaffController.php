<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\CourseSchedule;
use App\Models\Enrollment;
use App\Models\StaffInfo;
use App\Models\User;
use Illuminate\Http\Request;
use App\Services\StaffService;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\URL;

class StaffController extends Controller
{
    protected $staffService;

    public function __construct(StaffService $staffService)
    {
        $this->staffService = $staffService;
    }

    public function editMyInfo(Request $request) {
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

    public function removeMyInfo(Request $request) {
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

    //Secretary--------------------------------------------------
    public function enrollStudent (Request $request) {
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

    public function viewEnrolledStudentsInCourse($courseId) {
        return response()->json(
            $this->staffService->viewEnrolledStudentsInCourse($courseId)
        );
    }

    public function getAllEnrolledStudents()
    {
        return Enrollment::all()
            ->map(function ($enrollment) {
                $student = User::find($enrollment->StudentId);  // Fetch user details using StudentId
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

    public function addCourse(Request $request) {

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

        return response()->json(
            $this->staffService->createCourseWithSchedule($data)
        );
    }

    public function editCourse(Request $request) {

        if ($request->has('CourseDays') && is_string($request->input('CourseDays'))) {
            $request->merge([
                'CourseDays' => array_map('trim', explode(',', $request->input('CourseDays')))
            ]);
        }

        $data = $request->validate([
            'CourseId' => 'required|exists:courses,id',
            'RoomId' => 'required|exists:rooms,id',
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

        return response()->json(
            $this->staffService->editCourse($data)
        );
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
    public function sendAssignments() {

    }

    public function reviewMyCourses() {
        $teacherId = auth()->user()->id;

        $courses = Course::where('TeacherId', $teacherId)->with('CourseSchedule.Room', 'Language','User')->get();

        return response()->json([
            'My Courses' => $courses
        ]);
    }

    public function reviewSchedule() {

        $result = $this->staffService->getTodaysSchedule();

        return response()->json($result);
    }

    public function reviewStudentsNames($courseId) {
        $teacherId = auth()->user()->id;

        $course = Course::where('id', $courseId)->where('TeacherId', $teacherId)->first();

        if (!$course) {
            return response()->json(['message' => 'Course not found or not assigned to you'], 404);
        }

        $enrollments = $course->Enrollment()->with('User')->get();

        $studentNames = $enrollments->map(function ($enrollment) {
            return $enrollment->User->name ?? null;
        })->filter();

        return response()->json([
            'Students' => $studentNames->values()
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

    /*
    public function addTest(Request $request) {
        $data = $request->validate([
            'CourseId' => 'required|exists:courses,id',
            'Title' => 'required|string',
            'Duration' => 'required|numeric|min:1',
            'Mark' => 'required|numeric|min:0',
        ]);

        $data['TeacherId'] = auth()->user()->id;

        $test = $this->staffService->addTest($data);

        return response()->json([
            'message' => 'Test created successfully.',
            'Test' => $test,
        ]);
    }

    public function editTest(Request $request) {
        $data = $request->validate([
            'TestId' => 'required|exists:tests,id',
            'Title' => 'required|string',
            'Duration' => 'required|numeric|min:1',
            'Mark' => 'required|numeric|min:0',
        ]);

        $test = $this->staffService->editTest($data);

        return response()->json([
            'message' => 'Test updated successfully.',
            'Test' => $test,
        ]);
    }

    public function deleteTest(Request $request) {
        $data = $request->validate([
            'TestId' => 'required|exists:tests,id',
        ]);

        $this->staffService->deleteTest($data['TestId']);

        return response()->json([
            'message' => 'Test deleted successfully.',
        ]);
    }

    public function addSelfTest(Request $request) {

    }

    public function editSelfTest(Request $request) {

    }

    public function deleteSelfTest(Request $request) {

    }*/

    public function addFlashCard(Request $request) {
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

    public function editFlashCard(Request $request) {
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

    public function deleteFlashCard(Request $request) {
        $data = $request->validate([
            'FlashcardId' => 'required|exists:flash_cards,id',
        ]);

        $this->staffService->deleteFlashCard($data['FlashcardId']);

        return response()->json([
            'message' => 'Flashcard deleted successfully.',
        ]);
    }

    public function viewAllTeacherFlashCards() {
        $teacherId = auth()->user()->id;
        $flashCards = $this->staffService->getAllFlashCards($teacherId);

        return response()->json([
            'message' => 'All flashcards retrieved successfully.',
            'FlashCards' => $flashCards
        ]);
    }

    public function viewTeacherFlashCard($flashcardId) {
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

}
