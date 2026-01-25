<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Traits\HttpResponses;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    use HttpResponses;

    /**
     * Display a listing of the user's notifications.
     */
    public function index()
    {
        $notifications = Auth::user()->notifications()
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->success($notifications);
    }

    /**
     * Mark a specific notification as read.
     */
    public function markAsRead(string $id)
    {
        $notification = Auth::user()->notifications()->findOrFail($id);

        $notification->update([
            'read_at' => now()
        ]);

        return $this->success($notification, 'Notification marquée comme lue.');
    }

    /**
     * Mark all notifications as read.
     */
    public function markAllAsRead()
    {
        Auth::user()->notifications()->whereNull('read_at')->update([
            'read_at' => now()
        ]);

        return $this->success(null, 'Toutes les notifications ont été marquées comme lues.');
    }

    /**
     * Remove the specified notification.
     */
    public function destroy(string $id)
    {
        $notification = Auth::user()->notifications()->findOrFail($id);
        $notification->delete();

        return $this->success(null, 'Notification supprimée.');
    }
}
