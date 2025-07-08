<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;  // استيراد SoftDeletes

class StaffInfo extends Model
{
    use HasFactory ,SoftDeletes;

    protected $fillable = [
        'UserId',
        'Photo',
        'Description'
    ];

    public function User()
    {
        return $this->belongsTo(User::class, 'UserId');
    }
}
