<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SelfTestProgress extends Model
{
    use HasFactory;

    protected $table = 'self_test_progress';

    protected $fillable = [
        'StudentId',
        'SelfTestId',
        'LastAnsweredQuestionId',
    ];

    public function student()
    {
        return $this->belongsTo(User::class, 'StudentId');
    }

    public function selfTest()
    {
        return $this->belongsTo(SelfTest::class, 'SelfTestId');
    }

    public function lastAnsweredQuestion()
    {
        return $this->belongsTo(SelfTestQuestion::class, 'LastAnsweredQuestionId');
    }
}
