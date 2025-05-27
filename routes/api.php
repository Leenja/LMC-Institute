<?php

use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\ComplaintController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\HolidayController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\LanguageController;
use App\Http\Controllers\LibraryController;
use App\Http\Controllers\ManagerController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\TaskController;


// Public routes
Route::post('LoginSuperAdmin', [AuthController::class, 'login']);
Route::post('login', [AuthController::class, 'login']);
Route::post('registerGuest', [AuthController::class, 'registerGuest']);


// Super Admin routes
Route::middleware(['auth:api', 'role:SuperAdmin'])->prefix('super-admin')->group(function () {
    Route::post('register', [AuthController::class, 'register']);

    Route::get('showUserInfo/{id}', [AuthController::class, 'showUserInfo']);

    Route::get('getStaff', [AuthController::class, 'getStaff']);

    Route::get('showComplaint/{id}', [ComplaintController::class, 'showComplaint']);

    Route::get('showAllComplaint', [ComplaintController::class, 'showAllComplaint']);

    Route::get('showTeacherComplaints/{teacherId}', [ComplaintController::class, 'showTeacherComplaints']);

    Route::get('showPendingComplaints', [ComplaintController::class, 'showPendingComplaints']);

    Route::get('showSolvedComplaints', [ComplaintController::class, 'showSolvedComplaints']);

    Route::post('checkComplaint/{complaintId}', [ComplaintController::class, 'checkComplaint']);

    Route::post('addHoliday', [HolidayController::class, 'addHoliday']);

    //Route::post('deleteHoliday/{id}', [HolidayController::class, 'deleteHoliday']);

    Route::post('assignTask', [TaskController::class, 'assignTask']);

    Route::get('showTasks', [TaskController::class, 'showTasks']);

    Route::post('addRoom', [RoomController::class, 'addRoom']);

    Route::post('updateRoom/{id}', [RoomController::class, 'updateRoom']);

    Route::post('addLanguage', [LanguageController::class, 'addLanguage']);

    Route::post('updateLanguage/{id}', [LanguageController::class, 'updateLanguage']);

    Route::delete('deleteLanguage/{id}', [LanguageController::class, 'deleteLanguage']);

    Route::post('editLMCInfo', [ManagerController::class, 'editLMCInfo']);
});


Route::middleware(['auth:api', 'role:Teacher|SuperAdmin'])->prefix('teacher')->group(function () {
    Route::post('addFlashcard', [StaffController::class, 'addFlashCard']);

    Route::post('editFlashcard', [StaffController::class, 'editFlashCard']);

    Route::post('deleteFlashcard', [StaffController::class, 'deleteFlashCard']);

    Route::get("viewAllTeacherFlashCards", [StaffController::class,"viewAllTeacherFlashCards"]);

    Route::get("viewTeacherFlashCard/{flashcardId}", [StaffController::class,"viewTeacherFlashCard"]);

    Route::get('viewLessonFlashCards/{lessonId}', [StaffController::class, 'viewLessonFlashCards']);

    Route::get('viewCourseFlashCards/{courseId}', [StaffController::class, 'viewCourseFlashCards']);

    Route::post('enterBonus', [StaffController::class, 'enterBonus']);

    Route::post('markAttendance', [StaffController::class, 'markAttendance']);

    Route::get('reviewMyCourses', [StaffController::class, 'reviewMyCourses']);

    Route::get('reviewSchedule', [StaffController::class, 'reviewSchedule']);

    Route::get('reviewStudentsNames/{courseId}', [StaffController::class, 'reviewStudentsNames']);

    Route::post('addSelfTest', [StaffController::class, 'addSelfTest']);

    Route::post('addSelfTestQuestion', [StaffController::class, 'addSelfTestQuestion']);

    Route::post('editSelfTest', [StaffController::class, 'editSelfTest']);

    Route::post('editSelfTestQuestion', [StaffController::class, 'editSelfTestQuestion']);

    Route::delete('deleteSelfTest/{selfTestId}', [StaffController::class, 'deleteSelfTest']);

    Route::delete('deleteSelfTestQuestion/{selfTestQuestionId}', [StaffController::class, 'deleteSelfTestQuestion']);

    Route::post('editComplaint/{complaint}', [ComplaintController::class, 'editComplaint']);

    Route::get('deleteComplaint/{id}', [ComplaintController::class, 'deleteComplaint']);

    Route::get('showTeacherOwnComplaints', [ComplaintController::class, 'showTeacherOwnComplaints']);

    Route::get('showPendingComplaintsTeacher', [ComplaintController::class, 'showPendingComplaintsTeacher']);

    Route::get('showSolvedComplaintsTeacher', [ComplaintController::class, 'showSolvedComplaintsTeacher']);

    Route::post('submitComplaint', [ComplaintController::class, 'submitComplaint']);

    Route::post('assignTaskToSecretary', [TaskController::class, 'assignTaskToSecretary']);
});

