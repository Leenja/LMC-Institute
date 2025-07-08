<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Language extends Model
{
    protected $table = 'languages';

    use HasFactory;

    protected $fillable = [
        'Name',
        'Description'
    ];

    public function IndividualCourseRequest()
    {
        return $this->hasMany(IndividualCourseRequest::class ,'language_id');
    }

    public function PlacementTest(){
        return $this->hasOne(PlacementTest::class, 'PlacementTestId');
    }

    public function library(){
        return $this->hasOne(Library::class, 'LanguageId');
    }

    public function libraries(){
        return $this->hasMany(Library::class, 'LanguageId');
    }

    public function Course(){
        return $this->hasMany(Course::class, 'CourseId');
    }
}
