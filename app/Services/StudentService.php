<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Course;
use App\Models\CourseSchedule;
use App\Models\Enrollment;
use App\Models\FlashCard;
use App\Models\Lesson;
use App\Models\Notes;
use App\Models\SelfTest;
use App\Models\SelfTestProgress;
use App\Models\SelfTestQuestion;
use App\Models\Test;
use App\Models\User;
use App\Repositories\StudentRepository;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StudentService
{
    private $studentRepository;

    public function __construct(StudentRepository $studentRepository)
    {
        $this->studentRepository = $studentRepository;
    }

    //View my courses
    public function getEnrolledCourses($studentId)
    {
        return Enrollment::where('StudentId', $studentId)
            ->with('course.CourseSchedule.Room', 'course.Language', 'course.User')->get()->pluck('course');
    }

    //View my lessons for a course
    public function getMyLessons($studentId, $courseId)
    {
        $isEnrolled = Enrollment::where('StudentId', $studentId)->where('CourseId', $courseId)->exists();

        if (!$isEnrolled) {
            return ['error' => 'You are not enrolled in this course.'];
        }

        return Lesson::where('CourseId', $courseId)->with('Course.CourseSchedule.Room', 'Course.Language', 'Course.User')->get();
    }

    //View teachers
    public function getAllTeachers()
    {
        return User::role('Teacher')
            ->select('id', 'name', 'email')
            ->with(['staffInfo:id,UserId,Photo,Description'])
            ->get();
    }

    public function getAvailableCourses()
    {
        $currentDate = Carbon::today();

        $courses = Course::with(['CourseSchedule.Room', 'User', 'Language'])
            ->where('Status', 'Unactive')
            ->whereHas('CourseSchedule', function ($query) use ($currentDate) {
                $query->where('Start_Date', '>=', $currentDate)
                    ->where('End_Enroll', '>=', $currentDate);
            })
            ->get()
            ->map(function ($course) {
                $schedule = $course->CourseSchedule->first();

                return [
                    'id' => $course->id,
                    'TeacherName' => $course->User->name ?? null,
                    'LanguageName' => $course->Language->Name ?? null,
                    'Description' => $course->Description,
                    'Photo' => $course->Photo,
                    'Status' => $course->Status,
                    'Level' => $course->Level,
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

        return $courses;
    }

    public function getTeacher($teacherId)
    {
        return User::role('Teacher')
            ->where('id', $teacherId)
            ->select('id', 'name', 'email')
            ->with(['staffInfo:id,UserId,Photo,Description'])
            ->first();
    }

    //Take self test
    public function getSelfTestQuestions($studentId, $selfTestId)
    {
        $selfTest = SelfTest::with('Lesson.Course')->find($selfTestId);
        if (!$selfTest) {
            return ['error' => 'Self test not found'];
        }

        $course = $selfTest->Lesson->Course;

        $isEnrolled = Enrollment::where('StudentId', $studentId)
            ->where('CourseId', $course->id)->exists();

        if (!$isEnrolled) {
            return ['error' => 'You are not enrolled in this course'];
        }

        $progress = SelfTestProgress::where('StudentId', $studentId)->where('SelfTestId', $selfTestId)->first();

        $lastAnswered = $progress?->LastAnsweredQuestionId;

        $allQuestions = SelfTestQuestion::where('SelfTestId', $selfTestId)
            ->orderBy('id')->pluck('id')->toArray();

        $nextQuestion = $lastAnswered
            ? collect($allQuestions)->first(fn($id) => $id > $lastAnswered)
            : $allQuestions[0] ?? null;

        if (!$nextQuestion) {
            return ['message' => 'Test completed'];
        }

        return $this->studentRepository->getNextSelfTestQuestion($selfTestId, $nextQuestion);
    }

    public function getSelfTest_ALL_Questions($studentId, $selfTestId)
    {
        $selfTest = $this->studentRepository->findWithLessonAndCourse($selfTestId);

        if (!$selfTest) {
            return ['error' => 'Self test not found'];
        }

        $course = $selfTest->Lesson->Course ?? null;

        if (!$course) {
            return ['error' => 'Course not found for this lesson'];
        }

        $questions = $this->studentRepository->getQuestionsBySelfTestId($selfTestId);

        $questions->transform(function ($question) {
            if ($question->Type === 'MCQ' && is_string($question->Choices)) {
                $question->Choices = json_decode($question->Choices);
            }
            return $question;
        });

        return [
            'SelfTest' => [
                'id' => $selfTest->id,
                'Title' => $selfTest->Title,
                'Description' => $selfTest->Description,
                'created_at' => $selfTest->created_at,
            ],
            'Lesson' => [
                'id' => $selfTest->Lesson->id,
                'Title' => $selfTest->Lesson->Title,
                'Date' => $selfTest->Lesson->Date,
                'StartTime' => $selfTest->Lesson->Start_Time,
                'EndTime' => $selfTest->Lesson->End_Time,
                'CourseTitle' => $course->Title,
            ],
            'Questions' => $questions,
        ];
    }

    public function getSelfTestsByLesson($lessonId)
    {
        $selfTests = $this->studentRepository->getByLessonWithQuestions($lessonId);

        if ($selfTests->isEmpty()) {
            return ['error' => 'No self tests found for this lesson'];
        }

        $selfTests->transform(function ($selfTest) {
            $selfTest->Questions->transform(function ($question) {
                if ($question->Type === 'MCQ' && is_string($question->Choices)) {
                    $question->Choices = json_decode($question->Choices);
                }
                return $question;
            });
            return [
                'id' => $selfTest->id,
                'Title' => $selfTest->Title,
                'Description' => $selfTest->Description,
                'created_at' => $selfTest->created_at,
                'Questions' => $selfTest->Questions,
            ];
        });

        return $selfTests;
    }

    //Get final test questions
    public function getNextFinalTestQuestion($studentId, $testId)
    {
        return DB::transaction(function () use ($studentId, $testId) {
            // Check if course has ended
            $test = Test::with('Course')->findOrFail($testId);

            $courseSchedule = CourseSchedule::where('CourseId', $test->CourseId)->first();

            if (!$courseSchedule || $courseSchedule->End_Date > now()->toDateString()) {
                return ['error' => 'You cannot take this test until the course is finished.'];
            }
            return $this->studentRepository->fetchNextFinalTestQuestion($studentId, $testId);
        });
    }

    //Answer final test questions
    public function submitFinalTestAnswer($studentId, $data)
    {
        return DB::transaction(function () use ($studentId, $data) {
            $test = Test::with('Course')->findOrFail($data['TestId']);

            $courseSchedule = CourseSchedule::where('CourseId', $test->CourseId)->first();

            if (!$courseSchedule || $courseSchedule->End_Date > now()->toDateString()) {
                throw new \Exception('You cannot submit answers until the course is finished.');
            }

            return $this->studentRepository->handleFinalTestAnswerSubmission($studentId, $data);
        });
    }

    //Get all final test answers
    public function getAllFinalTestQuestions($studentId, $testId)
    {
        return DB::transaction(function () use ($studentId, $testId) {
            return $this->studentRepository->fetchAllFinalTestQuestions($studentId, $testId);
        });
    }

    //Can only access when it is last day and hour in course
    public function canAccessFinalTest($studentId, $courseId): bool
    {
        $now = now();

        $courseSchedule = CourseSchedule::where('CourseId', $courseId)
            ->whereHas('course.Enrollment', function ($q) use ($studentId) {
                $q->where('StudentId', $studentId);
            })
            ->first();

        if (!$courseSchedule) {
            return false;
        }

        $endDate = Carbon::parse($courseSchedule->End_Date);
        $startTime = Carbon::parse($courseSchedule->End_Time)->subHour();
        $endTime = Carbon::parse($courseSchedule->End_Time);

        $startWindow = $endDate->copy()->setTimeFrom($startTime);
        $endWindow = $endDate->copy()->setTimeFrom($endTime);

        Log::debug('FinalTestGate - time check', [
            'now'         => $now->toDateTimeString(),
            'endDate'     => $endDate->toDateTimeString(),
            'startWindow' => $startWindow->toDateTimeString(),
            'endWindow'   => $endWindow->toDateTimeString(),
        ]);

        if (!$now->between($startWindow, $endWindow)) {
            Log::debug('FinalTestGate - outside window');
            return false;
        }

        $hasAttendance = Attendance::where('StudentId', $studentId)
            ->whereDate('created_at', $endDate->toDateString())
            ->exists();

        Log::debug('FinalTestGate - attendance check', [
            'studentId'    => $studentId,
            'attendanceOk' => $hasAttendance,
        ]);

        return $hasAttendance;
    }

    public function getFinalTest($courseId)
    {
        return Test::select('id', 'CourseId', 'TeacherId', 'Title', 'Duration', 'Mark')
            ->with(['User:id,name'])
            ->where('CourseId', $courseId)
            ->first()
            ->makeHidden('TeacherId');
    }

    //View flash cards
    public function getAllFlashCards($studentId)
    {
        $courseIds = Enrollment::where('StudentId', $studentId)->pluck('CourseId');

        return FlashCard::whereIn('CourseId', $courseIds)->get();
    }

    public function getFlashCard($studentId, $flashCardId)
    {
        $flashCard = FlashCard::find($flashCardId);

        if (!$flashCard) {
            return null;
        }

        $isEnrolled = Enrollment::where('StudentId', $studentId)
            ->where('CourseId', $flashCard->CourseId)->exists();

        return $isEnrolled ? $flashCard : null;
    }

    public function getFlashCardsByLesson($studentId, $lessonId)
    {
        $lesson = Lesson::find($lessonId);

        if (!$lesson) {
            return null;
        }

        $isEnrolled = Enrollment::where('StudentId', $studentId)
            ->where('CourseId', $lesson->CourseId)->exists();

        if (!$isEnrolled) {
            return null;
        }

        return FlashCard::where('LessonId', $lessonId)->get();
    }

    public function getFlashCardsByCourse($studentId, $courseId)
    {
        $isEnrolled = Enrollment::where('StudentId', $studentId)
            ->where('CourseId', $courseId)->exists();

        if (!$isEnrolled) {
            return null;
        }

        return FlashCard::where('CourseId', $courseId)->get();
    }

    //Note
    public function addNote($data)
    {
        return $this->studentRepository->createNote($data);
    }

    public function editNote($studentId, $noteId, $content)
    {
        $note = Notes::find($noteId);

        if (!$note || $note->StudentId !== $studentId) {
            return ['error' => 'Note not found.'];
        }

        return $this->studentRepository->updateNote($note, $content);
    }

    public function deleteNote($studentId, $noteId)
    {
        $note = Notes::find($noteId);

        if (!$note || $note->StudentId !== $studentId) {
            return ['error' => 'Note not found or unauthorized.'];
        }

        return $this->studentRepository->deleteNote($note);
    }

    public function getMyNotes($studentId)
    {
        return Notes::where('StudentId', $studentId)->latest()->get();
    }

    //View progress
    public function getProgress($studentId)
    {
        return $this->studentRepository->calculateProgress($studentId);
    }

    //View my rroadmap as a guest
    public function getRoadmap($guestId)
    {
        return $this->studentRepository->getRoadmapCourses($guestId);
    }
}