Route::middleware(['auth:api', 'role:Secretarya|SuperAdmin'])->prefix('secretarya')->group(function () {
    Route::post("enroll", [StaffController::class, "enrollStudent"]);

    Route::post("cancelEnrollment", [StaffController::class, "cancelEnrollment"]);

    Route::post("addCourse", [StaffController::class, "addCourse"]);

    Route::post("editCourse", [StaffController::class,"editCourse"]);

    Route::delete("deleteCourse/{course}", [StaffController::class,"deleteCourse"]);

    Route::get('showRooms', [RoomController::class, 'showRooms']);

    Route::get('viewAvailableRooms', [RoomController::class, 'viewAvailableRooms']);

    Route::get('viewReservedRooms', [RoomController::class, 'viewReservedRooms']);

    Route::get("viewEnrolledStudentsInCourse/{course}", [StaffController::class,"viewEnrolledStudentsInCourse"]);

    Route::get("getAllEnrolledStudents", [StaffController::class,"getAllEnrolledStudents"]);

    Route::get('showInvoice/{id}', [InvoiceController::class, 'showInvoice']);

    Route::post('addAnnouncement', [AnnouncementController::class, 'addAnnouncement']);

    Route::post('updateAnnouncement/{id}', [AnnouncementController::class, 'updateAnnouncement']);

    Route::delete('deleteAnnouncement/{id}', [AnnouncementController::class, 'deleteAnnouncement']);

    Route::post('uploadFile', [LibraryController::class, 'uploadFile']);

    Route::post('addLanguageToLibrary', [LibraryController::class, 'addLanguageToLibrary']);

    Route::post('editFileInLibrary/{id}', [LibraryController::class, 'editFile']);

    Route::delete('deleteFileInLibrary/{id}', [LibraryController::class, 'deleteFile']);

    Route::delete('deleteLibraryForLanguage/{id}', [LibraryController::class, 'deleteLibraryForLanguage']);

});

Route::middleware(['auth:api' , 'role:Logistic|SuperAdmin'])->prefix('logistic')->group(function() {

    Route::post('createInvoice', [InvoiceController::class, 'createInvoice']);

});

Route::middleware(['auth:api' , 'role:Student|SuperAdmin'])->prefix('student')->group(function() {
    Route::get("viewEnrolledCourses", [StudentController::class,"viewEnrolledCourses"]);

    Route::get("viewMyLessons/{course}", [StudentController::class,"viewMyLessons"]);

    Route::get("viewRoadmap", [StudentController::class,"viewRoadmap"]);

    Route::get("viewTeachers", [StudentController::class,"viewTeachers"]);

    Route::get("viewAvailableCourses", [StudentController::class,"viewAvailableCourses"]);

    Route::get("viewTeacher/{teacherId}", [StudentController::class,"viewTeacher"]);

    Route::get("getSelfTestQuestions/{selfTestId}", [StudentController::class,"getSelfTestQuestions"]);

    Route::post("submitSelfTestAnswer", [StudentController::class,"submitSelfTestAnswer"]);

    Route::get("viewAllFlashCards", [StudentController::class,"viewAllFlashCards"]);

    Route::get("viewFlashCard/{flashcardId}", [StudentController::class,"viewFlashCard"]);

    Route::get('viewFlashCardsByLesson/{lessonId}', [StudentController::class, 'viewFlashCardsByLesson']);

    Route::get('viewFlashCardsByCourse/{courseId}', [StudentController::class, 'viewFlashCardsByCourse']);

    Route::post("addNote", [StudentController::class,"addNote"]);

    Route::post("editNote/{noteId}", [StudentController::class,"editNote"]);

    Route::get("deleteNote/{noteId}", [StudentController::class,"deleteNote"]);

    Route::get("viewMyNotes", [StudentController::class,"viewMyNotes"]);

    Route::get("viewProgress", [StudentController::class,"viewProgress"]);
});

Route::middleware(['auth:api' , 'role:Guest'])->prefix('guest')->group(function() {
    Route::get("viewAvailableCourses", [StudentController::class,"viewAvailableCourses"]);

    Route::get("viewTeachers", [StudentController::class,"viewTeachers"]);

    Route::get("viewTeacher/{teacherId}", [StudentController::class,"viewTeacher"]);

    Route::get("viewRoadmap", [StudentController::class,"viewRoadmap"]);
});

//all staff
Route::middleware(['auth:api' , 'role:Logistic|SuperAdmin|Teacher|Secretarya'])->prefix('staff')->group(function() {
    Route::post("editMyInfo", [StaffController::class,"editMyInfo"]);

    Route::post("removeMyInfo", [StaffController::class,"removeMyInfo"]);

    Route::post('completeUserTask/{id}', [TaskController::class, 'completeUserTask']);

    Route::get('myTasks', [TaskController::class, 'myTasks']);
});

//all users
Route::get('viewLMCInfo', [StudentController::class, 'viewLMCInfo']);

Route::get("viewCourses", [StaffController::class,"viewCourses"]);

Route::get("viewCourse/{courseId}", [StaffController::class,"viewCourse"]);

Route::get("viewCourseDetails/{courseId}", [StaffController::class,"viewCourseDetails"]);

Route::get('showLanguage/{id}', [LanguageController::class, 'showLanguage']);

Route::get('showAllLanguage', [LanguageController::class, 'showAllLanguage']);

Route::get('getAnnouncement/{id}', [AnnouncementController::class, 'getAnnouncement']);

Route::get('getAllAnnouncements', [AnnouncementController::class, 'getAllAnnouncements']);

Route::get('getLanguagesThatHaveLibrary', [LibraryController::class, 'getLanguages']);

Route::get('getFilesByLanguage/{id}', [LibraryController::class, 'getFilesByLanguage']);

Route::get('downloadFile/{id}', [LibraryController::class, 'downloadFile']);

Route::get('getHoliday', [HolidayController::class, 'getHoliday']);

// Authenticated routes (all logged-in users)
Route::middleware(['auth:api'])->group(function () {
    Route::get('profile', [AuthController::class, 'profile']);

    Route::get('getCourseLessons/{courseId}', [StaffController::class, 'getCourseLessons']);

    Route::post('logout', [AuthController::class, 'logout']);
});
