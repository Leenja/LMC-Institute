<?php

/*namespace App\Services;

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

    //new
    public function sendNotificationToUsers($users, string $title, string $body)
    {
        // استخراج كل التوكنات من المستخدمين
        $allTokens = $users->flatMap(fn($user) => $user->firebaseTokens->pluck('device_key'))
            ->unique()
            ->values()
            ->toArray();

        if (empty($allTokens)) {
            return ['message' => 'No registered devices found for these users', 'success' => false];
        }

        $notification = Notification::create($title, $body);
        $message = CloudMessage::new()->withNotification($notification);

        // تقسيم التوكنات إلى مجموعات كل منها تحتوي على 500 توكن كحد أقصى
        $tokenChunks = array_chunk($allTokens, 500);

        $totalSuccesses = 0;
        $totalFailures = 0;

        foreach ($tokenChunks as $chunk) {
            $response = $this->messaging->sendMulticast($message, $chunk);
            $totalSuccesses += $response->successes()->count();
            $totalFailures += $response->failures()->count();
        }

        return [
            'message' => 'Notification sent to all users in chunks',
            'success' => true,
            'success_count' => $totalSuccesses,
            'failure_count' => $totalFailures,
        ];
    }
}*/

namespace App\Services;

use App\Models\DeviceToken;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Psr\Log\LoggerInterface;

class FirebaseService
{
    public function __construct(private Messaging $messaging, private LoggerInterface $logger) {}

    public function sendToTokens(array $tokens, string $title, string $body): array
    {
        $tokens = array_values(array_unique(array_filter($tokens)));
        if (empty($tokens)) {
            return ['success' => false, 'message' => 'No tokens'];
        }

        $message = CloudMessage::new()
            ->withNotification(Notification::create($title, $body));

        $chunks = array_chunk($tokens, 500);
        $ok = 0;
        $fail = 0;
        $invalid = [];

        foreach ($chunks as $chunk) {
            try {
                $resp = $this->messaging->sendMulticast($message, $chunk);

                $ok   += $resp->successes()->count();
                $fail += $resp->failures()->count();

                foreach ($resp->failures()->getItems() as $f) {
                    $code = $f->error()->getCode();
                    if (in_array($code, ['registration-token-not-registered', 'invalid-argument'], true)) {
                        $invalid[] = $f->target()->value();
                    }
                }
            } catch (\Throwable $e) {
                // سجّل الخطأ واستمر (لا توقف باقي الدُفعات)
                $this->logger->error('Firebase sendMulticast error', [
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        if ($invalid) {
            DeviceToken::whereIn('device_key', array_unique($invalid))->delete();
        }

        return [
            'success'        => true,
            'success_count'  => $ok,
            'failure_count'  => $fail,
            'invalid_removed' => count($invalid),
        ];
    }

    public function sendToTopic(string $topic, string $title, string $body): array
    {
        $message = CloudMessage::new()
            ->withNotification(Notification::create($title, $body))
            ->withChangedTarget('topic', $topic);

        try {
            $this->messaging->send($message);
            return ['success' => true, 'topic' => $topic];
        } catch (\Throwable $e) {
            $this->logger->error('Firebase sendToTopic error', ['topic' => $topic, 'error' => $e->getMessage()]);
            return ['success' => false, 'topic' => $topic, 'error' => $e->getMessage()];
        }
    }

    public function subscribeTokensToTopics(array $tokens, array $topics): void
    {
        $tokens = array_values(array_unique(array_filter($tokens)));
        $topics = array_values(array_unique(array_filter($topics)));
        if (!$tokens || !$topics) return;

        foreach ($topics as $topic) {
            $this->messaging->subscribeToTopic($topic, $tokens);
        }
    }

    public function unsubscribeTokensFromTopics(array $tokens, array $topics): void
    {
        $tokens = array_values(array_unique(array_filter($tokens)));
        $topics = array_values(array_unique(array_filter($topics)));
        if (!$tokens || !$topics) return;

        foreach ($topics as $topic) {
            $this->messaging->unsubscribeFromTopic($topic, $tokens);
        }
    }
}
