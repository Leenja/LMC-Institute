<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessPlacementFile;
use App\Models\Course;
use App\Models\CourseSchedule;
use App\Models\LMCInfo;
use App\Models\PlacementTestAnswer;
use App\Models\PlacementTestQuestion;
use App\Models\User;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ManagerController extends Controller
{
    public function uploadQuestions(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png',
        ]);

        $file = $request->file('file');
        $fileName = uniqid() . '.' . $file->getClientOriginalExtension();
        $destinationPath = storage_path('app/placement');

        if (!file_exists($destinationPath)) {
            mkdir($destinationPath, 0777, true);
            Log::info("Created placement directory at: $destinationPath");
        }

        if ($file->move($destinationPath, $fileName)) {
            Log::info("File moved successfully to: placement/$fileName");

            ProcessPlacementFile::dispatchSync('placement/' . $fileName);

            return response()->json(['message' => 'File received and will be processed'], 200);
        } else {
            Log::error("Failed to move uploaded file to: " . $destinationPath . DIRECTORY_SEPARATOR . $fileName);
            return response()->json(['message' => 'File upload failed'], 500);
        }
    }

    public function markCorrectAnswer($answerId)
    {
        $answer = PlacementTestAnswer::find($answerId);

        if (!$answer) {
            return response()->json(['message' => 'Answer not found'], 404);
        }

        $questionId = $answer->QuestionId;

        DB::beginTransaction();
        try {
            if ($answer->isCorrect) {
                $answer->update(['isCorrect' => false]);

                DB::commit();
                return response()->json(['message' => 'Correct answer unmarked'], 200);
            } else {
                PlacementTestAnswer::where('QuestionId', $questionId)
                    ->update(['isCorrect' => false]);

                $answer->update(['isCorrect' => true]);

                DB::commit();
                return response()->json(['message' => 'Answer marked as correct'], 200);
            }
        } catch (Exception $e) {
            DB::rollback();
            return response()->json(['message' => 'Failed to toggle correct answer'], 500);
        }
    }

    public function addOrUpdatePTMedia(Request $request, $id)
    {
        $request->validate([
            'Media' => 'required|file|mimetypes:video/mp4,audio/mp4,audio/mpeg,audio/x-m4a,audio/aac,audio/wav,audio/x-wav,image/jpeg,image/png|max:10240',
        ]);

        $question = PlacementTestQuestion::find($id);

        if (!$question) {
            return response()->json(['message' => 'Question not found'], 404);
        }

        try {
            $media = $request->file('Media');
            $fileName = time() . '_' . $media->getClientOriginalName(); // بدون أي فلترة

            $media->move(public_path('storage/PTMedia'), $fileName);

            if (!file_exists(public_path('storage/PTMedia/' . $fileName))) {
                throw new Exception('Failed to upload media');
            }

            $mediaUrl = url('storage/PTMedia/' . $fileName);

            $question->update([
                'Media' => $mediaUrl,
            ]);

            return response()->json([
                'message' => 'Media uploaded and linked to the question successfully',
                'media_url' => $mediaUrl
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'message' => 'Failed to upload media',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getPTQuestionsWithAnswers()
    {
        $questions = PlacementTestQuestion::with('answers')->get();

        return response()->json($questions);
    }

    public function getPTQuestion($id)
    {
        $question = PlacementTestQuestion::with('answers')->find($id);

        if (!$question) {
            return response()->json(['message' => 'Question not found'], 404);
        }

        return response()->json($question, 200);
    }

    public function addPlacementTestQuestion(Request $request)
    {
        $validated = $request->validate([
            'Section' => 'required|in:Listening,Reading,LanguageUse',
            'Context' => 'nullable|string',
            'QuestionText' => 'required|string',
            'Answers' => 'required|array|size:4',
            'Answers.*.AnswerText' => 'required|string',
            'Answers.*.isCorrect' => 'required|boolean',
            'Media' => 'nullable|file|mimetypes:video/mp4,audio/mp4,audio/mpeg,audio/x-m4a,audio/aac,audio/wav,audio/x-wav,image/jpeg,image/png|max:10240',
        ]);

        DB::beginTransaction();
        try {
            $mediaUrl = null;

            if ($request->hasFile('Media')) {
                $media = $request->file('Media');
                $new_name = time() . '_' . $media->getClientOriginalName();
                $media->move(public_path('storage/PTMedia'), $new_name);

                if (!file_exists(public_path('storage/PTMedia/' . $new_name))) {
                    throw new Exception('Failed to upload media', 500);
                }

                $mediaUrl = url('storage/PTMedia/' . $new_name);
            }

            $question = PlacementTestQuestion::create([
                'Section' => $validated['Section'],
                'Context' => $validated['Context'] ?? null,
                'QuestionText' => $validated['QuestionText'],
                'Media' => $mediaUrl,
            ]);

            foreach ($validated['Answers'] as $answerData) {
                $question->answers()->create([
                    'AnswerText' => $answerData['AnswerText'],
                    'isCorrect' => $answerData['isCorrect'],
                ]);
            }
            DB::commit();
            return response()->json(['message' => 'Question and answers added successfully'], 201);

        } catch (Exception $e) {
            DB::rollback();
            return response()->json(['message' => 'Failed to add question', 'error' => $e->getMessage()], 500);
        }
    }

    public function editPlacementTestQuestion(Request $request, $id)
    {
        $validated = $request->validate([
            'Section' => 'sometimes|in:Listening,Reading,LanguageUse',
            'Context' => 'sometimes|nullable|string',
            'QuestionText' => 'sometimes|string',
            'Answers' => 'sometimes|array|size:4',
            'Answers.*.AnswerText' => 'required_with:Answers|string',
            'Answers.*.isCorrect' => 'required_with:Answers|boolean',
            'Media' => 'nullable|file|mimetypes:video/mp4,audio/mp4,audio/mpeg,audio/x-m4a,audio/aac,audio/wav,audio/x-wav,image/jpeg,image/png|max:10240',
        ]);

        $question = PlacementTestQuestion::with('answers')->find($id);

        if (!$question) {
            return response()->json(['message' => 'Question not found'], 404);
        }

        DB::beginTransaction();
        try {
            $updateData = array_filter([
                'Section' => $validated['Section'] ?? null,
                'Context' => $validated['Context'] ?? null,
                'QuestionText' => $validated['QuestionText'] ?? null,
            ], fn($v) => !is_null($v));

            if ($request->hasFile('Media')) {
                $media = $request->file('Media');
                $new_name = time() . '_' . $media->getClientOriginalName();
                $media->move(public_path('storage/PTMedia'), $new_name);

                if (!file_exists(public_path('storage/PTMedia/' . $new_name))) {
                    throw new Exception('Failed to upload media', 500);
                }

                $mediaUrl = url('storage/PTMedia/' . $new_name);
                $updateData['Media'] = $mediaUrl;
            }

            $question->update($updateData);

            if (isset($validated['Answers'])) {
                $question->answers()->delete();

                foreach ($validated['Answers'] as $answerData) {
                    $question->answers()->create([
                        'AnswerText' => $answerData['AnswerText'],
                        'isCorrect' => $answerData['isCorrect'],
                    ]);
                }
            }
            DB::commit();
            return response()->json(['message' => 'Question updated successfully'], 200);

        } catch (Exception $e) {
            DB::rollback();
            return response()->json([
                'message' => 'Failed to update question',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function deletePlacementTestQuestion($id)
    {
        $question = PlacementTestQuestion::find($id);

        if (!$question) {
            return response()->json(['message' => 'Question not found'], 404);
        }

        try {
            $question->delete();
            return response()->json(['message' => 'Question deleted successfully'], 200);
        } catch (Exception $e) {
            return response()->json(['message' => 'Failed to delete question'], 500);
        }
    }

    public function editLMCInfo(Request $request)
    {
        $info = LMCInfo::findOrFail(1);

        $data = $request->validate([
            'Title' => 'sometimes|string',
            'Descriptions' => 'sometimes|array',
            'Descriptions.*.Title' => 'required_with:Descriptions|string',
            'Descriptions.*.Explanation' => 'required_with:Descriptions|string',
            'Photo' => 'nullable|image|max:2048'
        ]);

        if ($request->hasFile('Photo')) {
            if ($info->photo) {
                Storage::disk('public')->delete($info->photo);
            }
            $image = $request->file('Photo');
            $new_name = time() . '_' . $image->getClientOriginalName();
            $image->move(public_path('storage/LMC_photos'), $new_name);
            $imageUrl = url('storage/LMC_photos/' . $new_name);

            if (!file_exists(public_path('storage/LMC_photos/' . $new_name))) {
                throw new Exception('Failed to upload image', 500);
            }
            $data['Photo'] = $imageUrl;
        }

        if (isset($data['Descriptions'])){
            $data['Descriptions'] = json_encode($data['Descriptions']);
        }

        $info->update($data);

        return response()->json($info);
    }

    public function reviewFinalGrades()
    {
        $students = DB::table('final_grades')
            ->join('users', 'final_grades.StudentId', '=', 'users.id')
            ->join('courses', 'final_grades.CourseId', '=', 'courses.id')
            ->select(
                'users.name as StudentName',
                'users.id as StudentId',
                'courses.id as CourseId',
                'courses.Description as CourseName',
                'final_grades.FinalTestScore',
                'final_grades.Bonus',
                'final_grades.FinalGrade'
            )
            ->get();

        return response()->json([
            'message' => 'Final grades retrieved successfully.',
            'data' => $students
        ]);
    }

    public function reviewFinalGradesForCourse($courseId)
    {
        $students = DB::table('final_grades')
            ->join('users', 'final_grades.StudentId', '=', 'users.id')
            ->join('courses', 'final_grades.CourseId', '=', 'courses.id')
            ->where('final_grades.CourseId', $courseId)
            ->select(
                'users.name as StudentName',
                'users.id as StudentId',
                'courses.id as CourseId',
                'courses.Description as CourseName',
                'final_grades.FinalTestScore',
                'final_grades.Bonus',
                'final_grades.FinalGrade'
            )
            ->get();

        return response()->json([
            'message' => 'Final grades for course retrieved successfully.',
            'data' => $students
        ]);
    }

    public function reviewFinalGradeForStudent($studentId)
    {
        $grades = DB::table('final_grades')
            ->join('courses', 'final_grades.CourseId', '=', 'courses.id')
            ->join('users', 'final_grades.StudentId', '=', 'users.id')
            ->where('final_grades.StudentId', $studentId)
            ->select(
                'users.name as StudentName',
                'users.id as StudentId',
                'courses.id as CourseId',
                'courses.Description as CourseName',
                'final_grades.FinalTestScore',
                'final_grades.Bonus',
                'final_grades.FinalGrade'
            )
            ->get();

        return response()->json([
            'message' => 'Final grades for student retrieved successfully.',
            'data' => $grades
        ]);
    }

    public function getTopStudentForCourse($courseId)
    {
        $topStudent = DB::table('final_grades')
            ->join('users', 'final_grades.StudentId', '=', 'users.id')
            ->join('courses', 'final_grades.CourseId', '=', 'courses.id')
            ->where('final_grades.CourseId', $courseId)
            ->orderByDesc('final_grades.FinalGrade')
            ->select(
                'users.name as StudentName',
                'users.id as StudentId',
                'courses.id as CourseId',
                'courses.Description as CourseName',
                'final_grades.FinalTestScore',
                'final_grades.Bonus',
                'final_grades.FinalGrade'
            )
            ->first();

        if ($topStudent) {
            return response()->json([
                'message' => 'Top student retrieved successfully.',
                'data' => $topStudent
            ]);
        } else {
            return response()->json([
                'message' => 'No students found for this course.',
                'data' => null
            ], 404);
        }
    }

   public function viewStatistics()
    {
        $stats = [
            'Total Students' => User::where('role_id', 5)->count(),
            'New students this month' => User::where('role_id', 5)
                ->whereMonth('created_at', now()->month)
                ->count(),
            'Active Teachers' => User::where('role_id',3)->count(),
            'Active Courses' => CourseSchedule::where('Start_Date', '<=', now())
                ->where('End_Date', '>=', now())->count(),
            'Completed Courses' => CourseSchedule::where('End_Date', '<', now())->count(),
            'Average Of final grades' => DB::table('final_grades')->avg('FinalGrade'),
            'Top Course' => Course::withCount('Enrollment')->first(['Description']),
            'Students Without Final Test' => DB::table('enrollments')
                ->leftJoin('final_grades', function ($join) {
                    $join->on('enrollments.StudentId', '=', 'final_grades.StudentId')
                        ->on('enrollments.CourseId', '=', 'final_grades.CourseId');
                })
                ->whereNull('final_grades.id')->count(),
        ];

        return response()->json([
            'message' => 'Manager statistics retrieved successfully.',
            'data' => $stats,
        ]);
    }
}
