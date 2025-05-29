<?php

namespace App\Repositories;

use App\Models\Attendance;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Notes;
use App\Models\PlacementTest;
use App\Models\SelfTestQuestion;
use App\Models\StudentProgress;
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
    public function getNextSelfTestQuestion($selfTestId , $questionId)
    {
        return SelfTestQuestion::where('SelfTestId', $selfTestId)
                ->where('id',$questionId)
                ->select('id', 'Type', 'Media', 'QuestionText', 'Choices')->first();
    }

    //Note
    public function createNote($data) {
        return Notes::create($data);
    }

    public function updateNote($note, $content) {
        $note->Content = $content;
        $note->save();
        return $note;
    }

    public function deleteNote($note) {
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
