<?php

namespace App\Core\Notifications\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'unread_count' => $user->unreadNotifications()->count(),
                'notifications' => $user->notifications()->latest()->limit(20)->get()
                    ->map(fn ($notification) => [
                        'id' => $notification->id,
                        'title' => $notification->data['title'] ?? '',
                        'body' => $notification->data['body'] ?? '',
                        'action_url' => $notification->data['action_url'] ?? null,
                        'type' => $notification->data['type'] ?? 'info',
                        'read_at' => $notification->read_at?->toISOString(),
                        'created_at' => $notification->created_at?->toISOString(),
                    ]),
            ],
        ]);
    }

    public function readAll(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['message' => 'All notifications marked as read.']);
    }
}
