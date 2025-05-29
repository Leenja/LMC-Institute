<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

use Illuminate\Support\Facades\App;


class SendNotificationController extends Controller
{
    protected $messaging;

    public function __construct(Messaging $messaging)
    {
        $this->messaging = $messaging;
    }

    /*  public function sendNotification(Request $request)
    {
        $deviceKey = $request->input('device_key');
        $messageText = $request->input('message');
        $title = $request->input('title');

        $message = CloudMessage::withTarget('token', $deviceKey)
            ->withNotification(Notification::create($title, $messageText));

        $this->messaging->send($message);

        return response()->json(['success' => true]);
    }*/


    public function sendNotification(Request $request)
    {
        $deviceKey = $request->input('device_key');
        $messageText = $request->input('message');
        $title = $request->input('title');

        $message = CloudMessage::withTarget('token', $deviceKey)
            ->withNotification(Notification::create($title, $messageText));

        try {
            $this->messaging->send($message);
            return response()->json(['message' => 'Notification sent!']);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Failed to send notification',
                'details' => $e->getMessage()
            ], 500);
        }
    }
}
