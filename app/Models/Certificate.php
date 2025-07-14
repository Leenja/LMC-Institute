<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Certificate extends Model
{
    protected $table = 'certificates';

    use HasFactory;

    protected $fillable = [
        'CourseId',
        'StudentId',
        'VerificationCode',
        'CourseLanguage',
        'CourseLevel',
        'TeacherName',
    ];

    public function course()
    {
        return $this->belongsTo(Course::class, 'CourseId');
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'StudentId');
    }
}
