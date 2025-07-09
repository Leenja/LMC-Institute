<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FinalTestProgress extends Model
{
    protected $table = 'test_progresses';

    use HasFactory;

    protected $fillable = [
        'StudentId',
        'TestId',
        'LastAnsweredQuestionId',
        'Score',
    ];

    public function student()
    {
        return $this->belongsTo(User::class, 'StudentId');
    }

    public function test()
    {
        return $this->belongsTo(Test::class, 'TestId');
    }

    public function lastQuestion()
    {
        return $this->belongsTo(TestQuestion::class, 'LastAnsweredQuestionId');
    }
}
