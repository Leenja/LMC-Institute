<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use App\Repositories\TaskRepository;
use App\Repositories\UserRepository;
use App\Repositories\UserTaskRepository;
use Carbon\Carbon;
use Illuminate\Support\Collection;

use Illuminate\Support\Facades\DB;


class TaskService
{
    protected $taskRepo;
    protected $userRepo;
    protected $userTaskRepo;

    public function __construct(
        TaskRepository $taskRepo,
        UserRepository $userRepo,
        UserTaskRepository $userTaskRepo
    ) {
        $this->taskRepo = $taskRepo;
        $this->userRepo = $userRepo;
        $this->userTaskRepo = $userTaskRepo;
    }

    //lana

    /* public function updateTask(int $taskId, int $updaterId, array $data): array
    {
        DB::beginTransaction();

        try {
            $task = $this->taskRepo->findOrFail($taskId);

            // تحديث الوصف والموعد النهائي
            if (array_key_exists('Description', $data)) {
                $task->Description = $data['Description'];
            }

            if (array_key_exists('Deadline', $data)) {
                $task->Deadline = $data['Deadline'];
            }

            $hasUserOrRole = !empty($data['user_id']) || !empty($data['role_id']);
            $hasRequiresInvoice = array_key_exists('RequiresInvoice', $data);
            $requiresInvoiceValue = $hasRequiresInvoice ? $data['RequiresInvoice'] : null;

            //$isValidRequiresInvoice = is_bool($requiresInvoiceValue);

            // نتحقق إذا القيمة صحيحة (true أو false أو 0)
            $isValidRequiresInvoice = in_array($requiresInvoiceValue, [true, false, 0], true);

            $users = collect();
            if ($hasUserOrRole) {
                // حذف التعيينات القديمة
                $this->userTaskRepo->deleteByTaskId($taskId);

                // جلب المستخدمين الجدد
                $users = $this->getUsersToUpdateAssign($data, $updaterId);

                if ($users->isEmpty()) {
                    throw new \Exception('No valid users to assign the task to.', 400);
                }


                if ($hasRequiresInvoice) {
                    $task->RequiresInvoice = $requiresInvoiceValue;

                    foreach ($users as $user) {
                        $user->loadMissing('roles'); // مهم

                        $userRequiresInvoice = ($requiresInvoiceValue === true && $user->hasRole('logistic'));

                        $this->userTaskRepo->create([
                            'UserId'          => $user->id,
                            'TaskId'          => $taskId,
                            'RequiresInvoice' => $isValidRequiresInvoice ? $userRequiresInvoice : false,
                            'Completed'       => false,
                        ]);
                    }
                } else {
                    // لا يوجد RequiresInvoice - استخدم القيمة الحالية
                    $currentRequiresInvoice = $task->RequiresInvoice;
                    $isCurrentValid = is_bool($currentRequiresInvoice);

                    foreach ($users as $user) {
                        $user->loadMissing('roles');

                        $userRequiresInvoice = ($isCurrentValid && $currentRequiresInvoice === true && $user->hasRole('logistic'));

                        $this->userTaskRepo->create([
                            'UserId'          => $user->id,
                            'TaskId'          => $taskId,
                            'RequiresInvoice' => $userRequiresInvoice,
                            'Completed'       => false,
                        ]);
                    }
                }
            } elseif ($hasRequiresInvoice) {
                $task->RequiresInvoice = $requiresInvoiceValue;

                if ($isValidRequiresInvoice) {
                    $users = $task->assignedUsers()->with('roles')->get();

                    foreach ($users as $user) {
                        $userRequiresInvoice = ($requiresInvoiceValue === true && $user->hasRole('logistic'));

                        $this->userTaskRepo->updateRequiresInvoice($taskId, $user->id, $userRequiresInvoice);
                    }
                }
            }

            $this->taskRepo->save($task);

            DB::commit();

            return [
                'status' => 200,
                'data' => [
                    'message' => 'Task updated successfully',
                    'task' => $task->load('assignedUsers.roles'),
                ],
            ];
        } catch (\Exception $e) {
            DB::rollBack();

            return [
                'status' => 500,
                'error' => $e->getMessage(),
            ];
        }
    }*/

