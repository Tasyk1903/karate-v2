<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Account\CoachProfileAccess;
use App\Services\Account\UpdateCoachProfile;
use App\Services\Students\BeltPresentation;
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
        return app(BeltPresentation::class)->forRank($rank);
    }
}
