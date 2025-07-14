<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\DeviceToken;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\Http;
use App\Services\FirebaseService;

class FirebaseTokenController extends Controller
{
    public function update(Request $request)
    {
        $request->validate([
            'newFirebaseToken' => 'required|string',
            'oldFirebaseToken' => 'nullable|string',
        ]);

        $user = auth()->user();

        if ($request->oldFirebaseToken) {
            DeviceToken::where('device_key', $request->oldFirebaseToken)->delete();
        }

        $exists = DeviceToken::where('device_key', $request->newFirebaseToken)->exists();
        if (!$exists) {
            DeviceToken::create([
                'user_id' => $user->id,
                'device_key' => $request->newFirebaseToken,
            ]);
        }

        return response()->json(['message' => 'Firebase token updated successfully.']);
    }

    /*function sendNotificationToUser($userId, $title, $body)
    {
        $tokens = DeviceToken::where('user_id', $userId)->pluck('device_token')->toArray();

        if (empty($tokens)) return;

        $serverKey = config('services.firebase.server_key'); // أضف هذا في config/services.php

        $response = Http::withHeaders([
            'Authorization' => 'key=' . $serverKey,
            'Content-Type' => 'application/json',
        ])->post('https://fcm.googleapis.com/fcm/send', [
            'registration_ids' => $tokens,
            'notification' => [
                'title' => $title,
                'body' => $body,
            ],
            'data' => [
                'click_action' => 'FLUTTER_NOTIFICATION_CLICK', // if needed
            ]
        ]);

        return $response->json();
    }*/

    /* public function sendNotificationToUser(User $user, string $title, string $body)
    {
        $serverKey = config('services.fcm.server_key');

        $deviceTokens = $user->firebaseTokens->pluck('device_key')->toArray();

        if (empty($deviceTokens)) {
            return response()->json(['message' => 'لا توجد أجهزة مسجلة لهذا المستخدم'], 404);
        }

        $response = Http::withHeaders([
            'Authorization' => 'key=' . $serverKey,
            'Content-Type' => 'application/json',
        ])->post('https://fcm.googleapis.com/fcm/send', [
            'registration_ids' => $deviceTokens, // عدة توكنات
            'notification' => [
                'title' => $title,
                'body' => $body,
            ],
            'data' => [
                'user_id' => $user->id,
            ],
            'priority' => 'high',
        ]);

        return $response->json();
    }*/


    public function sendTestNotification(Request $request)
    {
        $request->validate([
            'title' => 'required|string',
            'body' => 'required|string',
            'users' => 'required|array',
            'users.*' => 'string'
        ]);

        $roles = $request->users;

        $users = User::role($roles)->get();

        $notification = Notification::create([
            'title' => $request->title,
            'body' => $request->body,
            'target_roles' => $roles,
        ]);

        $firebaseService = new FirebaseService();

        $results = [];

        foreach ($users as $user) {
            UserNotification::create([
                'user_id' => $user->id,
                'notification_id' => $notification->id,
            ]);

            $results[] = [
                'user_id' => $user->id,
                'result' => $firebaseService->sendNotificationToUser($user, $request->title, $request->body),
            ];
        }

        return response()->json([
            'message' => 'Notifications are sent!',
            'results' => $results,
        ]);
    }

    public function sendNotificationToUser(User $user, string $title, string $body)
    {
        $firebaseService = new FirebaseService();
        return $firebaseService->sendNotificationToUser($user, $title, $body);
    }

    //should test
    public function getNotifications()
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        $notifications = $user->userNotifications()
            ->with('notification')
            ->latest()
            ->get()
            ->map(function ($un) {
                return [
                    'id' => $un->id,
                    'title' => $un->notification->title,
                    'body' => $un->notification->body,
                    'is_read' => $un->is_read,
                    'created_at' => $un->created_at->diffForHumans(),
                ];
            });

        return response()->json($notifications);
    }

    public function getUnreadCount()
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        $count = $user->userNotifications()->where('is_read', false)->count();

        return response()->json(['unread' => $count]);
    }

    public function markAsRead(Request $request)
    {
        $request->validate([
            'id' => 'required|exists:user_notifications,id',
        ]);

        $un = UserNotification::findOrFail($request->id);
        if ($un->user_id != auth()->id()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $un->update(['is_read' => true]);

        return response()->json(['message' => 'Marked as read']);
    }

    /*public function sendNotification(Request $request)
    {
        $request->validate([
            'title' => 'required|string',
            'body' => 'required|string',
            'roles' => 'required|array', // ['Secretarya', 'SuperAdmin']
        ]);

        $notification = Notification::create([
            'title' => $request->title,
            'body' => $request->body,
            'target_roles' => json_encode($request->roles),
        ]);

        $users = User::role($request->roles)->get();

        foreach ($users as $user) {
            UserNotification::create([
                'user_id' => $user->id,
                'notification_id' => $notification->id,
            ]);

            foreach ($user->deviceKeys as $device) {
                // Send to Firebase
                $this->sendFirebaseMessage($device->firebase_token, $request->title, $request->body);
            }
        }

        return response()->json(['message' => 'Notification sent']);
    }

    private function sendFirebaseMessage($token, $title, $body)
    {
        $SERVER_API_KEY = config('services.firebase.server_key');

        Http::withHeaders([
            'Authorization' => 'key=' . $SERVER_API_KEY,
            'Content-Type' => 'application/json',
        ])->post('https://fcm.googleapis.com/fcm/send', [
            'to' => $token,
            'notification' => [
                'title' => $title,
                'body' => $body,
            ],
            'priority' => 'high',
        ]);
    }*/
}
