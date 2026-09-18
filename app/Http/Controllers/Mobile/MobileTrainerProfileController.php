<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Account\CoachProfileAccess;
use App\Services\Account\UpdateCoachProfile;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileTrainerProfileController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->hasProjectRole('Coach'), 403);

        return response()->json([
            'trainer' => [
                'id' => $user->id,
                'full_name' => trim($user->full_name) ?: $user->name,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'patronymic' => $user->patronymic,
                'capabilities' => app(CoachProfileAccess::class)->capabilities($user),
                'email' => $user->email,
                'avatar_url' => $user->avatar ? asset('storage/'.$user->avatar) : null,
                'club' => $user->club,
                'gender' => $user->gender,
                'gender_label' => $this->genderLabel($user->gender),
                'age' => $this->ageNumber($user->birthday),
                'birthday' => $this->dateLabel($user->birthday),
                'weight' => $user->weight,
                'height' => $user->height,
                'rang' => $user->rang,
                'city_training' => $user->city_training,
                'belt' => $this->beltFor((string) $user->rang),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->hasProjectRole('Coach'), 403);

        $updated = app(UpdateCoachProfile::class)->update($user, $request);
        $request->setUserResolver(fn () => $updated);

        return $this($request);
    }

    private function ageNumber(mixed $birthday): ?int
    {
        return $birthday ? Carbon::parse($birthday)->age : null;
    }

    private function dateLabel(mixed $date): ?string
    {
        return $date ? Carbon::parse($date)->format('d.m.Y') : null;
    }

    private function genderLabel(?string $gender): ?string
    {
        return match ($gender) {
            'm', 'male' => __('mobile.male'),
            'f', 'female' => __('mobile.female'),
            default => null,
        };
    }

    private function beltFor(string $rank): array
    {
        preg_match('/\d+/', $rank, $matches);
        $number = isset($matches[0]) ? (int) $matches[0] : null;
        $isDan = str_contains(mb_strtolower($rank), 'дан') || str_contains(mb_strtolower($rank), 'dan');

        if ($isDan) {
            return ['label_key' => 'blackBelt', 'color' => '#111827', 'accent' => '#d6a233', 'progress' => 100];
        }

        $map = [
            10 => ['whiteBelt', '#f8fafc', '#d1d5db', 10],
            9 => ['orangeBelt', '#fb923c', '#fde68a', 20],
            8 => ['blueBelt', '#2563eb', '#f8fafc', 30],
            7 => ['blueBelt', '#2563eb', '#facc15', 40],
            6 => ['yellowBelt', '#facc15', '#f8fafc', 50],
            5 => ['yellowBelt', '#facc15', '#22c55e', 60],
            4 => ['greenBelt', '#16a34a', '#f8fafc', 70],
            3 => ['greenBelt', '#16a34a', '#a16207', 80],
            2 => ['brownBelt', '#92400e', '#f8fafc', 90],
            1 => ['brownBelt', '#92400e', '#111827', 96],
        ];

        [$labelKey, $color, $accent, $progress] = $map[$number] ?? ['beltNotSet', '#e5e7eb', '#9ca3af', 0];

        return ['label_key' => $labelKey, 'color' => $color, 'accent' => $accent, 'progress' => $progress];
    }
}
