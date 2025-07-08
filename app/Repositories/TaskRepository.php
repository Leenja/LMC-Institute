<?php

namespace App\Repositories;

use App\Models\Task;
use App\Models\User;
use App\Models\UserTask;
use Illuminate\Database\Eloquent\Collection;

class TaskRepository
{
    public function create(array $data)
    {
        return Task::create($data);
    }

    public function delete(int $id)
    {
        return Task::destroy($id);
    }

    public function find(int $id)
    {
        return Task::find($id);
    }

    public function markAsComplete(int $id)
    {
        return Task::where('id', $id)->update([
            'Status' => 'Done',
            'Completed_at' => now(),
        ]);
    }

    public function getWithFilters(array $filters): Collection
    {
        $query = Task::with(['users:id,name,email']);

        if (!empty($filters['status'])) {
            $query->where('Status', $filters['status']);
        }

        if (!empty($filters['user_id'])) {
            $userId = $filters['user_id'];
            $query->where(function ($q) use ($userId) {
                $q->where('CreatorId', $userId)
                    ->orWhereHas('users', fn($q) => $q->where('users.id', $userId));
            });
        }

        if (!empty($filters['task_id'])) {
            $query->where('id', $filters['task_id']);
        }

        return $query->get();
    }

    public function getUserRelatedTasks(array $filters, int $userId): Collection
    {
        $query = Task::with(['users:id,name,email'])
            ->where(function ($q) use ($userId) {
                $q->where('CreatorId', $userId)
                    ->orWhereHas('users', fn($q) => $q->where('users.id', $userId));
            });

        if (!empty($filters['task_id'])) {
            $query->where('id', $filters['task_id']);
        }

        if (!empty($filters['status'])) {
            $query->where('Status', $filters['status']);
        }

        return $query->get();
    }

    public function findUserById(int $userId)
    {
        return User::find($userId);
    }

    public function getUsersByRoleExcluding(int $roleId, int $excludeUserId)
    {
        return User::where('role_id', $roleId)
            ->where('id', '!=', $excludeUserId)
            ->get();
    }

    public function assignUserToTask(int $userId, int $taskId)
    {
        return UserTask::create([
            'UserId' => $userId,
            'TaskId' => $taskId,
            'Completed' => false,
        ]);
    }

    public function findOrFail(int $id): Task
    {
        return Task::findOrFail($id);
    }

    public function save(Task $task): bool
    {
        return $task->save();
    }


}
