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
     *
     * Résultat borné (50 par défaut, 200 max via `limit`) : un compte ancien
     * peut accumuler des milliers de notifications, on ne les charge jamais
     * toutes. La réponse reste un tableau plat sous `data` — l'app mobile
     * (NotificationRepository KMP) dépend de cette forme.
     *
     * @queryParam limit int Nombre maximum de notifications (défaut 50, max 200).
     */
    public function index(Request $request)
    {
        $limit = min(max((int) $request->input('limit', 50), 1), 200);

        $notifications = Auth::user()->notifications()
            ->orderBy('created_at', 'desc')
            ->limit($limit)
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
            'read_at' => now(),
        ]);

        return $this->success($notification, 'Notification marquée comme lue.');
    }

    /**
     * Mark all notifications as read.
     */
    public function markAllAsRead()
    {
        Auth::user()->notifications()->whereNull('read_at')->update([
            'read_at' => now(),
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
