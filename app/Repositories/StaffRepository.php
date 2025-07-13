<?php

namespace App\Repositories;

use App\Models\Attendance;
use App\Models\Course;
use App\Models\User;
use App\Models\Enrollment;
use App\Models\FlashCard;
use App\Models\Lesson;
use App\Models\SelfTest;
use App\Models\StudentProgress;
use App\Models\Test;
use App\Models\TestQuestion;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class StaffRepository
{

    //Secretary----------------------------------------------

    //Enrollment

    public function updateUserRole($studentId, $role_id)
    {
        $user = User::findOrFail($studentId);

        $user->role_id = $role_id;
        $user->save();

        $role = Role::findById($role_id, 'sanctum');
        $user->syncRoles($role->name);

        return $user;
    }

    public function createEnrollment($data)
    {
        return Enrollment::create([
            'StudentId' => $data['StudentId'],
            'CourseId' => $data['CourseId'],
            'isPrivate' => $data['isPrivate'],
        ]);
    }

    public function deleteEnrollment($studentId, $courseId)
    {
        Enrollment::where('StudentId', $studentId)
            ->where('CourseId', $courseId)->delete();
    }

    public function getEnrolledStudentsInCourse($courseId)
    {
        return Enrollment::where('CourseId', $courseId)
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
        $students = DB::table('enrollments')
        ->join('courses', 'enrollments.CourseId', '=', 'courses.id')
        ->join('users', 'enrollments.StudentId', '=', 'users.id')
        ->where('courses.LanguageId', $languageId)
        ->select('users.id as StudentId', 'users.name as StudentName', 'users.email')
        ->distinct()->get();

        return [
            'count' => $students->count(),
            'students' => $students,
        ];
    }

    //Add course
    public function createCourse($data)
    {
        return Course::create([
            'TeacherId' => $data['TeacherId'],
            'LanguageId' => $data['LanguageId'],
            'Description' => $data['Description'],
            'Photo' => $data['Photo'] ?? null,
            'Level' => $data['Level'],
            'Status' => 'Unactive',
        ]);
    }

    public function createSchedule($courseId, $data)
    {
        return DB::table('course_schedules')->insert([
            'CourseId' => $courseId,
            'RoomId' => $data['RoomId'],
            'Start_Enroll' => $data['Start_Enroll'],
            'End_Enroll' => $data['End_Enroll'],
            'Enroll_Status' => 'Open',
            'Start_Date' => $data['Start_Date'],
            'End_Date' => $data['End_Date'],
            'Start_Time' => $data['Start_Time'],
            'End_Time' => $data['End_Time'],
            'CourseDays' => json_encode($data['CourseDays']),
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }

    public function calculateCourseEndDate($startDate, $daysOfWeek, $numberOfLessons)
    {
        $date = Carbon::parse($startDate);
        $count = 0;

        while ($count < $numberOfLessons) {
            if (in_array($date->format('D'), $daysOfWeek)) {
                $count++;
            }
            $date->addDay();
        }

        return $date->subDay(); // go back to last valid day
    }

    //Edit course
    public function updateCourseSchedule($courseId, $data)
    {
        return DB::table('course_schedules')
            ->where('CourseId', $courseId)
            ->update([
                'RoomId' => $data['RoomId'],
                'Start_Enroll' => $data['Start_Enroll'],
                'End_Enroll' => $data['End_Enroll'],
                'Start_Date' => $data['Start_Date'],
                'End_Date' => $data['End_Date'],
                'Start_Time' => $data['Start_Time'],
                'End_Time' => $data['End_Time'],
                'CourseDays' => json_encode($data['CourseDays']),
                'updated_at' => now(),
            ]);

        if (!empty($data['Photo'])) {
            Course::where('id', $courseId)->update([
                'Photo' => $data['Photo'],
                'updated_at' => now(),
            ]);
        }
    }

    //Conflict course schedule
    public function checkCourseScheduleConflict($roomId, $startDate, $endDate, $courseDays, $startTime, $endTime)
    {
        $courseDays = (array) $courseDays;

        return DB::table('course_schedules')
            ->where('RoomId', $roomId)
            ->where(function ($query) use ($startDate, $endDate, $courseDays, $startTime, $endTime) {
                $query->where(function ($q) use ($startDate, $endDate) {
                    $q->whereBetween('Start_Date', [$startDate, $endDate])
                        ->orWhereBetween('End_Date', [$startDate, $endDate])
                        ->orWhere(function ($q2) use ($startDate, $endDate) {
                            $q2->where('Start_Date', '<=', $endDate)
                                ->where('End_Date', '>=', $startDate);
                        });
                });

                $query->where(function ($q) use ($courseDays) {
                    foreach ($courseDays as $day) {
                        $q->orWhereRaw("JSON_CONTAINS(CourseDays, '\"$day\"')");
                    }
                });

                $query->where(function ($q) use ($startTime, $endTime) {
                    $q->where(function ($timeQ) use ($startTime, $endTime) {
                        $timeQ->where('Start_Time', '<', $endTime)
                              ->where('End_Time', '>', $startTime);
                    });
                });
            })
            ->exists();
    }

    //Conflict teacher
    public function checkTeacherScheduleConflict($teacherId, $startDate, $endDate, $courseDays, $startTime, $endTime)
    {
        $courseDays = (array) $courseDays;

        return DB::table('course_schedules')
            ->join('courses', 'courses.id', '=', 'course_schedules.CourseId')
            ->where('courses.TeacherId', $teacherId)
            ->where(function ($query) use ($startDate, $endDate, $courseDays, $startTime, $endTime) {
                $query->where(function ($q) use ($startDate, $endDate) {
                    $q->whereBetween('Start_Date', [$startDate, $endDate])
                    ->orWhereBetween('End_Date', [$startDate, $endDate])
                    ->orWhere(function ($q2) use ($startDate, $endDate) {
                        $q2->where('Start_Date', '<=', $endDate)
                            ->where('End_Date', '>=', $startDate);
                    });
                });

                $query->where(function ($q) use ($courseDays) {
                    foreach ($courseDays as $day) {
                        $q->orWhereRaw("JSON_CONTAINS(CourseDays, '\"$day\"')");
                    }
                });

                $query->where(function ($q) use ($startTime, $endTime) {
                    $q->where('Start_Time', '<', $endTime)
                    ->where('End_Time', '>', $startTime);
                });
            })
            ->exists();
    }

    //Delete course
    public function deleteCourseAndLessons($course)
    {
        DB::table('flash_cards')->where('CourseId', $course->id)->delete();
        DB::table('enrollments')->where('CourseId', $course->id)->delete();

        Lesson::where('CourseId', $course->id)->delete();

        DB::table('course_schedules')->where('CourseId', $course->id)->delete();

        $course->delete();
    }

    //Teacher-------------------------------------------------------

    //Review todays schedule

    public function getScheduleByDay($teacherId, $today)
    {
        return Lesson::whereDate('Date', $today)
        ->whereHas('Course', function ($query) use ($teacherId) {
            $query->where('TeacherId', $teacherId);
        })
        ->with('Course.Language','Course.User')->get();
    }

    //Update student's progress
    public function updateStudentProgress($studentId, $courseId)
    {
        $lessons = Lesson::where('CourseId', $courseId)->get();

        $attendances = Attendance::where('StudentId', $studentId)
            ->whereIn('LessonId', $lessons->pluck('id'))->get();

        $totalLessons = $lessons->count();
        $attendedLessons = $attendances->count();

        $attendancePercentage = $totalLessons > 0
            ? round(($attendedLessons / $totalLessons) * 100, 2)
            : 0;

        $totalBonus = $attendances->sum('Bonus');

        $score = $totalBonus;  //Later we can add test marks here

        $studentProgress = StudentProgress::where('StudentId', $studentId)
            ->where('CourseId', $courseId)->first();

        if ($studentProgress) {
            $studentProgress->Percentage = $attendancePercentage;
            $studentProgress->Score = $score;
            $studentProgress->save();
        } else {
            StudentProgress::create([
                'StudentId' => $studentId,
                'CourseId' => $courseId,
                'Percentage' => $attendancePercentage,
                'Score' => $score,
            ]);
        }
    }

    //Flash cards
    public function createFlashCard($data)
    {
        $lesson = Lesson::findOrFail($data['LessonId']);
        $courseId = $lesson->CourseId;

        return Flashcard::create([
            'LessonId' => $data['LessonId'],
            'CourseId' => $courseId,
            'Content' => $data['Content'],
            'Translation' => $data['Translation'],
        ]);
    }

    public function updateFlashCard($data)
    {
        $flashcard = Flashcard::findOrFail($data['FlashcardId']);
        $flashcard->update([
            'Content' => $data['Content'],
            'Translation' => $data['Translation'],
        ]);

        return $flashcard;
    }

    public function deleteFlashCard($flashcardId)
    {
        $flashcard = Flashcard::findOrFail($flashcardId);
        $flashcard->delete();

        return true;
    }

    //Add,edit,delete Self Test
    public function createSelfTest($data)
    {
        return SelfTest::create([
            'LessonId' => $data['LessonId'],
            'Title' => $data['Title'],
            'Description' => $data['Description'],
        ]);
    }

    public function updateSelfTest($data)
    {
        $selftest = SelfTest::findOrFail($data['SelfTestId']);

        $selftest->update([
            'Title' => $data['Title'],
            'Description' => $data['Description'],
        ]);

        return $selftest;
    }

    public function deleteSelfTest($selftestId)
    {
        $selftest = SelfTest::findOrFail($selftestId);
        $selftest->delete();

        return true;
    }

        public function createFinalTest($data, $teacherId)
    {
        return Test::create([
            'CourseId' => $data['CourseId'],
            'TeacherId' => $teacherId,
            'Title' => $data['Title'],
            'Duration' => $data['Duration'],
            'Mark' => $data['Mark'],
        ]);
    }

    public function updateFinalTest($data, $teacherId)
    {
        $test = Test::find($data['TestId']);

        if (!$test || $test->TeacherId !== $teacherId) {
            throw new Exception('You are not authorized to edit this test');
        }

        $test->update($data);
        return $test;
    }

    public function deleteFinalTest($id, $teacherId)
    {
        $test = Test::find($id);

        if (!$test || $test->TeacherId !== $teacherId) {
            throw new Exception('You are not authorized to delete this test');
        }

        $test->delete();
    }

    public function createFinalTestQuestion($data, $mediaFile, $teacherId)
    {
        $test = Test::find($data['TestId']);
        if (!$test || $test->TeacherId !== $teacherId) {
            throw new Exception('You are not authorized to add questions to this test');
        }

        if ($mediaFile) {
            $fileName = time() . '_' . $mediaFile->getClientOriginalName();
            $mediaFile->move(public_path('storage/FinalTestsMedia'), $fileName);
            $data['Media'] = url('storage/FinalTestsMedia/' . $fileName);
        }

        return TestQuestion::create($data);
    }

    public function updateFinalTestQuestion($data, $mediaFile, $teacherId)
    {
        $question = TestQuestion::with('test')->find($data['TestQuestionId']);

        if (!$question || $question->Test->TeacherId !== $teacherId) {
            throw new Exception('You are not authorized to edit this test question');
        }

        if ($mediaFile) {
            $fileName = time() . '_' . $mediaFile->getClientOriginalName();
            $mediaFile->move(public_path('storage/FinalTestsMedia'), $fileName);
            $data['Media'] = url('storage/FinalTestsMedia/' . $fileName);
        }

        $question->update($data);
        return $question;
    }

    public function deleteFinalTestQuestion($id, $teacherId)
    {
        $question = TestQuestion::with('test')->find($id);

        if (!$question || $question->Test->TeacherId !== $teacherId) {
            throw new Exception('You are not authorized to delete this test question');
        }

        $question->delete();
    }

    public function getFinalTestQuestions($testId, $teacherId)
    {
        $test = Test::where('id', $testId)->where('TeacherId', $teacherId)->first();
        if (!$test) {
            throw new Exception('You are not authorized to view questions for this test');
        }
        return $test->Questions;
    }

    public function getFinalTestQuestion($questionId, $teacherId)
    {
        $question = TestQuestion::with('test')
            ->where('id', $questionId)
            ->first();

        if (!$question || $question->test->TeacherId !== $teacherId) {
            throw new Exception('You are not authorized to view this question');
        }
        return $question;
    }
}
