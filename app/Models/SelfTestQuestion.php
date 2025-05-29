<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SelfTestQuestion extends Model
{
    use HasFactory;

    protected $table = 'self_test_questions';

    protected $fillable = [
        'SelfTestId',
        'QuestionText',
        'Type',
        'Choices',
        'CorrectAnswer',
        'Media'
    ];

    protected $casts = [
        'Choices' => 'array',
    ];

    public function selfTest()
    {
        return $this->belongsTo(SelfTest::class, 'SelfTestId');
    }
}
