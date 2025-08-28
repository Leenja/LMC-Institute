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
    /*20/8
    public function update(Request $request)
    {
        $request->validate([
            'newFirebaseToken' => 'required|string',
            'oldFirebaseToken' => 'nullable|string',
        ]);

        $user = auth()->user();

        // حذف التوكن القديم إذا وُجد
        if ($request->filled('oldFirebaseToken')) {
            DeviceToken::where('device_key', $request->oldFirebaseToken)->delete();
        }

        // تحديث أو إنشاء التوكن الجديد للمستخدم
        DeviceToken::updateOrCreate(
            ['device_key' => $request->newFirebaseToken],
            ['user_id' => $user->id]
        );

        return response()->json(['message' => 'Firebase token updated successfully.']);
    }*/

    /*28-8
    public function update(Request $request)
    {
        $request->validate([
            'newFirebaseToken' => 'required|string',
            'oldFirebaseToken' => 'nullable|string',
        ]);

        $user = auth()->user();

        // احذف التوكن القديم إن أُرسل
        $oldToken = $request->string('oldFirebaseToken')->toString();
        if ($oldToken !== '') {
            \App\Models\DeviceToken::where('device_key', $oldToken)->delete();
        }

        // upsert للتوكن الجديد
        \App\Models\DeviceToken::updateOrCreate(
            ['device_key' => $request->newFirebaseToken],
            ['user_id'    => $user->id]
        );

        // اشترك/ألغِ اشتراك في Topics الأدوار
        $topics = $user->getRoleNames()->map(fn($r) => 'role_' . trim($r))->values()->all();
        $fcm = app(\App\Services\FirebaseService::class);

        if ($oldToken !== '') {
            $fcm->unsubscribeTokensFromTopics([$oldToken], $topics);
        }
        $fcm->subscribeTokensToTopics([$request->newFirebaseToken], $topics);

        return response()->json(['message' => 'Firebase token updated successfully.']);
    }*/

    public function update(Request $request)
    {
        $request->validate([
            'newFirebaseToken' => 'required|string',
            'oldFirebaseToken' => 'nullable|string',
        ]);

        /** @var \App\Models\User $user */
        $user = auth()->user();

        // احذف التوكن القديم إن أُرسل (خاص بالمستخدم الحالي فقط)
        if ($request->filled('oldFirebaseToken')) {
            \App\Models\DeviceToken::where('user_id', $user->id)
                ->where('device_key', $request->oldFirebaseToken)
                ->delete();
        }

        // upsert للتوكن الجديد بحيث يكون (user_id + device_key) فريد
        \App\Models\DeviceToken::updateOrCreate(
            [
                'user_id'    => $user->id,
                'device_key' => $request->newFirebaseToken,
            ],
            [] // مافي بيانات إضافية للتحديث
        );

        // اشترك/ألغِ اشتراك في Topics الأدوار
        $topics = $user->getRoleNames()->map(fn($r) => 'role_' . trim($r))->values()->all();

        /** @var \App\Services\FirebaseService $fcm */
        $fcm = app(\App\Services\FirebaseService::class);

        if ($request->filled('oldFirebaseToken')) {
            $fcm->unsubscribeTokensFromTopics([$request->oldFirebaseToken], $topics);
        }

        $fcm->subscribeTokensToTopics([$request->newFirebaseToken], $topics);

        return response()->json(['message' => 'Firebase token updated successfully.']);
    }

    public function sendTestNotification(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string',
            'body'  => 'required|string',
            'users' => 'required|array',
            'users.*' => 'string'
        ]);

        $roles = $data['users'];

        $notification = \App\Models\Notification::create([
            'title'        => $data['title'],
            'body'         => $data['body'],
            'target_roles' => $roles,
        ]);

        // خزّن اسنادًا لكل مستخدم بهذه الأدوار لكن عبر Job (حتى لا نحمّل الذاكرة)
        /*dispatch(new \App\Jobs\BackfillUserNotificationsJob(
        $notification->id,
        $roles
    ))->onQueue('default');

    // أرسل للمشتركين في مواضيع الأدوار (مرّة لكل دور)
    /** @var \App\Services\FirebaseService $fcm */
        /* $fcm = app(\App\Services\FirebaseService::class);
    $results = [];
    foreach ($roles as $role) {
        $results[$role] = $fcm->sendToTopic('role_'.trim($role), $data['title'], $data['body']);
    }

    return response()->json([
        'message' => 'Notification queued and broadcasted to role topics.',
        'by_topics' => $results,
    ], 202);*/
        // 2) أضِف عملية ملء user_notifications (Backfill) على Queue
        \App\Jobs\BackfillUserNotificationsJob::dispatch($notification->id, $roles)
        ->onQueue('notifications');

        // 3) أرسل إلى التوكنات (heavy) — job تقوم بعمل chunking داخليًا
        /* \App\Jobs\SendNotificationToTokensJob::dispatch($notification->id, $roles)
        ->onQueue('notifications');*/

        // 4) أرسل إلى topics (خفيف نسبياً)
        \App\Jobs\SendNotificationToTopicsJob::dispatch($notification->id, $roles)
            ->onQueue('notifications');

        return response()->json([
            'message' => 'Notification queued and dispatched to jobs.',
            'notification_id' => $notification->id,
        ], 202);
    }



    /*20/8
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

        //يتم إنشاء كائن من خدمة Firebase المسؤولة عن إرسال الإشعارات الحقيقية للأجهزة
        // $firebaseService = new \App\Services\FirebaseService();

        // $results = [];

        foreach ($users as $user) {
            UserNotification::create([
                'user_id' => $user->id,
                'notification_id' => $notification->id,
            ]);

            /* $results[] = [
                'user_id' => $user->id,
                'result' => $firebaseService->sendNotificationToUser($user, $request->title, $request->body),
            ];*/
    //20/8}

    // إرسال الإشعار دفعة واحدةnew
    /*20/8
        $firebaseService = new FirebaseService();
        $sendResult = $firebaseService->sendNotificationToUsers($users, $request->title, $request->body);


        return response()->json([
            'message' => 'Notifications are sent!',
            //'results' => $results,
            'result' => $sendResult,

        ]);
    }*/

    /*public function sendNotificationToUser(User $user, string $title, string $body)
    {
        $firebaseService = new FirebaseService();
        return $firebaseService->sendNotificationToUser($user, $title, $body);
    }*/

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


    public function markAsRead($notificationId)
    {
        // Step 1: تأكد أن الإشعار موجود في جدول notifications
        $notification = \App\Models\Notification::find($notificationId);

        if (!$notification) {
            return response()->json(['message' => 'Notification not found'], 404);
        }

        // Step 2: البحث عن السجل في user_notifications للمستخدم الحالي
        $userNotification = \App\Models\UserNotification::where('notification_id', $notificationId)
            ->where('user_id', auth()->id())
            ->first();

        if (!$userNotification) {
            return response()->json(['message' => 'You do not have this notification assigned'], 403);
        }

        // Step 3: تحديد الإشعار كمقروء
        if (!$userNotification->is_read) {
            $userNotification->update(['is_read' => true]);
        }

        return response()->json(['message' => 'Notification marked as read successfully']);
    }
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

    //5 august

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
