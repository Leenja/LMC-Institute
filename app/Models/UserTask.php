<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class UserTask extends Pivot{


    protected $table = 'usertasks';
    public $incrementing = true; // If you have an auto-incrementing primary key
    public $timestamps = true; // If your table has timestamps


    protected $fillable = [
        'TaskId',
        'UserId',
        'RequiresInvoice',
        'Completed',
    ];

    public function User(){
        return $this->belongsTo(User::class, 'UserId');
    }

    public function Task(){
        return $this->belongsTo(Task::class, 'TaskId');
    }

    public function updateRequiresInvoice(int $taskId, int $userId, bool $value): bool
{
    return UserTask::where('TaskId', $taskId)
                   ->where('UserId', $userId)
                   ->update(['RequiresInvoice' => $value]);
}
}
