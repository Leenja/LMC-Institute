<?php

namespace App\Http\Controllers;

use App\Models\Language;
use App\Models\LMCInfo;
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

    public function viewLMCInfo() {
        $info = LMCInfo::latest()->first();
        $teachers = User::where('role_id',3)->get();
        $languages = Language::all();

        return response()->json([
            'Title' => $info->Title,
            'Description' => $info->Descriptions ? json_decode($info->Descriptions) : [],
            'Photo' => $info->Photo,
            'Teachers' => $teachers,
            'Languages' => $languages,
        ]);
    }

    public function viewEnrolledCourses() {
        $studentId = auth()->user()->id;

        $courses = $this->studentService->getEnrolledCourses($studentId);

        return response()->json([
            'message' => 'Enrolled courses retrieved successfully.',
            'Courses' => $courses,
        ]);
    }

    public function viewMyLessons($courseId) {
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

    public function viewTeachers() {
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

    public function viewTeacher($teacherId){
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

    public function viewAvailableCourses() {
        $courses = $this->studentService->getAvailableCourses();

        return response()->json([
            'message' => 'Available courses retrieved successfully.',
            'Available Courses' => $courses
        ]);
    }

    public function takePlacementTest() {

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

    public function viewMyNotes() {
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

    public function viewAllFlashCards() {
        $studentId = auth()->user()->id;
        $flashCards = $this->studentService->getAllFlashCards($studentId);

        return response()->json([
            'message' => 'All flashcards retrieved successfully.',
            'FlashCards' => $flashCards
        ]);
    }

    public function viewFlashCard($flashcardId) {
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

    public function requestPrivateCourse() {

    }

    public function viewProgress() {
        $studentId = auth()->user()->id;

        $progress = $this->studentService->getProgress($studentId);

        return response()->json([
            'message' => 'Student progress retrieved successfully.',
            'Progress' => $progress
        ]);
    }

    public function viewRoadmap() {
        $guestId = auth()->user()->id;

        $roadmap = $this->studentService->getRoadmap($guestId);

        return response()->json([
            'Your Roadmap' => $roadmap
        ]);
    }
}
