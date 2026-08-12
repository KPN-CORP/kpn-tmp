<?php

namespace App\Http\Controllers;

use App\Models\ApprovalNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * In-app approval notifications for the signed-in user (mark read / read all).
 * The list itself is shared to the frontend via HandleInertiaRequests.
 */
class NotificationController extends Controller
{
    public function markRead(Request $request, ApprovalNotification $notification): RedirectResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 403);

        if (! $notification->read_at) {
            $notification->update(['read_at' => now()]);
        }

        return back();
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        ApprovalNotification::where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return back();
    }
}
