<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlacementTestProgress extends Model
{
    protected $table = 'placement_test_progress';

    use HasFactory;

    protected $fillable = [
        'PlacementTestId',
        'QuestionId',
        'SelectedAnswerId',
    ];

    public function test()
    {
        return $this->belongsTo(PlacementTest::class, 'PlacementTestId');
    }

    public function question()
    {
        return $this->belongsTo(PlacementTestQuestion::class, 'QuestionId');
    }
}
