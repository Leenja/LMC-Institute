<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\StaffController;

// Public routes
Route::post('LoginSuperAdmin', [AuthController::class, 'login']);
Route::post('login', [AuthController::class, 'login']);
Route::post('registerGuest', [AuthController::class, 'registerGuest']);


// Super Admin routes
Route::middleware(['auth:api', 'role:SuperAdmin'])->prefix('super-admin')->group(function () {
    Route::post('register', [AuthController::class, 'register']);

    Route::get('showUserInfo/{id}', [AuthController::class, 'showUserInfo']);
});


Route::middleware(['auth:api', 'role:Teacher|SuperAdmin'])->prefix('teacher')->group(function () {
    Route::post('addFlashcard', [StaffController::class, 'addFlashCard']);

    Route::get('reviewMyCourses', [StaffController::class, 'reviewMyCourses']);
});

Route::middleware(['auth:api', 'role:Secretarya|SuperAdmin'])->prefix('secretarya')->group(function () {
    Route::post("enroll", [StaffController::class, "enrollStudent"]);

    Route::post("addCourse", [StaffController::class, "addCourse"]);

    Route::post("editCourse", [StaffController::class,"editCourse"]);

    Route::delete("deleteCourse/{course}", [StaffController::class,"deleteCourse"]);

    Route::get("viewEnrolledStudentsInCourse/{course}", [StaffController::class,"viewEnrolledStudentsInCourse"]);

    Route::get("getAllEnrolledStudents", [StaffController::class,"getAllEnrolledStudents"]);
});

// Authenticated routes (all logged-in users)
Route::middleware(['auth:api'])->group(function () {
    // Logout for all authenticated users
    Route::post('logout', [AuthController::class, 'logout']);

});
