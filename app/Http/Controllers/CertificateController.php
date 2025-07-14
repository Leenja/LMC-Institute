<?php

namespace App\Http\Controllers;

use App\Models\Certificate;
use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;


class CertificateController extends Controller
{

    public function requestCertificate(Request $request)
    {
        $request->validate([
            'course_id' => 'required|exists:courses,id',
        ]);

        $user = Auth::user();
        $course = Course::findOrFail($request->course_id);

        $finalGrade = DB::table('final_grades')
            ->where('StudentId', $user->id)
            ->where('CourseId', $course->id)
            ->first();

        if (!$finalGrade) {
            return response()->json(['message' => 'Final grade is not set yet.'], 403);
        }

        $existing = Certificate::where('StudentId', $user->id)
            ->where('CourseId', $course->id)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Certificate is already generated.',
                'certificate' => $existing
            ]);
        }

        $verificationCode = strtoupper(Str::random(10));

        $certificate = Certificate::create([
            'CourseId' => $course->id,
            'StudentId' => $user->id,
            'VerificationCode' => $verificationCode,
            'CourseLanguage' => $course->language->Name,
            'CourseLevel' => $course->Level,
            'TeacherName' => $course->User->name,
        ]);

        return response()->json([
            'message' => 'Certificate generated successfully!',
            'certificate' => [
                'Name' => $user->name,
                'Course language' => $certificate->CourseLanguage,
                'Course level' => $certificate->CourseLevel,
                'Certificate token' => $certificate->VerificationCode,
                'TeacherName' => $certificate->TeacherName,
                'Grade' => $finalGrade->FinalGrade ?? null,
            ],
        ]);
    }

    public function verifyCertificate($token)
    {
        $certificate = Certificate::where('VerificationCode', $token)->first();

        if (!$certificate) {
            return response()->json(['message' => 'Invalid certificate token.'], 404);
        }

        $student = $certificate->student;

        $finalGrade = DB::table('final_grades')
            ->where('StudentId', $certificate->StudentId)
            ->where('CourseId', $certificate->CourseId)
            ->first();

        return response()->json([
            'Name' => $student->name,
            'Course language' => $certificate->CourseLanguage,
            'Course level' => $certificate->CourseLevel,
            'Certificate token' => $certificate->VerificationCode,
            'teacher name' => $certificate->TeacherName,
            'Grade' => $finalGrade->FinalGrade ?? null,
        ]);
    }

    public function viewCertificate($id)
    {
        $user = Auth::user();

        $course = Course::find($id);
        if (!$course) {
            return response()->json(['message' => 'Course not found.'], 404);
        }

        $certificate = Certificate::where('StudentId', $user->id)
            ->where('CourseId', $id)
            ->first();

        if (!$certificate) {
            return response()->json(['message' => 'No certificate was generated for this course.'], 404);
        }

        $finalGrade = DB::table('final_grades')
            ->where('StudentId', $user->id)
            ->where('CourseId', $id)
            ->first();

        return response()->json([
            'Name' => $user->name,
            'Course language' => $certificate->CourseLanguage,
            'Course level' => $certificate->CourseLevel,
            'Certificate token' => $certificate->VerificationCode,
            'TeacherName' => $certificate->TeacherName,
            'Grade' => $finalGrade->FinalGrade ?? null,
        ]);
    }

    public function listCertificates()
    {
        $user = Auth::user();

        $certificates = Certificate::where('StudentId', $user->id)
            ->with('course')
            ->get();

        if ($certificates->isEmpty()) {
            return response()->json(['message' => 'No available certificate for this user.'], 404);
        }

        $finalGrades = DB::table('final_grades')
            ->whereIn('StudentId', [$user->id])
            ->get()
            ->keyBy('CourseId');

        $data = $certificates->map(function ($cert) use ($user, $finalGrades) {
            $finalGrade = $finalGrades[$cert->CourseId]->FinalGrade ?? null;

            return [
                'Name' => $user->name,
                'Course language' => $cert->CourseLanguage,
                'Course level' => $cert->CourseLevel,
                'Certificate token' => $cert->VerificationCode,
                'TeacherName' => $cert->TeacherName,
                'Grade' => $finalGrade,
            ];
        });

        return response()->json([
            'certificates' => $data,
        ]);
    }
}
