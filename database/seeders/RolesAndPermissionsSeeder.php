<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Define all possible permissions in the application
        $allApplicationPermissions = [
            //user
            'register',
            'login',
            'logout',
            'showUserInfo',
            'registerGuest',
            'LoginSuperAdmin',
            'getStaff',
            'addFlashcard',
            'editFlashcard',
            'deleteFlashcard',
            'enrollStudent',
            'cancelEnrollment',
            'addCourse',
            'editCourse',
            'deleteCourse',
            'viewEnrolledStudentsInCourse',
            'getAllEnrolledStudents',
            'reviewMyCourses',
            'submitComplaint',
            'editComplaint',
            'showSolvedComplaintsTeacher',
            'showPendingComplaintsTeacher',
            'showComplaintTeacher',
            'deleteComplaint',
            'checkComplaint',
            'showSolvedComplaints',
            'showPendingComplaints',
            'showAllComplaint',
            'showTeacherComplaints',
            'showComplaint',
            'reviewSchedule',
            'reviewStudentsNames',
            'enterBonus',
            'markAttendance',
            'viewEnrolledCourses',
            'viewMyLessons',
            'viewTeachers',
            'viewTeacher',
            'addNote',
            'editNote',
            'deleteNote',
            'viewMyNotes',
            'viewProgress',
            'viewAvailableCourses',
            'viewAllFlashCards',
            'viewFlashCard',
            'viewAllTeacherFlashCards',
            'viewTeacherFlashCard',
            'viewLessonFlashCards',
            'viewCourseFlashCards',
            'viewFlashCardsByLesson',
            'viewFlashCardsByCourse',
            'viewRoadmap',
            'viewCourses',
            'viewCourse',
            'viewCourseDetails',
            'getCourseLessons',
            'deleteAnnouncement',
            'updateAnnouncement',
            'addAnnouncement',
            'showInvoice',
            'deleteLanguage',
            'updateLanguage',
            'addLanguage',
            'updateRoom',
            'addLanguageToLibrary',
            'editFileInLibrary',
            'deleteFileInLibrary',
            'deleteLibraryForLanguage',
            'addRoom',
            'viewAvailableRooms',
            'showTasks',
            'assignTask',
            'showLanguage',
            'showAllLanguage',
            'getAnnouncement',
            'getAllAnnouncements',
            'viewReservedRooms',
            'showRooms',
            'editMyInfo',
            'removeMyInfo',
            'addHoliday',
            'getHoliday',
            'assignTaskToSecretary',
            'addSelfTest',
            'addSelfTestQuestion',
            'editSelfTest',
            'editSelfTestQuestion',
            'deleteSelfTest',
            'deleteSelfTestQuestion',
            'getSelfTestQuestions',
            'submitSelfTestAnswer',
        ];

        // Create all permissions
        foreach ($allApplicationPermissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                //'guard_name' => 'api'
                'guard_name' => 'sanctum'
            ]);
        }

        // Define roles with their permissions (SuperAdmin will get all permissions)
        $roles = [
            'SuperAdmin' => $allApplicationPermissions, // SuperAdmin gets ALL permissions
            'Secretarya' => [
               'registerGuest',
                'login',
                'logout',
                'editMyInfo',
                'removeMyInfo',
                'enrollStudent',
                'cancelEnrollment',
                'addCourse',
                'editCourse',
                'deleteCourse',
                'viewEnrolledStudentsInCourse',
                'getAllEnrolledStudents',
                'viewCourses',
                'viewCourse',
                'viewCourseDetails',
                'getCourseLessons',
                'viewAvailableRooms',
                'viewReservedRooms',
                'showRooms',
                'deleteAnnouncement',
                'updateAnnouncement',
                'addAnnouncement',
                'showInvoice',
                'showLanguage',
                'showAllLanguage',
                'getAnnouncement',
                'getAllAnnouncements',
                'addLanguageToLibrary',
                'editFileInLibrary',
                'deleteFileInLibrary',
                'deleteLibraryForLanguage',
                'getHoliday',
            ],
            'Teacher' => [
                'registerGuest',
                'login',
                'logout',
                'editMyInfo',
                'removeMyInfo',
                'addSelfTest',
                'addSelfTestQuestion',
                'editSelfTest',
                'editSelfTestQuestion',
                'deleteSelfTest',
                'deleteSelfTestQuestion',
                'addFlashcard',
                'editFlashcard',
                'deleteFlashcard',
                'reviewMyCourses',
                'submitComplaint',
                'editComplaint',
                'showSolvedComplaintsTeacher',
                'showPendingComplaintsTeacher',
                'showComplaintTeacher',
                'deleteComplaint',
                'reviewSchedule',
                'reviewStudentsNames',
                'enterBonus',
                'markAttendance',
                'viewAllTeacherFlashCards',
                'viewTeacherFlashCard',
                'viewLessonFlashCards',
                'viewCourseFlashCards',
                'viewCourses',
                'viewCourse',
                'viewCourseDetails',
                'getCourseLessons',
                'showLanguage',
                'showAllLanguage',
                'getAnnouncement',
                'getAllAnnouncements',
                'assignTaskToSecretary',
                'getHoliday',
            ],
            'Logistic' => [
                'registerGuest',
                'login',
                'logout',
                'editMyInfo',
                'removeMyInfo',
                'showLanguage',
                'showAllLanguage',
                'getAnnouncement',
                'getAllAnnouncements',
                'viewCourses',
                'viewCourse',
                'viewCourseDetails',
                'getCourseLessons',
                'getHoliday',
            ],
            'Student' => [
              'registerGuest',
              'login',
              'logout',
              'viewEnrolledCourses',
              'viewMyLessons',
              'viewTeachers',
              'viewTeacher',
              'addNote',
              'editNote',
              'deleteNote',
              'viewMyNotes',
              'viewProgress',
              'viewAvailableCourses',
              'viewAllFlashCards',
              'viewFlashCard',
              'viewFlashCardsByLesson',
              'viewFlashCardsByCourse',
              'viewRoadmap',
              'viewCourses',
              'viewCourse',
              'viewCourseDetails',
              'getCourseLessons',
              'showLanguage',
              'showAllLanguage',
              'getAnnouncement',
              'getAllAnnouncements',
              'getHoliday',
              'getSelfTestQuestions',
              'submitSelfTestAnswer',
            ],
            'Guest' => [
                'registerGuest',
                'login',
                'logout',
                'viewTeachers',
                'viewTeacher',
                'viewAvailableCourses',
                'viewRoadmap',
                'viewCourses',
                'viewCourse',
                'viewCourseDetails',
                'showLanguage',
                'showAllLanguage',
                'getAnnouncement',
                'getAllAnnouncements',
                'getHoliday',
            ],
        ];

        // Create roles and assign permissions
        foreach ($roles as $roleName => $rolePermissions) {
            $role = Role::firstOrCreate([
                'name' => $roleName,
                //'guard_name' => 'api'
                'guard_name' => 'sanctum'

            ]);
            $role->syncPermissions($rolePermissions);
        }

          // Create SuperAdmin User
          $superAdminUser = User::factory()->create([
            'name' => 'Super Admin',
            'email' => 'super@admin.com',
            'password' => Hash::make('password'),
            'role_id' => 1,
        ]);

        $superAdminUser->assignRole('SuperAdmin');

        $superAdminUser->givePermissionTo($roles['SuperAdmin']);

    }
}
