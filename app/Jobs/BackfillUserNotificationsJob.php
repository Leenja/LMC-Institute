<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;


class BackfillUserNotificationsJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(public int $notificationId, public array $roles) {}

    public function handle()
    {
        try {
        $notification = Notification::find($this->notificationId);
        if (!$notification) return;

        // chunkById لتجنّب تحميل كل المستخدمين
        User::role($this->roles)
            ->select('users.id')
            ->orderBy('users.id')
            ->chunkById(500, function ($users) use ($notification) {
                $rows = [];
                $now = now();

                foreach ($users as $u) {
                    $rows[] = [
                        'user_id'        => $u->id,
                        'notification_id'=> $notification->id,
                        'is_read'        => false,
                        'created_at'     => $now,
                        'updated_at'     => $now,
                    ];
                }

                if ($rows) {
                    // إدراج مجمّع (bulk insert)
                    UserNotification::insert($rows);
                }
            }, 'users.id');
    }
    catch (\Throwable $e) {
        Log::error("BackfillUserNotificationsJob failed: " . $e->getMessage());
    }

    
}
}