    public function updateTask(int $taskId, array $data): array
    {
        $task = $this->taskRepo->findOrFail($taskId);
        $originalRequiresInvoice = $task->RequiresInvoice;
        $newRequiresInvoice = $data['RequiresInvoice'] ?? null;
        $usersUpdated = !empty($data['user_id']) || !empty($data['role_id']);
        $requiresInvoiceUpdated = array_key_exists('RequiresInvoice', $data);

        // Update task fields (description, deadline, requires invoice)
        if (isset($data['Description'])) {
            $task->Description = $data['Description'];
        }
        if (isset($data['Deadline'])) {
            $task->Deadline = $data['Deadline'];
        }

        if ($requiresInvoiceUpdated) {
            $task->RequiresInvoice = $newRequiresInvoice;
        }

        $this->taskRepo->save($task);

        // Reassign if users were updated
        if ($usersUpdated) {
            $users = $this->getUsersToAssign($data, $task->CreatorId);
            if ($users->isEmpty()) {
                throw new \Exception('No valid users to assign the task to.', 400);
            }

            // Remove old assignments
            $this->userTaskRepo->deleteByTaskId($task->id);

            // Use updated RequiresInvoice if provided, otherwise original
            $effectiveRequiresInvoice = $requiresInvoiceUpdated ? $newRequiresInvoice : $originalRequiresInvoice;

            $this->assignTaskToUsers($task->id, $users, $effectiveRequiresInvoice);
        }

        // Update RequiresInvoice values in user_tasks for existing users if only invoice flag was updated
        if (!$usersUpdated && $requiresInvoiceUpdated) {
            $userTasks = $this->userTaskRepo->getForTaskWithUsers($taskId);
            foreach ($userTasks as $userTask) {
                $role = $userTask->user->roles()->first();
                $isLogistic = $role && strtolower($role->name) === 'logistic';

                $this->userTaskRepo->updateRequiresInvoice(
                    $taskId,
                    $userTask->UserId,
                    $newRequiresInvoice && $isLogistic
                );
            }
        }

        return [
            'data' => [
                'message' => 'Task updated successfully.',
                //'task' => $task,
                'task' => $task->load(['userTasks.user']),

            ],
            'status' => 200,
        ];
    }

    protected function getUsersToUpdateAssign(array $data, int $excludeUserId = null)
    {
        $users = collect();

        // user_id
        if (!empty($data['user_id'])) {
            $ids = is_array($data['user_id']) ? $data['user_id'] : [$data['user_id']];
            foreach ($ids as $id) {
                if ($id != $excludeUserId) {
                    $user = $this->userRepo->find($id);
                    if ($user) {
                        $users->push($user);
                    }
                }
            }
        }

        // role_id
        if (!empty($data['role_id'])) {
            $roleIds = is_array($data['role_id']) ? $data['role_id'] : [$data['role_id']];
            foreach ($roleIds as $roleId) {
                $roleUsers = $this->userRepo->getByRoleId($roleId, $excludeUserId);
                $users = $users->merge($roleUsers);
            }
        }

        return $users->unique('id');
    }

    public function deleteTask(int $taskId): void
    {
        DB::beginTransaction();

        try {
            $task = $this->taskRepo->findOrFail($taskId);

            // هل للمهمة فواتير مرتبطة بها؟
            if ($task->invoice()->exists()) {
                throw new \Exception("Cannot delete task because it has related invoices.", 400);
            }

            // حذف العلاقات في جدول usertasks
            $task->assignedUsers()->detach();

            // حذف نهائي
            $this->taskRepo->delete($taskId);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new \Exception("Failed to delete task: " . $e->getMessage(), $e->getCode() ?: 500);
        }
    }


