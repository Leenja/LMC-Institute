<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlacementTestAnswer extends Model
{
    protected $table = 'placement_test_answers';

    use HasFactory;

    protected $fillable = [
        'QuestionId',
        'AnswerText',
        'isCorrect'
    ];

    public function question()
    {
        return $this->belongsTo(PlacementTestQuestion::class, 'QuestionId');
    }
}
