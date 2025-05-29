<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SelfTest extends Model
{
    protected $table = 'self_tests';

    use HasFactory;

    protected $fillable = [
        'LessonId',
        'Title',
        'Description'
    ];

    public function Lesson(){
        return $this->belongsTo(Lesson::class, 'LessonId');
    }

    public function questions()
    {
        return $this->hasMany(SelfTestQuestion::class, 'SelfTestId');
    }
}
