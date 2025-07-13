<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlacementTestQuestion extends Model
{
    protected $table = 'placement_test_questions';

    use HasFactory;

    protected $fillable = [
        'Section',
        'QuestionText',
        'Context',
        'Media',
    ];

    public function answers()
    {
        return $this->hasMany(PlacementTestAnswer::class, 'QuestionId');
    }
}
