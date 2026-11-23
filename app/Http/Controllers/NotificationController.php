<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function read(Request $request, string $notification): RedirectResponse
    {
        $notification = $request->user()->notifications()->findOrFail($notification);
        $notification->markAsRead();

        return redirect($notification->data['url']);
    }

    public function mark(Request $request, string $notification): RedirectResponse
    {
        $notification = $request->user()->notifications()->findOrFail($notification);
        $request->validate(['read' => ['required', 'boolean']]);
        $request->boolean('read') ? $notification->markAsRead() : $notification->markAsUnread();

        return back();
    }

    public function destroy(Request $request, string $notification): RedirectResponse
    {
        $request->user()->notifications()->findOrFail($notification)->delete();

        return back();
    }

    public function destroyRead(Request $request): RedirectResponse
    {
        $request->user()->readNotifications()->delete();

        return back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }

    public function preferences(Request $request): RedirectResponse
    {
        $request->user()->forceFill($request->validate([
            'notify_snipe_it' => ['required', 'boolean'],
            'notify_mikrotik' => ['required', 'boolean'],
            'notify_unifi' => ['required', 'boolean'],
            'notify_google_drive' => ['required', 'boolean'],
        ]))->save();

        return back();
    }
}