    //end lana

    //the frst correct version
    /*public function assignTask(int $creatorId, array $data): array
    {
        $task = $this->taskRepo->create([
            'CreatorId'    => $creatorId,
            'Description'  => $data['Description'],
            'Deadline'     => $data['Deadline'],
            'Status'       => 'Pending',
            'Completed_at' => null,
        ]);

        $users = $this->getUsersToAssign($data, $creatorId);

        if ($users->isEmpty()) {
            $this->taskRepo->delete($task->id);
            throw new \Exception('No valid users to assign the task to.', 400);
        }

        $this->assignTaskToUsers($task->id, $users);

        return [
            'data' => [
                'message' => 'Task assigned successfully.',
                'task' => $task,
                'assigned_users' => $users->pluck('id'),
            ],
            'status' => 200,
        ];
    }*/

    public function assignTask(int $creatorId, array $data): array
    {
        $task = $this->taskRepo->create([
            'CreatorId'       => $creatorId,
            'Description'     => $data['Description'],
            'Deadline'        => $data['Deadline'],
            'Status'          => 'Pending',
            'Completed_at'    => null,
            'RequiresInvoice' => $data['RequiresInvoice'] ?? false,
        ]);

        $users = $this->getUsersToAssign($data, $creatorId);

        if ($users->isEmpty()) {
            $this->taskRepo->delete($task->id);
            throw new \Exception('No valid users to assign the task to.', 400);
        }

        $this->assignTaskToUsers($task->id, $users, $data['RequiresInvoice'] ?? false);

        return [
            'data' => [
                'message' => 'Task assigned successfully.',
                'task' => $task,
                'assigned_users' => $users->pluck('id'),
            ],
            'status' => 200,
        ];
    }

    protected function getUsersToAssign(array $data, int $creatorId): Collection
    {
        $users = collect();

        if (!empty($data['user_id'])) {
            $user = $this->userRepo->find($data['user_id']);
            if ($user) {
                $users->push($user);
            }
        }

        if (!empty($data['role_id'])) {
            $roleUsers = $this->userRepo->getByRoleId($data['role_id'], $creatorId);
            $users = $users->merge($roleUsers);
        }



        return $users->unique('id');
    }

    /*the first correct version without modar
    protected function assignTaskToUsers(int $taskId, Collection $users): void
    {
        foreach ($users as $user) {
            $this->userTaskRepo->create([
                'UserId' => $user->id,
                'TaskId' => $taskId,
                'Completed' => false,
            ]);
        }
    }*/

    /* protected function assignTaskToUsers(int $taskId, Collection $users, bool $requiresInvoice): void
    {

        foreach ($users as $user) {
            $userRole = $user->roles()->first(); // Spatie Role

            $userRequiresInvoice = false;

            if ($requiresInvoice === true) {
                // تحقق هل لدى المستخدم دور "logistic"
                $userRequiresInvoice = $userRole && strtolower($userRole->name) === 'logistic';
            }

            $this->userTaskRepo->create([
                'UserId'          => $user->id,
                'TaskId'          => $taskId,
                'RequiresInvoice' => $userRequiresInvoice,
                'Completed'       => false,
            ]);
        }
    }*/

    protected function assignTaskToUsers(int $taskId, Collection $users, bool $requiresInvoice): Collection
    {
        $assigned = collect();

        foreach ($users as $user) {
            $userRole = $user->roles()->first();

            $userRequiresInvoice = false;

            if ($requiresInvoice === true) {
                $userRequiresInvoice = $userRole && strtolower($userRole->name) === 'logistic';
            }

            $userTask = $this->userTaskRepo->create([
                'UserId'          => $user->id,
                'TaskId'          => $taskId,
                'RequiresInvoice' => $userRequiresInvoice,
                'Completed'       => false,
            ]);

            $userTask->user = $user;

            $assigned->push($userTask);
        }

        return $assigned;
    }

