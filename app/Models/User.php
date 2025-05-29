<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
//use Tymon\JWTAuth\Contracts\JWTSubject;



use Illuminate\Support\Collection;


//class User extends Authenticatable
class User extends Authenticatable /*implements JWTSubject*/{

    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    //protected $guard_name = 'api'; // Important for Spatie with JWT

    protected $guard_name = 'sanctum';
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */

    protected $fillable = [
        'name',
        'email',
        'password',
        'role_id',
        'remember_token',
        'email_verified_at',
    ];

    public function getJWTIdentifier() { return $this->getKey(); }

    public function getJWTCustomClaims() { return []; }

    public function getAllPermissions(): Collection
    {
        return $this->getPermissionsViaRoles();
    }

    /**
     * Check if user has permission.
     */
    public function hasPermissionTo($permission, $guardName = null): bool
    {
        return $this->hasPermissionThroughRole($permission) ||
               parent::hasPermissionTo($permission, $guardName);
    }

    public function invoiceRecipients()
    {
       return $this->hasMany(InvoiceRecipient::class, 'UserId');
    }

    // If you want to get all invoices where the user is the creator:
    public function createdInvoices()
    {
        return $this->hasMany(Invoice::class, 'CreatorId');
    }

    // Relationship to tasks via the UserTask pivot table
    public function tasks()
    {
        return $this->belongsToMany(Task::class, 'usertasks', 'UserId', 'TaskId');
    }

    public function StudentProgress()
    {
        return $this->hasOne(StudentProgress::class, 'StudentProgressId');
    }

    public function Notes()
    {
        return $this->hasOne(Notes::class, 'NotesId');
    }

    public function Test()
    {
        return $this->hasMany(Test::class, 'TestId');
    }

    public function Attendance(){
        return $this->hasMany(Attendance::class, 'AttendanceId');
    }

    public function announcements()
    {
     return $this->hasMany(Announcement::class, 'CreatorId');
    }

    public function UserTask(){
        return $this->hasMany(UserTask::class, 'UserTaskId');
    }
    public function Enrollment(){
        return $this->hasMany(Enrollment::class, 'EnrollmentId');
    }

    public function Complaint(){
        return $this->hasMany(Complaint::class, 'ComplaintId');
    }

    public function PlacementTest(){
        return $this->hasMany(PlacementTest::class, 'PlacementTestId');
    }

    public function Course(){
        return $this->hasMany(Course::class, 'CourseId');
    }

    public function staffInfo()
    {
        return $this->hasOne(StaffInfo::class, 'UserId');
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

}
