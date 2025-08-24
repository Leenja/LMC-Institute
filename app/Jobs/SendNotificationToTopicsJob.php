<?php

// app/Jobs/SendNotificationToTopicsJob.php
namespace App\Jobs;

use App\Models\Notification;
use App\Services\FirebaseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendNotificationToTopicsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $notificationId;
    public array $roles;

    public $tries = 2;

    public function __construct(int $notificationId, array $roles)
    {
        $this->notificationId = $notificationId;
        $this->roles = $roles;
    }

    public function handle(FirebaseService $fcm)
    {
        $notification = Notification::find($this->notificationId);
        if (!$notification) return;

        foreach ($this->roles as $role) {
            $topic = 'role_' . trim($role);
            $fcm->sendToTopic($topic, $notification->title, $notification->body);
        }
    }
}
