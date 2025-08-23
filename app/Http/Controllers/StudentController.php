<?php

namespace App\Http\Controllers;

use App\Models\FinalTestAnswer;
use App\Models\FinalTestProgress;
use App\Models\Language;
use App\Models\Lesson;
use App\Models\LMCInfo;
use App\Models\PlacementTest;
use App\Models\PlacementTestAnswer;
use App\Models\PlacementTestProgress;
use App\Models\PlacementTestQuestion;
use App\Models\SelfTestProgress;
use App\Models\SelfTestQuestion;
use App\Models\Test;
use App\Models\TestQuestion;
use App\Models\User;
use App\Services\StudentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudentController extends Controller
{
    protected $studentService;

    public function __construct(StudentService $studentService)
    {
        $this->studentService = $studentService;
    }

    public function viewLMCInfo()
    {
        $info = LMCInfo::latest()->first();
        $teachers = User::where('role_id', 3)->get();
        $languages = Language::all();

        return response()->json([
            'Title' => $info->Title,
            'Description' => $info->Descriptions ? json_decode($info->Descriptions) : [],
            'Photo' => $info->Photo,
            'Teachers' => $teachers,
            'Languages' => $languages,
        ]);
    }

    public function viewEnrolledCourses()
    {
        $studentId = auth()->user()->id;

        $enrollments  = $this->studentService->getEnrolledCourses($studentId);

        $courses = $enrollments->map(function ($course) {
            $firstSchedule = $course->CourseSchedule->first();

            return [
                'id' => $course->id,
                'Room Number' => $firstSchedule->Room->NumberOfRoom ?? null,
                'Teacher Name' => $course->User->name ?? null,
                'Language' => $course->Language->Name ?? null,
                'Description' => $course->Description,
                'Photo' => $course->Photo,
                'Status' => $course->Status,
                'Level' => $course->Level,
                'course_schedule' => $course->CourseSchedule->map(function ($schedule) {
                    return [
                        'id' => $schedule->id,
                        'CourseId' => $schedule->CourseId,
                        'Start_Enroll' => $schedule->Start_Enroll,
                        'End_Enroll' => $schedule->End_Enroll,
                        'Enroll_Status' => $schedule->Enroll_Status,
                        'Start_Date' => $schedule->Start_Date,
                        'End_Date' => $schedule->End_Date,
                        'Start_Time' => $schedule->Start_Time,
                        'End_Time' => $schedule->End_Time,
                        'CourseDays' => $schedule->CourseDays,
                    ];
                }),
            ];
        });

        return response()->json([
            'message' => 'Enrolled courses retrieved successfully.',
            'Courses' => $courses,
        ]);
    }

    public function viewMyLessons($courseId)
    {
        $studentId = auth()->user()->id;

        $lessons = $this->studentService->getMyLessons($studentId, $courseId);

        if (isset($lessons['error'])) {
            return response()->json(['message' => $lessons['error']], 403);
        }

        return response()->json([
            'message' => 'Lessons retrieved successfully.',
            'My Lessons' => $lessons,
        ]);
    }

    public function viewTeachers()
    {
        $teachers = $this->studentService->getAllTeachers();

        return response()->json([
            'message' => 'Teachers retrieved successfully.',
            'Teachers' => $teachers->map(function ($teacher) {
                return [
                    'id' => $teacher->id,
                    'name' => $teacher->name,
                    'email' => $teacher->email,
                    'Photo' => $teacher->staffInfo->Photo,
                    'Description' => $teacher->staffInfo->Description,
                ];
            }),
        ]);
    }

    public function viewTeacher($teacherId)
    {
        $teacher = $this->studentService->getTeacher($teacherId);

        if (!$teacher) {
            return response()->json(['message' => 'Teacher not found.'], 404);
        }

        return response()->json([
            'message' => 'Teacher retrieved successfully.',
            'Teacher' =>
            [
                'id' => $teacher->id,
                'name' => $teacher->name,
                'email' => $teacher->email,
                'Photo' => $teacher->staffInfo->Photo,
                'Description' => $teacher->staffInfo->Description,
            ],
        ]);
    }

    public function viewAvailableCourses()
    {
        $courses = $this->studentService->getAvailableCourses();

        return response()->json([
            'message' => 'Available courses retrieved successfully.',
            'Available Courses' => $courses
        ]);
    }

    public function getSelfTestQuestions($selfTestId)
    {
        $studentId = auth()->user()->id;

        $questions = $this->studentService->getSelfTestQuestions($studentId, $selfTestId);

        if (isset($questions['error'])) {
            return response()->json(['message' => $questions['error']], 403);
        }

        return response()->json([
            'message' => 'Self test questions retrieved successfully.',
            'Questions' => $questions,
        ]);
    }

    public function getSelfTest_ALL_Questions($selfTestId)
    {
        $studentId = auth()->id();

        $result = $this->studentService->getSelfTest_ALL_Questions($studentId, $selfTestId);

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], 404);
        }

        return response()->json([
            'message' => 'Self test questions retrieved successfully.',
            'SelfTest' => $result['SelfTest'],
            'Lesson' => $result['Lesson'],
            'Questions' => $result['Questions'],
        ]);
    }

    public function getSelfTestsByLesson($lessonId)
    {
        if (!\App\Models\Lesson::where('id', $lessonId)->exists()) {
            return response()->json(['message' => 'Lesson not found'], 404);
        }

        $result = $this->studentService->getSelfTestsByLesson($lessonId);

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], 404);
        }

        return response()->json([
            'message' => 'Self tests retrieved successfully.',
            'SelfTests' => $result,
        ]);
    }

    public function submitSelfTestAnswer(Request $request)
    {
        $studentId = auth()->user()->id;

        $request->validate([
            'SelfTestId' => 'required|exists:self_tests,id',
            'QuestionId' => 'required|exists:self_test_questions,id',
            'Answer' => 'required',
        ]);

        $question = SelfTestQuestion::find($request->QuestionId);

        if (!$question || $question->SelfTestId != $request->SelfTestId) {
            return response()->json(['message' => 'Invalid question for this self test.'], 400);
        }

        $isCorrect = trim(strtolower($request->Answer)) === trim(strtolower($question->CorrectAnswer));

        // Save progress
        SelfTestProgress::updateOrCreate(
            ['StudentId' => $studentId, 'SelfTestId' => $request->SelfTestId],
            ['LastAnsweredQuestionId' => $request->QuestionId]
        );

        return response()->json(array_filter([
            'message' => $isCorrect ? 'Correct answer!' : 'Wrong answer!',
            'correctAnswer' => $isCorrect ? null : $question->CorrectAnswer,
            'nextAvailable' => true
        ], fn($value) => !is_null($value)));
    }

    public function getFinalTestQuestion($testId)
    {
        $studentId = auth()->id();

        if (!$this->studentService->canAccessFinalTest($studentId, $testId)) {
            return response()->json([
                'message' => 'You can take the final test only during the last hour of the last course day,and you have to be attended.'
            ], 403);
        }

        $question = $this->studentService->getNextFinalTestQuestion($studentId, $testId);

        if (isset($question['error'])) {
            return response()->json(['message' => $question['error']], 403);
        }

        return response()->json([
            'message' => 'Final test question retrieved successfully.',
            'question' => $question,
        ]);
    }

    public function submitFinalTestAnswer(Request $request)
    {
        $studentId = auth()->id();

        $request->validate([
            'TestId' => 'required|exists:tests,id',
            'QuestionId' => 'required|exists:test_questions,id',
            'Answer' => 'required|string',
        ]);

        if (!$this->studentService->canAccessFinalTest($studentId, $request->TestId)) {
            return response()->json([
                'message' => 'You can take the final test only during the last hour of the last course day,and you have to be attended.'
            ], 403);
        }

        $result = $this->studentService->submitFinalTestAnswer($studentId, $request->all());

        return response()->json($result);
    }

    public function getAllFinalTestQuestions($testId)
    {
        $studentId = auth()->id();

        if (!$this->studentService->canAccessFinalTest($studentId, $testId)) {
            return response()->json([
                'message' => 'You can take the final test only during the last hour of the last course day,and you have to be attended.'
            ], 403);
        }

        $questions = $this->studentService->getAllFinalTestQuestions($studentId, $testId);

        return response()->json([
            'message' => 'All final test questions retrieved successfully.',
            'questions' => $questions,
        ]);
    }

    public function getFinalTest($testId)
    {
        $studentId = auth()->id();

        if (!$this->studentService->canAccessFinalTest($studentId, $testId)) {
            return response()->json([
                'message' => 'You can take the final test only during the last hour of the last course day,and you have to be attended.'
            ], 403);
        }

        $finalTest = $this->studentService->getFinalTest($testId);

        if (!$finalTest) {
            return response()->json([
                'message' => 'Final test not found.'
            ], 404);
        }

        return response()->json([
            'message' => 'Final test retrieved successfully.',
            'Final Test' => $finalTest
        ]);
    }

    //Click when finish the test
    public function submitFinalTest(Request $request)
    {
        $studentId = auth()->id();
        $testId = $request->input('TestId');

        $test = Test::findOrFail($testId);

        $totalQuestions = TestQuestion::where('TestId', $testId)->count();
        $answered = FinalTestAnswer::where('StudentId', $studentId)->where('TestId', $testId)->count();

        if ($answered < $totalQuestions) {
            return response()->json([
                'message' => 'You must answer all questions before submitting the test.'
            ], 400);
        }

        $score = FinalTestProgress::where('StudentId', $studentId)
            ->where('TestId', $testId)
            ->sum('Score');

        $courseId = $test->CourseId;
        $lessonIds = Lesson::where('CourseId', $courseId)->pluck('id');

        $bonus = DB::table('attendances')
            ->where('StudentId', $studentId)
            ->whereIn('LessonId', $lessonIds)
            ->sum('Bonus');

        $finalGrade = $score + $bonus;

        DB::table('final_grades')->updateOrInsert(
            ['StudentId' => $studentId, 'CourseId' => $courseId],
            [
                'FinalTestScore' => $score,
                'Bonus' => $bonus,
                'FinalGrade' => $finalGrade,
                'updated_at' => now(),
                'created_at' => now()
            ]
        );

        return response()->json([
            'message' => 'Final test submitted successfully.',
            'FinalTestScore' => $score,
            'Bonus' => $bonus,
            'FinalGrade' => $finalGrade
        ]);
    }

    public function getAllPTQuestions()
    {
        $user = auth()->user();
        $userId = $user->id;

        $lastTest = PlacementTest::where('GuestId', $userId)
                                ->latest()
                                ->first();

        // زائر
        if ($user->role_id == 6) {
            if ($lastTest && $lastTest->Status === 'Completed') {
                return response()->json([
                    'message' => 'You have already completed the placement test.',
                ], 403);
            }
        }

        elseif ($user->role_id == 5) {
            if ($lastTest && $lastTest->Status === 'Completed') {
                $daysSinceLastTest = $lastTest->created_at->diffInDays(now());

                if ($daysSinceLastTest < 30) {
                    return response()->json([
                        'message' => 'You must wait at least 30 days to view the placement test again.',
                        'LastTestDate' => $lastTest->created_at->toDateString(),
                        'DaysRemaining' => 30 - $daysSinceLastTest
                    ], 403);
                }
            }
        }

        else {
            return response()->json([
                'message' => 'Unauthorized role for placement test questions.'
            ], 403);
        }

        $questions = PlacementTestQuestion::with(['answers' => function ($query) {
            $query->select('id', 'QuestionId', 'AnswerText');
        }])
        ->select('id', 'Section', 'Context', 'Media', 'QuestionText')->get();

        return response()->json([
            'Questions' => $questions
        ]);
    }

    public function getPTQuestion()
    {
        $user = auth()->user();
        $userId = $user->id;

        $lastTest = PlacementTest::where('GuestId', $userId)
                                ->latest()
                                ->first();

        if ($user->role_id == 6) {
            if ($lastTest && $lastTest->Status === 'Completed') {
                return response()->json([
                    'message' => 'Test Completed',
                    'Level' => $lastTest->Level,
                    'TotalScore' => $lastTest->TotalScore,
                    'AudioScore' => $lastTest->AudioScore,
                    'ReadingScore' => $lastTest->ReadingScore,
                    'SpeakingScore' => $lastTest->SpeakingScore,
                ]);
            }
            if (!$lastTest) {
                $lastTest = PlacementTest::create([
                    'GuestId' => $userId,
                    'LanguageId' => 1,
                    'Level' => 'Not Set',
                    'AudioScore' => 0,
                    'ReadingScore' => 0,
                    'SpeakingScore' => 0,
                    'TotalScore' => 0,
                ]);
            }
        }

        elseif ($user->role_id == 5) {
            if ($lastTest && $lastTest->Status === 'Completed') {
                $now = now();
                $diffInDays = $lastTest->created_at->diffInDays($now);

                if ($diffInDays < 30) {
                    return response()->json([
                        'message' => 'You must wait at least 30 days before taking the placement test again.',
                        'LastTestDate' => $lastTest->created_at->toDateString(),
                        'DaysRemaining' => 30 - $diffInDays
                    ], 403);
                }

                $lastTest = PlacementTest::create([
                    'GuestId' => $userId,
                    'LanguageId' => 1,
                    'Level' => 'Not Set',
                    'AudioScore' => 0,
                    'ReadingScore' => 0,
                    'SpeakingScore' => 0,
                    'TotalScore' => 0,
                ]);
            }

            if (!$lastTest) {
                $lastTest = PlacementTest::create([
                    'GuestId' => $userId,
                    'LanguageId' => 1,
                    'Level' => 'Not Set',
                    'AudioScore' => 0,
                    'ReadingScore' => 0,
                    'SpeakingScore' => 0,
                    'TotalScore' => 0,
                ]);
            }
        }

        else {
            return response()->json([
                'message' => 'Unauthorized role for placement test.'
            ], 403);
        }

        $progress = PlacementTestProgress::where('PlacementTestId', $lastTest->id)
                                        ->orderByDesc('QuestionId')
                                        ->first();

        $nextQuestion = PlacementTestQuestion::with('answers')
            ->when($progress, fn($q) => $q->where('id', '>', $progress->QuestionId))
            ->orderBy('id')
            ->first();

        if (!$nextQuestion) {
            $lastTest->update([
                'Status' => 'Completed',
                'Level' => $this->determineLevel($lastTest->TotalScore),
            ]);

            return response()->json([
                'message' => 'Test Completed',
                'Level' => $lastTest->Level,
                'TotalScore' => $lastTest->TotalScore,
                'AudioScore' => $lastTest->AudioScore,
                'ReadingScore' => $lastTest->ReadingScore,
                'SpeakingScore' => $lastTest->SpeakingScore,
            ]);
        }

        return response()->json([
            'Question' => [
                'id' => $nextQuestion->id,
                'Section' => $nextQuestion->Section,
                'Context' => $nextQuestion->Context,
                'Media' => $nextQuestion->Media,
                'QuestionText' => $nextQuestion->QuestionText,
                'Answers' => $nextQuestion->answers->map(fn($a) => [
                    'id' => $a->id,
                    'AnswerText' => $a->AnswerText,
                ]),
            ]
        ]);
    }

    public function submitPTAnswer(Request $request)
    {
        $userId = auth()->id();

        $request->validate([
            'QuestionId' => 'required|exists:placement_test_questions,id',
            'SelectedAnswerId' => 'nullable|exists:placement_test_answers,id',
        ]);

        $test = PlacementTest::where('GuestId', $userId)
                            ->where('Status', 'Pending')
                            ->latest()->first();

        if (!$test) {
            return response()->json(['message' => 'You already completed the test.'], 400);
        }

        $question = PlacementTestQuestion::find($request->QuestionId);

        $answer = null;

        if ($request->SelectedAnswerId) {
            $answer = PlacementTestAnswer::find($request->SelectedAnswerId);

            if (!$answer || $answer->QuestionId != $question->id) {
                return response()->json(['message' => 'Answer does not belong to the question.'], 400);
            }
        }

        $alreadyAnswered = PlacementTestProgress::where('PlacementTestId', $test->id)
            ->where('QuestionId', $question->id)
            ->exists();

        if ($alreadyAnswered) {
            return response()->json(['message' => 'You already answered this question.'], 409);
        }

        PlacementTestProgress::create([
            'PlacementTestId' => $test->id,
            'QuestionId' => $question->id,
            'SelectedAnswerId' => $answer?->id,
        ]);

        if ($answer && $answer->isCorrect) {
            $test->increment('TotalScore');

            $scoreColumn = match ($question->Section) {
                'Listening' => 'AudioScore',
                'Reading' => 'ReadingScore',
                'LanguageUse' => 'SpeakingScore',
                default => null,
            };

            if ($scoreColumn) {
                $test->increment($scoreColumn);
            }
        }

        $sectionQuestionIds = PlacementTestQuestion::where('Section', $question->Section)->pluck('id')->toArray();
        $isLastInSection = $question->id === max($sectionQuestionIds);

        if ($isLastInSection) {
            $correctCount = PlacementTestAnswer::whereIn('QuestionId', $sectionQuestionIds)
                ->where('isCorrect', true)
                ->whereIn('id', function ($query) use ($test) {
                    $query->select('SelectedAnswerId')
                        ->from('placement_test_progress')
                        ->where('PlacementTestId', $test->id);
                })
                ->count();

            $scoreColumn = match ($question->Section) {
                'Listening' => 'AudioScore',
                'Reading' => 'ReadingScore',
                'LanguageUse' => 'SpeakingScore',
                default => null,
            };

            if ($scoreColumn) {
                $test->update([$scoreColumn => $correctCount]);
            }

            if ($question->Section === 'LanguageUse') {
                $totalScore = $test->TotalScore;
                $level = $this->determineLevel($totalScore);
                $test->update([
                    'Status' => 'Completed',
                    'Level' => $level,
                ]);
            }
        }

        return response()->json([
            'message' => $answer
                ? ($answer->isCorrect ? 'Correct' : 'Incorrect')
                : 'Time Over',
        ]);
    }

    private function determineLevel($score)
    {
        return match (true) {
            $score <= 5 => 'A.1.1',
            $score <= 10 => 'A.1.2',
            $score <= 15 => 'A.2.1',
            $score <= 20 => 'A.2.2',
            $score <= 25 => 'B.1.1',
            $score <= 30 => 'B.1.2',
            $score <= 35 => 'B.2.1',
            $score <= 40 => 'B.2.2',
            $score <= 45 => 'C.1.1',
            $score <= 50 => 'C.1.2',
            $score <= 60 => 'C.2.1',
            $score > 60 => 'C.2.2',
            default => 'Not set',
        };
    }

    public function addNote(Request $request)
    {
        $data = $request->validate([
            'Content' => 'required|string',
        ]);

        $data['StudentId'] = auth()->user()->id;

        $note = $this->studentService->addNote($data);

        return response()->json([
            'message' => 'Note added successfully.',
            'Note' => $note,
        ]);
    }

    public function editNote(Request $request, $noteId)
    {
        $data = $request->validate([
            'Content' => 'required|string',
        ]);

        $studentId = auth()->user()->id;

        $result = $this->studentService->editNote($studentId, $noteId, $data['Content']);

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], 403);
        }

        return response()->json([
            'message' => 'Note updated successfully.',
            'Note' => $result,
        ]);
    }

    public function deleteNote($noteId)
    {
        $studentId = auth()->user()->id;

        $result = $this->studentService->deleteNote($studentId, $noteId);

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], 403);
        }

        return response()->json(['message' => 'Note deleted successfully.']);
    }

    public function viewMyNotes()
    {
        $studentId = auth()->user()->id;

        $notes = $this->studentService->getMyNotes($studentId);

        if ($notes->isEmpty()) {
            return response()->json([
                'message' => 'You do not have any notes.'
            ]);
        }

        return response()->json([
            'message' => 'Notes retrieved successfully.',
            'Notes' => $notes,
        ]);
    }

    public function viewAllFlashCards()
    {
        $studentId = auth()->user()->id;
        $flashCards = $this->studentService->getAllFlashCards($studentId);

        return response()->json([
            'message' => 'All flashcards retrieved successfully.',
            'FlashCards' => $flashCards
        ]);
    }

    public function viewFlashCard($flashcardId)
    {
        $studentId = auth()->user()->id;
        $flashCard = $this->studentService->getFlashCard($studentId, $flashcardId);

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

    public function viewFlashCardsByLesson($lessonId)
    {
        $studentId = auth()->user()->id;

        $flashCards = $this->studentService->getFlashCardsByLesson($studentId, $lessonId);

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

    public function viewFlashCardsByCourse($courseId)
    {
        $studentId = auth()->user()->id;

        $flashCards = $this->studentService->getFlashCardsByCourse($studentId, $courseId);

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

    public function viewProgress()
    {
        $studentId = auth()->user()->id;

        $progress = $this->studentService->getProgress($studentId);

        return response()->json([
            'message' => 'Student progress retrieved successfully.',
            'Progress' => $progress
        ]);
    }

    public function viewRoadmap()
    {
        $guestId = auth()->user()->id;

        $roadmap = $this->studentService->getRoadmap($guestId);

        return response()->json([
            'Your Roadmap' => $roadmap
        ]);
    }

    public function studentLog()
    {
        $studentId = auth()->id();

        $courses = DB::table('enrollments')
            ->join('courses', 'enrollments.CourseId', '=', 'courses.id')
            ->join('course_schedules', 'courses.id', '=', 'course_schedules.CourseId')
            ->where('enrollments.StudentId', $studentId)
            ->select(
                'courses.id as CourseId',
                'courses.Description as CourseName',
                'course_schedules.Start_Date',
                'course_schedules.End_Date'
            )
            ->get();

        $log = [];

        foreach ($courses as $course) {
            //Final Grade
            $finalGrade = DB::table('final_grades')
                ->where('CourseId', $course->CourseId)
                ->where('StudentId', $studentId)
                ->value('FinalGrade');

            //Bonus only
            $bonus = DB::table('attendances')
                ->join('lessons', 'attendances.LessonId', '=', 'lessons.id')
                ->where('lessons.CourseId', $course->CourseId)
                ->where('attendances.StudentId', $studentId)
                ->sum('Bonus');

            //Attendance
            $lessonCount = DB::table('lessons')
                ->where('CourseId', $course->CourseId)
                ->count();

            $attendedLessons = DB::table('attendances')
                ->join('lessons', 'attendances.LessonId', '=', 'lessons.id')
                ->where('lessons.CourseId', $course->CourseId)
                ->where('attendances.StudentId', $studentId)
                ->count();

            $attendancePercentage = $lessonCount > 0 ? round(($attendedLessons / $lessonCount) * 100, 2) : 0;

            $log[] = [
                'CourseId' => $course->CourseId,
                'CourseName' => $course->CourseName,
                'StartDate' => $course->Start_Date,
                'EndDate' => $course->End_Date,
                'BonusScore' => $bonus,
                'FinalGrade' => $finalGrade ?? 'Not Graded',
                'AttendancePercentage' => $attendancePercentage . '%',
            ];
        }

        $studentName = auth()->user()->name;

        return response()->json([
            'message' => 'Student ' .$studentName. ' log retrieved successfully.',
            'data' => $log,
        ]);
    }

}
