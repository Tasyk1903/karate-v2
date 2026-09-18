<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MobileTrainerSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorizeCoach($user);

        return response()->json([
            'settings' => $this->settingsPayload($user),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorizeCoach($user);

        $data = $request->validate([
            'can_attach_to_tournaments_for_students' => ['required', 'boolean'],
            'can_visible_number_fight_to_tournaments_for_students' => ['required', 'boolean'],
            'can_attach_to_examination_for_students' => ['required', 'boolean'],
        ]);

        $old = $this->settingsPayload($user);

        $user->forceFill($data)->save();
        $user->refresh();

        $this->writeActivityLog($user, 'Обновлены настройки тренера в мобильном приложении', 'mobile.trainer.settings.updated', User::class, $user->id, [
            'old' => $old,
            'new' => $this->settingsPayload($user),
        ]);

        return response()->json([
            'settings' => $this->settingsPayload($user),
        ]);
    }

    private function authorizeCoach(User $user): void
    {
        abort_unless($user->hasProjectRole('Coach'), 403);
    }

    private function settingsPayload(User $user): array
    {
        return [
            'can_attach_to_tournaments_for_students' => (bool) $user->can_attach_to_tournaments_for_students,
            'can_visible_number_fight_to_tournaments_for_students' => (bool) $user->can_visible_number_fight_to_tournaments_for_students,
            'can_attach_to_examination_for_students' => (bool) $user->can_attach_to_examination_for_students,
        ];
    }

    private function writeActivityLog(User $causer, string $description, string $event, ?string $subjectType, int|string|null $subjectId, array $properties = []): void
    {
        DB::table('activity_log')->insert([
            'log_name' => 'mobile',
            'description' => $description,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'event' => $event,
            'causer_type' => User::class,
            'causer_id' => $causer->id,
            'properties' => json_encode($properties, JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
