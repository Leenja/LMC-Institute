<?php

// UserTaskRepository.php
namespace App\Repositories;

use App\Models\UserTask;
use Illuminate\Database\Eloquent\Collection;

class UserTaskRepository
{
    public function create(array $data)
    {
        return UserTask::create($data);
    }

    public function deleteByTaskId(int $taskId): void
    {
        UserTask::where('TaskId', $taskId)->delete();
    }

    public function updateRequiresInvoice(int $taskId, int $userId, bool $requiresInvoice): bool
{
    // Assuming UserTask is the model representing user_task pivot table
    $userTask = UserTask::where('TaskId', $taskId)
                        ->where('UserId', $userId)
                        ->first();

    if (!$userTask) {
        // Optionally throw exception or return false if record not found
        return false;
    }

    $userTask->RequiresInvoice = $requiresInvoice;
    return $userTask->save();
}

public function getForTaskWithUsers(int $taskId): Collection
{
    return UserTask::where('TaskId', $taskId)
                   ->with('user.roles') // لضمان تحميل الدور مع المستخدم
                   ->get();
}

    

    public function findByUserAndTask(int $userId, int $taskId)
    {
        return UserTask::where('TaskId', $taskId)
                     ->where('UserId', $userId)
                     ->first();
    }

    public function markAsComplete(int $id)
    {
        return UserTask::where('id', $id)->update([
            'Completed' => true,
            'updated_at' => now()
        ]);
    }

    public function countIncomplete(int $taskId): int
    {
        return UserTask::where('TaskId', $taskId)
                     ->where('Completed', false)
                     ->count();
    }

   /* public function getForTaskWithUsers(int $taskId): Collection
    {
        return UserTask::where('TaskId', $taskId)
                     ->with('user:id,name,email')
                     ->get();
    }*/
}