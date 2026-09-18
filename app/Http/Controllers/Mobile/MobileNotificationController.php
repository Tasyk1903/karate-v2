<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserAlert;
use App\Services\Account\NotificationContent;
use App\Services\Team\TeamActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MobileNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,50']]);
        $alerts = $request->user()->userAlerts()->withPivot('read_at')
            ->orderByDesc('user_alerts.created_at')->orderByDesc('user_alerts.id')
            ->paginate($data['per_page'] ?? 20);

        return response()->json([
            'data' => $alerts->getCollection()->map(function (UserAlert $alert): array {
                $runs = NotificationContent::runs((string) $alert->message);

                return ['id' => $alert->id, 'source_label' => 'Karate Rating',
                    'message' => implode('', array_column($runs, 'text')), 'content' => $runs,
                    'created_at' => $alert->created_at?->toISOString(),
                    'read_at' => $alert->pivot->read_at, 'is_read' => filled($alert->pivot->read_at)];
            }),
            'meta' => ['current_page' => $alerts->currentPage(), 'last_page' => $alerts->lastPage(),
                'per_page' => $alerts->perPage(), 'total' => $alerts->total(), 'unread' => $this->count($request->user())],
        ]);
    }

    public function unread(Request $request): JsonResponse
    {
        return response()->json(['unread' => $this->count($request->user())]);
    }

    public function markAsRead(Request $request, UserAlert $notification): JsonResponse
    {
        return $this->mark($request->user(), $notification->id);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        return $this->mark($request->user());
    }

    private function mark(User $user, ?int $id = null): JsonResponse
    {
        $result = DB::transaction(function () use ($user, $id): array {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($id !== null) {
                abort_unless($user->userAlerts()->whereKey($id)->exists(), 404);
            }
            $time = now();
            $changed = DB::table('user_alert_user')->where('user_id', $user->id)
                ->when($id !== null, fn ($query) => $query->where('user_alert_id', $id))
                ->whereNull('read_at')->update(['read_at' => $time, 'updated_at' => $time]);
            if ($changed) {
                TeamActivity::record($user, $id === null ? 'mobile.notifications.read_all' : 'mobile.notification.read',
                    $id === null ? User::class : UserAlert::class, $id ?? $user->id,
                    ['notification_id' => $id, 'count' => $changed, 'old' => ['read_at' => null], 'new' => ['read_at' => $time->toISOString()]]);
            }

            return ['unread' => $this->count($user), 'changed' => $changed];
        }, 3);

        return response()->json($result);
    }

    private function count(User $user): int
    {
        return $user->userAlerts()->wherePivotNull('read_at')->count();
    }
}
