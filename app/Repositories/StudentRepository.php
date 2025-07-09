<?php

namespace App\Repositories;

use App\Models\Attendance;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\FinalTestAnswer;
use App\Models\FinalTestProgress;
use App\Models\Notes;
use App\Models\PlacementTest;
use App\Models\SelfTest;
use App\Models\SelfTestQuestion;
use App\Models\StudentProgress;
use App\Models\TestQuestion;
use Carbon\Carbon;

class StudentRepository
{
    //Student progress
    public function getEnrollmentsForStudent($studentId)
    {
        return Enrollment::where('StudentId', $studentId)
            ->with('course.Lesson')->get();
    }

    public function getUpcomingLessons($lessons)
    {
        return $lessons->where('Date', '>=', Carbon::today()->toDateString())
            ->sortBy('Date')->values();
    }

    public function getAttendanceCount($studentId, $lessons)
    {
        return Attendance::where('StudentId', $studentId)
            ->whereIn('LessonId', $lessons->pluck('id'))->count();
    }

    public function getStudentProgress($studentId, $courseId)
    {
        return StudentProgress::where('StudentId', $studentId)
            ->where('CourseId', $courseId)->first();
    }

    public function calculateProgress($studentId)
    {
        $enrollments = $this->getEnrollmentsForStudent($studentId);

        $result = [];

        foreach ($enrollments as $enrollment) {
            $course = $enrollment->course;
            $lessons = $course->Lesson;

            $studentProgress = $this->getStudentProgress($studentId, $course->id);

            $attendancePercentage = 0;
            $score = 0;

            if ($studentProgress) {
                $attendancePercentage = $studentProgress->Percentage;
                $score = $studentProgress->Score;
            }

            $totalLessons = $lessons->count();
            $attendedLessons = $this->getAttendanceCount($studentId, $lessons);

            $upcomingLessons = $this->getUpcomingLessons($lessons);

            $result[] = [
                'CourseId' => $course->id,
                'Total Lessons' => $totalLessons,
                'Attended Lessons' => $attendedLessons,
                'Attendance Percentage' => $attendancePercentage . '%',
                'Score' => $score,
                'Upcoming Lessons' => $upcomingLessons,
            ];
        }
        return $result;
    }

    //Take self test
    public function getNextSelfTestQuestion($selfTestId, $questionId)
    {
        return SelfTestQuestion::where('SelfTestId', $selfTestId)
            ->where('id', $questionId)
            ->select('id', 'Type', 'Media', 'QuestionText', 'Choices')->first();
    }

    public function findWithLessonAndCourse($selfTestId)
    {
        return SelfTest::with('Lesson.Course')->find($selfTestId);
    }

    public function getQuestionsBySelfTestId($selfTestId)
    {
        return SelfTestQuestion::where('SelfTestId', $selfTestId)->get();
    }

    public function getByLessonWithQuestions($lessonId)
    {
        return SelfTest::with('Questions')->where('LessonId', $lessonId)->get();
    }

    //Get final test question
    public function fetchNextFinalTestQuestion($studentId, $testId)
    {
        $answeredIds = FinalTestAnswer::where('StudentId', $studentId)
            ->where('TestId', $testId)
            ->pluck('QuestionId');

        $nextQuestion = TestQuestion::where('TestId', $testId)
            ->whereNotIn('id', $answeredIds)
            ->orderBy('id')
            ->first();

        if (!$nextQuestion) {
            return ['error' => 'You have completed this test.'];
        }

        return [
            'id' => $nextQuestion->id,
            'TestId' => $nextQuestion->TestId,
            'Type' => $nextQuestion->Type,
            'Point' => $nextQuestion->Point,
            'Media' => $nextQuestion->Media,
            'QuestionText' => $nextQuestion->QuestionText,
            'Choices' => in_array($nextQuestion->Type, ['MCQ', 'true_false', 'translate']) ? $nextQuestion->Choices : null,
        ];
    }

    //Take final test
    public function handleFinalTestAnswerSubmission($studentId, $data)
    {
        $question = TestQuestion::find($data['QuestionId']);

        if (!$question || $question->TestId != $data['TestId']) {
            throw new \Exception('Invalid question for this test.');
        }

        $isCorrect = $this->normalizeAnswer($data['Answer']) === $this->normalizeAnswer($question->CorrectAnswer);

        FinalTestAnswer::updateOrCreate(
            [
                'StudentId' => $studentId,
                'TestId' => $data['TestId'],
                'QuestionId' => $data['QuestionId'],
            ],
            [
                'Answer' => $data['Answer'],
                'isCorrect' => $isCorrect
            ]
        );

        $progress = FinalTestProgress::updateOrCreate(
            ['StudentId' => $studentId, 'TestId' => $data['TestId']],
            ['LastAnsweredQuestionId' => $data['QuestionId']]
        );

        // Update score if correct
        if ($isCorrect) {
            $scoreIncrement = $question->Point ?? 0;
            $progress->increment('Score', $scoreIncrement);
        }

        $hasNext = TestQuestion::where('TestId', $data['TestId'])
            ->whereNotIn('id', FinalTestAnswer::where('StudentId', $studentId)
                ->where('TestId', $data['TestId'])
                ->pluck('QuestionId'))
            ->exists();

        return array_filter([
            'message' => $isCorrect ? 'Correct answer!' : 'Wrong answer!',
            'correctAnswer' => $isCorrect ? null : $question->CorrectAnswer,
            'nextAvailable' => $hasNext
        ], fn($value) => !is_null($value));
    }

    //Get all final test answers
    public function fetchAllFinalTestQuestions($studentId, $testId)
    {
        return TestQuestion::where('TestId', $testId)
            ->select('id', 'TestId','Type','Point','Media','QuestionText','Choices')
            ->orderBy('id')->get();
    }

    protected function normalizeAnswer($answer)
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $answer)));
    }

    //Note
    public function createNote($data)
    {
        return Notes::create($data);
    }

    public function updateNote($note, $content)
    {
        $note->Content = $content;
        $note->save();
        return $note;
    }

    public function deleteNote($note)
    {
        $note->delete();
        return true;
    }

    //View my roadmap as a guest
    public function getRoadmapCourses($guestId)
    {
        $placementTest = PlacementTest::where('GuestId', $guestId)
            ->where('Status', 'Completed')->latest()->first();

        if (!$placementTest) {
            return ['message' => 'Placement test record not found'];
        }

        $currentLevel = $placementTest->Level;
        $languageId = $placementTest->LanguageId;

        return Course::where('LanguageId', $languageId)
            ->get()->filter(function ($course) use ($currentLevel) {
                return $this->CompareLevel($course->Level, $currentLevel) >= 0;
            })->values();
    }

    protected function compareLevel($levelA, $levelB)
    {
        $partsA = explode('.', $levelA);
        $partsB = explode('.', $levelB);

        for ($i = 0; $i < max(count($partsA), count($partsB)); $i++) {
            $a = $partsA[$i] ?? 0;
            $b = $partsB[$i] ?? 0;

            if (is_numeric($a) && is_numeric($b)) {
                if ((int)$a !== (int)$b) {
                    return (int)$a - (int)$b;
                }
            } else {
                // For letter comparison like A, B, C
                if ($a !== $b) {
                    return strcmp($a, $b);
                }
            }
        }

        return 0;
    }
}
