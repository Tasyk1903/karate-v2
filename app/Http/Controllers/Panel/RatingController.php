<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Services\RatingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RatingController extends Controller
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

        return response()->json($this->withAvatarUrls($result));
    }

    private function withAvatarUrls(array $result): array
    {
        $mapItem = function (array $item): array {
            if (! empty($item['avatar'])) {
                $item['avatar_url'] = asset('storage/' . $item['avatar']);
            }

            return $item;
        };

        $result['groups'] = $result['groups']
            ->map(function (array $group) use ($mapItem): array {
                $group['items'] = collect($group['items'])->map($mapItem)->all();
                $group['leader'] = $group['leader'] ? $mapItem($group['leader']) : null;

                return $group;
            })
            ->values();

        if (! empty($result['trainerRanking']['items'])) {
            $result['trainerRanking']['items'] = collect($result['trainerRanking']['items'])->map($mapItem)->all();
            $result['trainerRanking']['leader'] = $result['trainerRanking']['leader']
                ? $mapItem($result['trainerRanking']['leader'])
                : null;
        }

        return $result;
    }
}