    public function completeUserTask(int $taskId, int $userId): array
    {
        $userTask = $this->userTaskRepo->findByUserAndTask($userId, $taskId);

        if (!$userTask) {
            throw new \Exception('Task not assigned to you', 404);
        }

        if ($userTask->Completed) {
            throw new \Exception('Task already completed', 400);
        }

        $this->userTaskRepo->markAsComplete($userTask->id);
        $this->updateMainTaskStatusIfAllComplete($taskId);

        return [
            'task_status' => $this->taskRepo->find($taskId)->Status,
            'completion_time' => $userTask->fresh()->updated_at,
        ];
    }

    protected function updateMainTaskStatusIfAllComplete(int $taskId): void
    {
        $incompleteCount = $this->userTaskRepo->countIncomplete($taskId);

        if ($incompleteCount === 0) {
            $this->taskRepo->markAsComplete($taskId);
        }
    }

    public function getTasks(array $filters): array
    {
        $tasks = $this->taskRepo->getWithFilters($filters);

        if (!empty($filters['task_id']) && $tasks->isEmpty()) {
            throw new \Exception('Task not found', 404);
        }

        return [
            'Tasks' => $tasks,
        ];
    }

    public function getUserTasks(array $filters, int $userId): array
    {
        $tasks = $this->taskRepo->getUserRelatedTasks($filters, $userId);

        if (!empty($filters['task_id']) && $tasks->isEmpty()) {
            throw new \Exception('Task not found or not related to user', 404);
        }

        $user = $this->userRepo->find($userId, ['id', 'name', 'email']);

        $createdTasks = $tasks->where('CreatorId', $userId);
        $assignedTasks = $tasks->where('CreatorId', '!=', $userId);

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'created_tasks' => $createdTasks,
            'assigned_tasks' => $assignedTasks,
        ];
    }

    protected function formatTasks(Collection $tasks, ?int $userId): Collection
    {
        return $tasks->map(function ($task) use ($userId) {
            $creator = $this->userRepo->find($task->CreatorId, ['id', 'name', 'email']);

            $userRole = null;
            if ($userId) {
                $userRole = $task->CreatorId == $userId ? 'creator' : 'assignee';
            }

            return [
                'task_id' => $task->id,
                'description' => $task->Description,
                'status' => $task->Status,
                'deadline' => $task->Deadline,
                'completed_at' => $task->Completed_at,
                'creator' => [
                    'user_id' => $creator->id,
                    'name' => $creator->name,
                    'email' => $creator->email
                ],
                'user_role' => $userRole,
                'assignees' => $this->formatAssignees($task)
            ];
        });
    }

    protected function formatAssignees($task): Collection
    {
        return $task->users->map(function ($user) use ($task) {
            $userTask = $this->userTaskRepo->findByUserAndTask($user->id, $task->id);

            return [
                'user_id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'completed' => $userTask->Completed,
                'completed_at' => $userTask->Completed ? $userTask->updated_at : null,
                'completion_order' => $userTask->Completed ? $userTask->updated_at->format('Y-m-d H:i:s') : 'Pending'
            ];
        })->sortByDesc('completed')->values();
    }

    protected function prepareTaskResponse(Collection $formattedTasks, array $filters): array
    {
        if (isset($filters['user_id'])) {
            $user = $this->userRepo->find($filters['user_id'], ['id', 'name', 'email']);
            return [
                'user' => [
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email
                ],
                'tasks' => $formattedTasks
            ];
        }

        if (isset($filters['task_id'])) {
            return ['task' => $formattedTasks->first()];
        }

        return ['tasks' => $formattedTasks];
    }

    protected function formatCreatedTasks(Collection $tasks): Collection
    {
        return $tasks->map(function ($task) {
            $assignments = $this->userTaskRepo->getForTaskWithUsers($task->id)
                ->sortByDesc(function ($assignment) {
                    return $assignment->Completed ? $assignment->updated_at->timestamp : 0;
                });

            return [
                'task_id' => $task->id,
                'description' => $task->Description,
                'task_status' => $task->Status,
                'deadline' => $task->Deadline,
                'task_completed_at' => $task->Completed_at,
                'your_role' => 'creator',
                'assignees' => $this->formatAssignmentDetails($assignments)
            ];
        });
    }

    protected function formatAssignedTasks(Collection $tasks, int $userId): Collection
    {
        return $tasks->map(function ($task) use ($userId) {
            $creator = $this->userRepo->find($task->CreatorId, ['id', 'name', 'email']);
            $userAssignment = $this->userTaskRepo->findByUserAndTask($userId, $task->id);

            return [
                'task_id' => $task->id,
                'description' => $task->Description,
                'task_status' => $task->Status,
                'deadline' => $task->Deadline,
                'task_completed_at' => $task->Completed_at,
                'your_role' => 'assignee',
                'your_status' => [
                    'completed' => $userAssignment->Completed,
                    'completed_at' => $userAssignment->Completed ? $userAssignment->updated_at : null
                ],
                'creator' => [
                    'user_id' => $creator->id,
                    'name' => $creator->name,
                    'email' => $creator->email
                ],
                'other_assignees' => $this->getOtherAssignees($task, $userId)
            ];
        });
    }

    protected function formatAssignmentDetails(Collection $assignments): Collection
    {
        return $assignments->map(function ($assignment) {
            return [
                'user_id' => $assignment->user->id,
                'name' => $assignment->user->name,
                'email' => $assignment->user->email,
                'completed' => $assignment->Completed,
                'completed_at' => $assignment->Completed ? $assignment->updated_at : null,
                'completion_order' => $assignment->Completed
                    ? $assignment->updated_at->format('Y-m-d H:i:s')
                    : 'Pending'
            ];
        })->values();
    }

    protected function getOtherAssignees($task, int $userId): Collection
    {
        return $task->users->reject(function ($user) use ($userId) {
            return $user->id == $userId;
        })->map(function ($user) use ($task) {
            $userTask = $this->userTaskRepo->findByUserAndTask($user->id, $task->id);

            return [
                'user_id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'completed' => $userTask->Completed,
                'completed_at' => $userTask->Completed ? $userTask->updated_at : null
            ];
        })->values();
    }

    public function assignTaskToSecretaryForLesson(int $creatorId, array $data): array
    {
        $course = Course::find($data['CourseId']);
        $lesson = Lesson::find($data['LessonId']);

        if (!$course || !$lesson) {
            throw new \Exception('Invalid course or lesson.', 404);
        }

        if ($course->TeacherId !== $creatorId) {
            throw new \Exception('You are not assigned to this course.', 403);
        }

        if ($lesson->CourseId !== $course->id) {
            throw new \Exception('The lesson does not belong to the specified course.', 400);
        }

        //task should be sent at least 3 hours before the course starts
        $lessonStart = Carbon::parse($lesson->Date . ' ' . $lesson->Start_Time);
        if (now()->diffInMinutes($lessonStart, false) < 180) {
            throw new \Exception('Tasks can only be assigned at least 3 hours before the lesson.', 400);
        }

        $secretaries = User::role('Secretarya')->get();

        if ($secretaries->isEmpty()) {
            throw new \Exception('No secretary users found to assign the task.', 404);
        }

        $task = $this->taskRepo->create([
            'CreatorId'       => $creatorId,
            'Description'     => $data['Description'],
            'Deadline'        => $data['Deadline'],
            'Status'          => 'Pending',
            'Completed_at'    => null,
            'CourseId'        => $course->id,
            'LessonId'        => $lesson->id,
        ]);

        $this->assignTaskToUsers($task->id, $secretaries, $data['RequiresInvoice'] ?? false);

        return [
            'data' => [
                'message' => 'Task assigned successfully to secretary.',
                'task' => $task,
                'assigned_users' => $secretaries->pluck('id'),
            ],
            'status' => 200,
        ];
    }
}
