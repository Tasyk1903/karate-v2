<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Services\RatingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileRatingController extends Controller
{
    public function __invoke(Request $request, RatingService $rating): JsonResponse
    {
        $result = $rating->resolve([
            'year' => $request->string('year', (string) now()->year)->toString(),
            'discipline' => $request->string('discipline', 'kumite')->toString(),
            'view_mode' => $request->string('view_mode', 'all')->toString(),
            'weight_category' => $request->input('weight_category'),
            'age_band' => $request->input('age_band'),
            'gender' => $request->input('gender'),
            'organization_id' => $request->input('organization_id'),
            'region_id' => $request->input('region_id'),
        ]);

        return response()->json($this->format($result));
    }

    private function format(array $result): array
    {
        $mapAthlete = function (array $item): array {
            return [
                'id' => $item['student_id'] ?? $item['id'] ?? $item['coach_id'] ?? null,
                'name' => $item['full_name'] ?? $item['name'] ?? '—',
                'coach' => $item['coach'] ?? $item['coach_name'] ?? $item['coach_label'] ?? null,
                'club' => $item['coach_club'] ?? $item['club'] ?? $item['coach_label'] ?? null,
                'points' => (int) ($item['rating_points'] ?? $item['points'] ?? 0),
                'avatar_url' => ! empty($item['avatar'])
                    ? asset('storage/' . $item['avatar'])
                    : ($item['avatar_url'] ?? null),
            ];
        };

        $groups = collect($result['groups'] ?? [])
            ->map(function (array $group) use ($mapAthlete): array {
                return [
                    'id' => $group['key'] ?? $group['id'] ?? null,
                    'title' => $group['title'] ?? $group['name'] ?? $group['label'] ?? '—',
                    'gender' => $group['subtitle'] ?? $group['gender'] ?? null,
                    'leader' => ! empty($group['leader']) ? $mapAthlete($group['leader']) : null,
                    'items' => collect($group['items'] ?? [])->map($mapAthlete)->values()->all(),
                ];
            })
            ->values()
            ->all();

        $trainerRanking = $result['trainerRanking'] ?? [];

        return [
            'groups' => $groups,
            'trainer_ranking' => [
                'leader' => ! empty($trainerRanking['leader']) ? $mapAthlete($trainerRanking['leader']) : null,
                'items' => collect($trainerRanking['items'] ?? [])->map($mapAthlete)->values()->all(),
            ],
            'filter_options' => [
                'view_modes' => $result['viewModeOptions'] ?? [],
                'weights' => $result['weightOptions'] ?? [],
                'age_bands' => $result['ageBandOptions'] ?? [],
                'genders' => $result['genderOptions'] ?? [],
                'organizations' => $result['organizationOptions'] ?? [],
                'regions' => $result['regionOptions'] ?? [],
            ],
            'filters' => $result['filters'] ?? [],
        ];
    }
}
