<?php

namespace App\Http\Controllers\Panel\Account;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Account\AccountAccess;
use App\Services\Account\SafeContent;
use App\Services\Team\TeamActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        AccountAccess::authorizeReader($user);
        $rows = $user->userAlerts()->orderByDesc('user_alerts.created_at')->orderByDesc('user_alerts.id')->paginate(20);
        $rows->through(fn ($alert) => ['id' => $alert->id, 'content' => SafeContent::html($alert->message), 'read_at' => $alert->pivot->read_at]);

        return response()->json($rows->toArray() + ['unread' => $user->userAlerts()->wherePivotNull('read_at')->count()]);
    }

    public function read(Request $request, ?int $notification = null): JsonResponse
    {
        $user = $request->user();
        AccountAccess::authorizeReader($user);
        DB::transaction(function () use ($user, $notification): void {
            if ($notification) {
                abort_unless($user->userAlerts()->whereKey($notification)->exists(), 404);
            }
            $changed = DB::table('user_alert_user')->where('user_id', $user->id)
                ->when($notification, fn ($query) => $query->where('user_alert_id', $notification))
                ->whereNull('read_at')->update(['read_at' => now(), 'updated_at' => now()]);
            if ($changed) {
                TeamActivity::record($user, 'notification.read', User::class, $user->id,
                    ['notification_id' => $notification, 'count' => $changed, 'old' => ['read_at' => null], 'new' => ['read_at' => now()->toISOString()]]);
            }
        });

        return response()->json(['unread' => $user->userAlerts()->wherePivotNull('read_at')->count()]);
    }
}
