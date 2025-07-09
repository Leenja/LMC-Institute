<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FinalTestAnswer extends Model
{
    protected $table = 'test_answers';

    use HasFactory;

    protected $fillable = [
        'StudentId',
        'TestId',
        'QuestionId',
        'Answer',
        'isCorrect',
    ];

    public function student()
    {
        return $this->belongsTo(User::class, 'StudentId');
    }

    public function test()
    {
        return $this->belongsTo(Test::class, 'TestId');
    }

    public function question()
    {
        return $this->belongsTo(TestQuestion::class, 'QuestionId');
    }
}
