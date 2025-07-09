<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TestQuestion extends Model
{
    protected $table = 'test_questions';

    use HasFactory;

    protected $fillable = [
        'TestId',
        'Media',
        'QuestionText',
        'Type',
        'Choices',
        'CorrectAnswer',
        'Point'
    ];

    public function Test()
    {
        return $this->belongsTo(Test::class, 'TestId');
    }

}
