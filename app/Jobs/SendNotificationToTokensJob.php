<?php

/*namespace App\Jobs;

use App\Models\Notification;
use App\Models\DeviceToken;
use App\Models\User;
use App\Services\FirebaseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendNotificationToTokensJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $notificationId;
    public array $roles;

    public $tries = 3;
    public $backoff = [60, 300];

    public function __construct(int $notificationId, array $roles)
    {
        $this->notificationId = $notificationId;
        $this->roles = $roles;
    }

    public function handle(FirebaseService $fcm)
    {
        $notification = Notification::find($this->notificationId);
        if (!$notification) return;

        // chunk المستخدمين (بدرجات) لتجنّب نفاذ الذاكرة
        User::role($this->roles)
            ->select('id')
            ->orderBy('id')
            ->chunkById(1000, function ($users) use ($fcm, $notification) {
                $userIds = $users->pluck('id')->toArray();

                $tokens = DeviceToken::whereIn('user_id', $userIds)
                    ->pluck('device_key')
                    ->unique()
                    ->values()
                    ->toArray();

                if (count($tokens) === 0) return;

                // الـ FirebaseService نفسه سيقسّم الدُفعات إلى مجموعات 500
                $fcm->sendToTokens($tokens, $notification->title, $notification->body);
            }, 'users.id');
    }
}*/


namespace App\Jobs;

use App\Models\Notification;
use App\Models\DeviceToken;
use App\Models\User;
use App\Services\FirebaseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendNotificationToTokensJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $notificationId;
    public array $userIds; // الآن نستقبل مستخدمين محددين بدل الأدوار

    public $tries = 3;
    public $backoff = [60, 300];

    public function __construct(int $notificationId, array $userIds)
    {
        $this->notificationId = $notificationId;
        $this->userIds = $userIds;
    }

    public function handle(FirebaseService $fcm)
    {
        $notification = Notification::find($this->notificationId);
        if (!$notification) return;

        $tokens = DeviceToken::whereIn('user_id', $this->userIds)
            ->pluck('device_key')
            ->unique()
            ->values()
            ->toArray();

        if (!empty($tokens)) {
            $fcm->sendToTokens($tokens, $notification->title, $notification->body);
        }
    }
}
