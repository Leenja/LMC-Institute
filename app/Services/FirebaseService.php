<?php

namespace App\Services;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use App\Models\User;

class FirebaseService
{
    protected $messaging;

    public function __construct()
    {
        $factory = (new Factory)
            ->withServiceAccount(config('services.firebase.credentials'))
            ->withDatabaseUri(config('services.firebase.database_url'));

        $this->messaging = $factory->createMessaging();
    }

    public function sendNotificationToUser(User $user, string $title, string $body)
    {
        $tokens = $user->firebaseTokens->pluck('device_key')->toArray();

        if (empty($tokens)) {
            return ['message' => 'No registered devices found for this user', 'success' => false];
        }

        $notification = Notification::create($title, $body);
        $message = CloudMessage::new()->withNotification($notification);

        $response = $this->messaging->sendMulticast($message, $tokens);

        return [
            'success' => $response->successes()->count(),
            'failures' => $response->failures()->count(),
            'message' => 'Notification is sent',
        ];
    }
}
