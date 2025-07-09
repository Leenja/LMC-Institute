<?php

namespace App\Http\Controllers;

use App\Models\Language;
use App\Models\LMCInfo;
use App\Models\PlacementTest;
use App\Models\PlacementTestAnswer;
use App\Models\PlacementTestProgress;
use App\Models\PlacementTestQuestion;
use App\Models\SelfTestProgress;
use App\Models\SelfTestQuestion;
use App\Models\User;
use App\Services\StudentService;
use Illuminate\Http\Request;

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

        $courses = $this->studentService->getEnrolledCourses($studentId);

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

    public function takePlacementTest() {}

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

        $result = $this->studentService->submitFinalTestAnswer($studentId, $request->all());

        return response()->json($result);
    }

    public function getAllFinalTestQuestions($testId)
    {
        $studentId = auth()->id();

        $questions = $this->studentService->getAllFinalTestQuestions($studentId, $testId);

        return response()->json([
            'message' => 'All final test questions retrieved successfully.',
            'questions' => $questions,
        ]);
    }

    public function getAllPTQuestions()
    {
        $questions = PlacementTestQuestion::with(['answers' => function ($query) {
            $query->select('id', 'QuestionId', 'AnswerText');
        }])
        ->select('id', 'Section', 'Context','Media', 'QuestionText')->get();

        return response()->json([
            'Questions' => $questions
        ]);
    }

    public function getPTQuestion(){
        $userId = auth()->id();

        $test = PlacementTest::where('GuestId', $userId)
                            ->latest()
                            ->first();

        if ($test && $test->Status === 'Completed') {
            return response()->json([
                'message' => 'Test Completed',
                'Level' => $test->Level,
                'TotalScore' => $test->TotalScore,
                'AudioScore' => $test->AudioScore,
                'ReadingScore' => $test->ReadingScore,
                'SpeakingScore' => $test->SpeakingScore,
            ]);
        }

        if (!$test) {
            $test = PlacementTest::create([
                'GuestId' => $userId,
                'LanguageId' => 1,
                'Level' => 'Not Set',
                'AudioScore' => 0,
                'ReadingScore' => 0,
                'SpeakingScore' => 0,
                'TotalScore' => 0,
            ]);
        }

        $progress = PlacementTestProgress::where('PlacementTestId', $test->id)
                                        ->orderByDesc('QuestionId')
                                        ->first();

        $nextQuestion = PlacementTestQuestion::with('answers')
            ->when($progress, fn($q) => $q->where('id', '>', $progress->QuestionId))
            ->orderBy('id')
            ->first();

        if (!$nextQuestion) {
            $test->update([
                'Status' => 'Completed',
                'Level' => $this->determineLevel($test->TotalScore),
            ]);

            return response()->json([
                'message' => 'Test Completed',
                'Level' => $test->Level,
                'TotalScore' => $test->TotalScore,
                'AudioScore' => $test->AudioScore,
                'ReadingScore' => $test->ReadingScore,
                'SpeakingScore' => $test->SpeakingScore,
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
            'SelectedAnswerId' => 'required|exists:placement_test_answers,id',
        ]);

        $test = PlacementTest::where('GuestId', $userId)
                            ->where('Status', 'Pending')
                            ->latest()->first();

        if (!$test) {
            return response()->json(['message' => 'You already completed the test.'], 400);
        }

        $question = PlacementTestQuestion::find($request->QuestionId);
        $answer = PlacementTestAnswer::find($request->SelectedAnswerId);

        if ($answer->QuestionId != $question->id) {
            return response()->json(['message' => 'Answer does not belong to the question.'], 400);
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
            'SelectedAnswerId' => $answer->id,
        ]);

        if ($answer->isCorrect) {
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
            'message' => $answer->isCorrect ? 'Correct' : 'Incorrect',
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

    public function requestPrivateCourse() {}

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
}
