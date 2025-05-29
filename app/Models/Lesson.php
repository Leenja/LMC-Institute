<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Lesson extends Model
{
    protected $table = 'lessons';

    use HasFactory;

    protected $fillable = [
        "CourseId",
        "Title",
        "Date",
        "Start_Time",
        "End_Time",
    ];

    public function Course(){
        return $this->belongsTo(Course::class, 'CourseId');
    }

    public function SelfTest(){
        return $this->hasMany(SelfTest::class, 'LessonId');
    }

    public function FlashCard(){
        return $this->hasMany(FlashCard::class, 'FlashCardId');
    }

    public function Attendance(){
        return $this->hasMany(Attendance::class, 'AttendanceId');
    }
}
