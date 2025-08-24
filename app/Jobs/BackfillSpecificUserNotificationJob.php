<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Models\UserNotification;
use App\Models\DeviceToken;
use App\Services\FirebaseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

class BackfillSpecificUserNotificationJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $notificationId;
    public array $userIds; // المستخدمين المحددين

    public $tries = 3;

    public function __construct(int $notificationId, array $userIds)
    {
        $this->notificationId = $notificationId;
        $this->userIds = $userIds;
    }

    public function handle(FirebaseService $fcm)
    {
        try {
            $notification = Notification::find($this->notificationId);
            if (!$notification) return;

            $rows = [];
            $now = now();

            foreach ($this->userIds as $userId) {
                $rows[] = [
                    'user_id' => $userId,
                    'notification_id' => $notification->id,
                    'is_read' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            // إدراج UserNotification
            if (!empty($rows)) {
                UserNotification::insert($rows);
            }

            // إرسال الإشعار عبر توكنات Firebase
           /* $tokens = DeviceToken::whereIn('user_id', $this->userIds)
                ->pluck('device_key')
                ->unique()
                ->values()
                ->toArray();

            if (!empty($tokens)) {
                $fcm->sendToTokens($tokens, $notification->title, $notification->body);
            }*/

        } catch (\Throwable $e) {
            Log::error("SendAndStoreNotificationJob failed: " . $e->getMessage());
        }
    }
}
