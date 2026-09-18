<?php

namespace App\Services;

use App\Models\KataPool;
use App\Models\Pool;
use App\Models\Region;
use App\Models\Scale;
use App\Models\Tournament;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class RatingService
{
    private const HIDDEN_ORGANIZATION_IDS = [3443];

    private const DISCIPLINES = [
        'kumite' => 'exports.kumite',
        'kata' => 'exports.kata',
    ];

    private const VIEW_MODES = [
        'all' => 'exports.all_categories',
        'p4p' => 'P4P',
    ];

    private const AGE_BANDS = [
        '8-9' => ['label' => '8-9', 'from' => 8, 'to' => 9],
        '10-11' => ['label' => '10-11', 'from' => 10, 'to' => 11],
        '12-13' => ['label' => '12-13', 'from' => 12, 'to' => 13],
        '14-15' => ['label' => '14-15', 'from' => 14, 'to' => 15],
        '16-17' => ['label' => '16-17', 'from' => 16, 'to' => 17],
        '18+' => ['label' => '18+', 'from' => 18, 'to' => 99],
    ];

    private const WEIGHT_CATEGORY_LIMITS = [
        '8-9' => [
            'm' => [25, 30, 35, 40, '40+'],
            'f' => [25, 30, 35, 40, '40+'],
        ],
        '10-11' => [
            'm' => [30, 35, 40, 45, 50, '50+'],
            'f' => [30, 35, 40, 45, 50, '50+'],
        ],
        '12-13' => [
            'm' => [35, 40, 45, 50, 55, 60, '60+'],
            'f' => [35, 40, 45, 50, 55, '55+'],
        ],
        '14-15' => [
            'm' => [40, 45, 50, 55, 60, 65, 70, '70+'],
            'f' => [40, 45, 50, 55, '55+'],
        ],
        '16-17' => [
            'm' => [50, 55, 60, 65, 70, 75, 80, '80+'],
            'f' => [50, 55, 60, '60+'],
        ],
        '18+' => [
            'm' => [60, 70, 80, 90, '90+'],
            'f' => [55, 65, '65+'],
        ],
    ];

    private const PODIUM_MULTIPLIERS = [
        'gold' => 3,
        'silver' => 2,
        'bronze' => 1,
    ];

    private const SCALE_COEFFICIENTS = [
        '8-9' => [
            Scale::CITY => 20,
            Scale::REGION => 30,
            Scale::FEDERAL_DISTRICT => 40,
        ],
        '10-11' => [
            Scale::CITY => 30,
            Scale::REGION => 40,
            Scale::FEDERAL_DISTRICT => 50,
        ],
        '12-13' => [
            Scale::CITY => 40,
            Scale::REGION => 50,
            Scale::FEDERAL_DISTRICT => 60,
        ],
        '14-15' => [
            Scale::CITY => 50,
            Scale::REGION => 60,
            Scale::FEDERAL_DISTRICT => 70,
        ],
        '16-17' => [
            Scale::CITY => 60,
            Scale::REGION => 70,
            Scale::FEDERAL_DISTRICT => 80,
        ],
        '18+' => [
            Scale::CITY => 70,
            Scale::REGION => 80,
            Scale::FEDERAL_DISTRICT => 90,
        ],
    ];

    private const COACH_POSITION_POINTS = [
        1 => 10,
        2 => 5,
        3 => 4,
        4 => 3,
        5 => 2,
    ];

    private array $resolvedCache = [];

    public function resolve(array $filters): array
    {
        $filters = $this->normalizeFilters($filters);
        $cacheKey = md5(app()->getLocale().json_encode($filters, JSON_UNESCAPED_UNICODE));

        if (isset($this->resolvedCache[$cacheKey])) {
            return $this->resolvedCache[$cacheKey];
        }

        $groups = $filters['discipline'] === 'kata'
            ? $this->buildKataGroups($filters)
            : $this->buildKumiteGroups($filters);

        return $this->resolvedCache[$cacheKey] = [
            'groups' => $groups,
            'filters' => $filters,
            'trainerRanking' => $this->buildCoachRanking($groups),
            'disciplineOptions' => array_map(fn ($label) => __($label), self::DISCIPLINES),
            'viewModeOptions' => array_map(fn ($label) => __($label), self::VIEW_MODES),
            'weightOptions' => $this->weightOptions($filters),
            'ageBandOptions' => collect(self::AGE_BANDS)
                ->mapWithKeys(fn (array $band, string $key) => [$key => $band['label']])
                ->all(),
            'genderOptions' => [
                'm' => __('exports.male_short'),
                'f' => __('exports.female_short'),
            ],
            'organizationOptions' => User::query()
                ->role('Organization')
                ->whereNotIn('id', self::HIDDEN_ORGANIZATION_IDS)
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all(),
            'regionOptions' => Region::query()
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all(),
            'summary' => [
                'discipline' => __(self::DISCIPLINES[$filters['discipline']] ?? 'exports.kumite'),
                'year' => $filters['year'],
                'groups_count' => $groups->count(),
                'athletes_count' => $groups->sum(fn (array $group) => count($group['items'])),
            ],
        ];
    }

    public function resolveUserTopPosition(User $user, string $discipline = 'kumite'): ?array
    {
        $result = $this->resolve([
            'year' => (string) now()->year,
            'discipline' => $discipline,
            'weight_category' => null,
            'age_band' => null,
            'gender' => null,
            'organization_id' => null,
            'region_id' => null,
        ]);

        $best = null;

        foreach ($result['groups'] as $group) {
            foreach ($group['items'] as $index => $item) {
                if ((int) $item['student_id'] !== (int) $user->id) {
                    continue;
                }

                $candidate = [
                    'position' => $index + 1,
                    'label' => 'TOP-'.($index + 1),
                    'group' => $group['name'],
                    'subtitle' => $group['subtitle'],
                    'rating_points' => (int) ($item['rating_points'] ?? 0),
                    'discipline' => self::DISCIPLINES[$discipline] ?? $discipline,
                    'year' => (string) now()->year,
                ];

                if ($best === null || $candidate['position'] < $best['position']) {
                    $best = $candidate;
                }
            }
        }

        return $best;
    }

    private function normalizeFilters(array $filters): array
    {
        $normalized = [
            'year' => $this->normalizeYear($filters['year'] ?? null),
            'discipline' => in_array(($filters['discipline'] ?? 'kumite'), array_keys(self::DISCIPLINES), true)
                ? ($filters['discipline'] ?? 'kumite')
                : 'kumite',
            'weight_category' => filled($filters['weight_category'] ?? null) ? (string) $filters['weight_category'] : null,
            'view_mode' => in_array(($filters['view_mode'] ?? 'all'), array_keys(self::VIEW_MODES), true)
                ? ($filters['view_mode'] ?? 'all')
                : 'all',
            'age_band' => array_key_exists(($filters['age_band'] ?? ''), self::AGE_BANDS) ? $filters['age_band'] : null,
            'gender' => in_array(($filters['gender'] ?? null), ['m', 'f'], true) ? $filters['gender'] : null,
            'organization_id' => filled($filters['organization_id'] ?? null) ? (int) $filters['organization_id'] : null,
            'region_id' => filled($filters['region_id'] ?? null) ? (int) $filters['region_id'] : null,
        ];
        if ($normalized['discipline'] === 'kata') {
            $normalized['view_mode'] = 'all';
            $normalized['weight_category'] = null;
        } elseif ($normalized['view_mode'] === 'p4p' || ! array_key_exists($normalized['weight_category'] ?? '', $this->weightOptions($normalized))) {
            $normalized['weight_category'] = null;
        }

        return $normalized;
    }

    private function weightOptions(array $filters): array
    {
        if (($filters['discipline'] ?? 'kumite') !== 'kumite') {
            return [];
        }

        $options = collect();

        $ageBands = $filters['age_band']
            ? [$filters['age_band']]
            : array_keys(self::AGE_BANDS);

        $genders = $filters['gender']
            ? [$filters['gender']]
            : ['m', 'f'];

        foreach ($ageBands as $ageBandKey) {
            foreach ($genders as $gender) {
                foreach ($this->weightBandsFor($ageBandKey, $gender) as $band) {
                    $options->put($band['key'], [
                        'label' => $band['label'],
                        'sort' => $band['sort'],
                    ]);
                }
            }
        }

        return $options
            ->sortBy(fn (array $band) => $band['sort'])
            ->mapWithKeys(fn (array $band, string $key) => [$key => $band['label']])
            ->all();
    }

    private function buildKumiteGroups(array $filters): Collection
    {
        $pools = Pool::query()
            ->with([
                'tournament.scale',
                'student.coach',
                'opponent.coach',
            ])
            ->whereHas('tournament', function ($query) use ($filters) {
                $query
                    ->whereNull('deleted_at')
                    ->where('tournament_type', Tournament::KUMITE)
                    ->whereHas('scale', fn ($scaleQuery) => $scaleQuery->where('is_rating', true))
                    ->whereYear('date', (int) $filters['year'])
                    ->when(
                        $filters['region_id'],
                        fn ($q, $regionId) => $q->where('region_id', $regionId),
                    );
            })
            ->where(function ($query) {
                $query->whereNotNull('winner_id')
                    ->orWhereNotNull('winner_id_1rd_robbin')
                    ->orWhereNotNull('winner_id_2rd_robbin')
                    ->orWhereNotNull('winner_id_3rd_robbin');
            })
            ->get();

        $scores = [];

        foreach ($pools->groupBy(fn (Pool $pool) => $pool->tournament_id.':'.$pool->list_id) as $group) {
            /** @var Collection<int, Pool> $group */
            $this->accumulateKumiteCategoryScores($scores, $group);
        }

        $athletes = collect($scores)
            ->map(function (array $score) {
                $student = $score['student'];

                return [
                    'student_id' => (int) $student->id,
                    'full_name' => trim(($student->last_name ?? '').' '.($student->first_name ?? '')),
                    'avatar' => $student->avatar,
                    'gender' => $student->gender,
                    'weight' => $student->weight !== null ? (int) $student->weight : null,
                    'birthday' => $student->birthday,
                    'rang' => $student->rang,
                    'student_organization_id' => $student->organization_id,
                    'coach_organization_id' => $student->coach?->organization_id,
                    'coach_id' => $student->coach?->id,
                    'coach_name' => trim(($student->coach?->last_name ?? '').' '.($student->coach?->first_name ?? '')),
                    'coach_club' => $student->coach?->club,
                    'coach_label' => $this->formatCoachLabel(
                        $student->coach?->last_name,
                        $student->coach?->first_name,
                        $student->coach?->club
                    ),
                    'rating_points' => (int) $score['rating_points'],
                    'wins_count' => (int) $score['wins_count'],
                ];
            })
            ->values();

        return $this->buildWeightBasedGroups($athletes, $filters);
    }

    private function buildKataGroups(array $filters): Collection
    {
        $pools = KataPool::query()
            ->with([
                'tournament.scale',
                'student.coach',
            ])
            ->whereHas('tournament', function ($query) use ($filters) {
                $query
                    ->whereNull('deleted_at')
                    ->where('tournament_type', Tournament::KATA)
                    ->whereHas('scale', fn ($scaleQuery) => $scaleQuery->where('is_rating', true))
                    ->whereYear('date', (int) $filters['year'])
                    ->when(
                        $filters['region_id'],
                        fn ($q, $regionId) => $q->where('region_id', $regionId),
                    );
            })
            ->where(function ($query) {
                $query->where('winner_1', true)
                    ->orWhere('winner_2', true)
                    ->orWhere('winner_3', true);
            })
            ->get();

        $athletes = [];

        foreach ($pools as $pool) {
            $student = $pool->student;

            if (! $student) {
                continue;
            }

            if (! $student->shouldCountCompetitiveResultAt($pool->tournament?->date)) {
                continue;
            }

            if ($filters['organization_id']) {
                $orgId = (int) $filters['organization_id'];
                $studentOrgId = (int) ($student->organization_id ?? 0);
                $coachOrgId = (int) ($student->coach?->organization_id ?? 0);

                if ($studentOrgId !== $orgId && $coachOrgId !== $orgId) {
                    continue;
                }
            }

            $points = $pool->winner_1 ? 3 : ($pool->winner_2 ? 2 : ($pool->winner_3 ? 1 : 0));

            if (! isset($athletes[$student->id])) {
                $athletes[$student->id] = [
                    'student_id' => $student->id,
                    'full_name' => trim(($student->last_name ?? '').' '.($student->first_name ?? '')),
                    'avatar' => $student->avatar,
                    'gender' => $student->gender,
                    'weight' => $student->weight !== null ? (int) $student->weight : null,
                    'birthday' => $student->birthday,
                    'rang' => $student->rang,
                    'student_organization_id' => $student->organization_id,
                    'coach_organization_id' => $student->coach?->organization_id,
                    'coach_id' => $student->coach?->id,
                    'coach_name' => trim(($student->coach?->last_name ?? '').' '.($student->coach?->first_name ?? '')),
                    'coach_club' => $student->coach?->club,
                    'coach_label' => $this->formatCoachLabel(
                        $student->coach?->last_name,
                        $student->coach?->first_name,
                        $student->coach?->club
                    ),
                    'rating_points' => 0,
                    'wins_count' => 0,
                ];
            }

            $athletes[$student->id]['rating_points'] += $points;

            if ($pool->winner_1) {
                $athletes[$student->id]['wins_count']++;
            }
        }

        return $this->buildWeightBasedGroups(collect(array_values($athletes)), $filters);
    }

    private function buildWeightBasedGroups(Collection $athletes, array $filters): Collection
    {
        $prepared = $athletes
            ->map(function (array $athlete) {
                $athlete['age_number'] = $this->resolveAge($athlete['birthday']);
                $athlete['age_band_key'] = $this->resolveAgeBandKey($athlete['age_number']);
                $athlete['rank_number'] = $this->normalizeRankNumber((string) ($athlete['rang'] ?? ''));
                $athlete['weight_band'] = $this->resolveWeightBand(
                    $athlete['weight'],
                    $athlete['age_band_key'],
                    $athlete['gender']
                );
                $athlete['summary'] = $this->formatWinsSummary($athlete['wins_count']);

                return $athlete;
            })
            ->filter(function (array $athlete) use ($filters) {
                if (! in_array($athlete['gender'], ['m', 'f'], true)) {
                    return false;
                }

                if ($athlete['age_number'] === null || $athlete['age_number'] < 8) {
                    return false;
                }

                if ($athlete['rank_number'] === null || $athlete['rank_number'] < 1 || $athlete['rank_number'] > 8) {
                    return false;
                }

                if ((int) ($athlete['coach_id'] ?? 0) <= 0) {
                    return false;
                }

                if ($filters['gender'] && $athlete['gender'] !== $filters['gender']) {
                    return false;
                }

                $studentOrganizationId = (int) ($athlete['student_organization_id'] ?? 0);
                $coachOrganizationId = (int) ($athlete['coach_organization_id'] ?? 0);

                if (
                    in_array($studentOrganizationId, self::HIDDEN_ORGANIZATION_IDS, true)
                    || in_array($coachOrganizationId, self::HIDDEN_ORGANIZATION_IDS, true)
                ) {
                    return false;
                }

                if ($filters['organization_id']) {
                    $organizationId = (int) $filters['organization_id'];

                    if ($studentOrganizationId !== $organizationId && $coachOrganizationId !== $organizationId) {
                        return false;
                    }
                }

                if ($filters['age_band']) {
                    $band = self::AGE_BANDS[$filters['age_band']];

                    if ($athlete['age_number'] < $band['from'] || $athlete['age_number'] > $band['to']) {
                        return false;
                    }
                }

                return true;
            })
            ->values();

        if (($filters['discipline'] ?? 'kumite') === 'kata') {
            return $this->buildAgeBasedGroups($prepared, $filters);
        }

        $p4pFiltered = $prepared;

        $weightFiltered = $prepared
            ->filter(function (array $athlete) use ($filters) {
                if ($filters['weight_category'] && ($athlete['weight_band']['key'] ?? null) !== $filters['weight_category']) {
                    return false;
                }

                return true;
            })
            ->values();

        $groups = collect();

        $ageBands = $filters['age_band']
            ? [$filters['age_band'] => self::AGE_BANDS[$filters['age_band']]]
            : self::AGE_BANDS;

        foreach ($ageBands as $ageBandKey => $ageBand) {
            $ageLabel = $this->formatAgeBandLabel($ageBandKey);
            $ageSort = array_search($ageBandKey, array_keys(self::AGE_BANDS), true) + 1;

            foreach (['m', 'f'] as $gender) {
                if ($filters['gender'] && $filters['gender'] !== $gender) {
                    continue;
                }

                $genderLabel = $this->resolveGenderAgeLabel($gender, $ageBandKey);

                $p4pItems = $p4pFiltered
                    ->filter(fn (array $athlete) => $athlete['gender'] === $gender
                        && $athlete['age_number'] >= $ageBand['from']
                        && $athlete['age_number'] <= $ageBand['to'])
                    ->sortByDesc(fn (array $athlete) => [$athlete['rating_points'], $athlete['wins_count'], $athlete['full_name']])
                    ->take(15)
                    ->values();

                if (($filters['discipline'] ?? 'kumite') === 'kumite' && (($filters['view_mode'] ?? 'all') === 'p4p' || $filters['age_band']) && $p4pItems->isNotEmpty()) {
                    $renderItems = $this->mapRenderableItems($p4pItems);

                    $groups->push([
                        'key' => 'p4p-'.$ageBandKey.'-'.$gender,
                        'name' => 'P4P',
                        'subtitle' => $genderLabel.' • '.$ageLabel.' • '.__('exports.outside_category'),
                        'sort' => sprintf('0%02d-%s', $ageSort, $gender),
                        'items' => $renderItems->all(),
                        'leader' => $renderItems->first(),
                    ]);
                }
            }

            if (($filters['view_mode'] ?? 'all') === 'p4p') {
                continue;
            }

            foreach (['m', 'f'] as $gender) {
                if ($filters['gender'] && $filters['gender'] !== $gender) {
                    continue;
                }

                $genderLabel = $this->resolveGenderAgeLabel($gender, $ageBandKey);

                foreach ($this->weightBandsFor($ageBandKey, $gender) as $band) {
                    $items = $weightFiltered
                        ->filter(fn (array $athlete) => $athlete['gender'] === $gender
                            && $athlete['age_band_key'] === $ageBandKey
                            && ($athlete['weight_band']['key'] ?? null) === $band['key'])
                        ->sortByDesc(fn (array $athlete) => [$athlete['rating_points'], $athlete['wins_count'], $athlete['full_name']])
                        ->take(15)
                        ->values();

                    if ($items->isEmpty()) {
                        continue;
                    }

                    $renderItems = $this->mapRenderableItems($items);

                    $groups->push([
                        'key' => 'weight-'.$ageBandKey.'-'.$gender.'-'.$band['key'],
                        'name' => $ageLabel.' • '.$band['label'],
                        'subtitle' => $genderLabel,
                        'sort' => sprintf('1%02d-%04d-%s', $ageSort, $band['sort'], $gender),
                        'items' => $renderItems->all(),
                        'leader' => $renderItems->first(),
                    ]);
                }
            }
        }

        return $groups->values();
    }

    private function buildAgeBasedGroups(Collection $athletes, array $filters): Collection
    {
        $groups = collect();

        $ageBands = $filters['age_band']
            ? [$filters['age_band'] => self::AGE_BANDS[$filters['age_band']]]
            : self::AGE_BANDS;

        foreach ($ageBands as $ageBandKey => $ageBand) {
            $ageLabel = $this->formatAgeBandLabel($ageBandKey);
            $ageSort = array_search($ageBandKey, array_keys(self::AGE_BANDS), true) + 1;

            foreach (['m', 'f'] as $gender) {
                if ($filters['gender'] && $filters['gender'] !== $gender) {
                    continue;
                }

                $genderLabel = $this->resolveGenderAgeLabel($gender, $ageBandKey);

                $items = $athletes
                    ->filter(fn (array $athlete) => $athlete['gender'] === $gender
                        && $athlete['age_band_key'] === $ageBandKey)
                    ->sortByDesc(fn (array $athlete) => [$athlete['rating_points'], $athlete['wins_count'], $athlete['full_name']])
                    ->take(15)
                    ->values();

                if ($items->isEmpty()) {
                    continue;
                }

                $renderItems = $this->mapRenderableItems($items);

                $groups->push([
                    'key' => 'age-'.$ageBandKey.'-'.$gender,
                    'name' => $ageLabel,
                    'subtitle' => $genderLabel,
                    'sort' => sprintf('2%02d-%s', $ageSort, $gender),
                    'items' => $renderItems->all(),
                    'leader' => $renderItems->first(),
                ]);
            }
        }

        return $groups->values();
    }

    private function mapRenderableItems(Collection $items): Collection
    {
        return $items->map(fn (array $athlete) => [
            'student_id' => $athlete['student_id'],
            'full_name' => $athlete['full_name'],
            'avatar' => $athlete['avatar'],
            'coach_label' => $athlete['coach_label'],
            'coach_id' => $athlete['coach_id'] ?? null,
            'coach_name' => $athlete['coach_name'] ?? null,
            'coach_club' => $athlete['coach_club'] ?? null,
            'rating_points' => $athlete['rating_points'],
            'wins_count' => $athlete['wins_count'],
            'summary' => $athlete['summary'],
        ])->values();
    }

    private function buildCoachRanking(Collection $groups): array
    {
        $coachResults = [];

        foreach ($groups as $group) {
            $groupKey = (string) ($group['key'] ?? '');

            if (str_starts_with($groupKey, 'p4p-')) {
                continue;
            }

            if (count($group['items'] ?? []) < 2) {
                continue;
            }

            foreach (($group['items'] ?? []) as $index => $item) {
                $position = $index + 1;
                $coachId = (int) ($item['coach_id'] ?? 0);

                if (! isset(self::COACH_POSITION_POINTS[$position]) || $coachId <= 0) {
                    continue;
                }

                if (! isset($coachResults[$coachId])) {
                    $coachResults[$coachId] = [
                        'coach_id' => $coachId,
                        'coach_name' => $item['coach_name'] ?: $item['coach_label'],
                        'coach_club' => $item['coach_club'] ?? null,
                        'fighters' => [],
                    ];
                }

                $coachResults[$coachId]['fighters'][] = [
                    'student_id' => $item['student_id'],
                    'athlete_name' => $item['full_name'],
                    'group' => $group['name'],
                    'subtitle' => $group['subtitle'],
                    'position' => $position,
                    'points' => self::COACH_POSITION_POINTS[$position],
                ];
            }
        }

        $items = collect($coachResults)
            ->map(function (array $coach) {
                $fighters = collect($coach['fighters'])
                    ->sortBy([
                        ['points', 'desc'],
                        ['position', 'asc'],
                        ['athlete_name', 'asc'],
                    ])
                    ->values();

                return [
                    'coach_id' => $coach['coach_id'],
                    'full_name' => $coach['coach_name'],
                    'coach_label' => $coach['coach_club'] ?: __('exports.no_club'),
                    'rating_points' => (int) $fighters->sum('points'),
                    'fighters_count' => $fighters->count(),
                    'summary' => $this->formatFightersSummary($fighters->count()),
                    'fighters' => $fighters->all(),
                ];
            })
            ->filter(fn (array $coach) => $coach['rating_points'] > 0)
            ->sortByDesc(fn (array $coach) => [$coach['rating_points'], $coach['fighters_count'], $coach['full_name']])
            ->take(15)
            ->values();

        return [
            'key' => 'coach-rating',
            'name' => __('exports.coach_ranking'),
            'subtitle' => __('exports.coach_ranking_subtitle'),
            'items' => $items->all(),
            'leader' => $items->first(),
        ];
    }

    private function weightBandsFor(string $ageBandKey, string $gender): array
    {
        $limits = self::WEIGHT_CATEGORY_LIMITS[$ageBandKey][$gender] ?? [];
        $bands = [];
        $from = 0;

        foreach (array_values($limits) as $index => $limit) {
            $isPlus = is_string($limit) && str_ends_with($limit, '+');
            $numericLimit = (int) rtrim((string) $limit, '+');

            $bands[] = [
                'key' => (string) $limit,
                'label' => $isPlus ? $numericLimit.'+ '.__('exports.kg') : $numericLimit.' '.__('exports.kg'),
                'sort' => ($numericLimit * 10) + ($isPlus ? 1 : 0),
                'from' => $isPlus ? $from : max($from, 0),
                'to' => $isPlus ? null : $numericLimit,
            ];

            $from = $numericLimit + 1;
        }

        return $bands;
    }

    private function resolveWeightBand(?int $weight, ?string $ageBandKey, ?string $gender): ?array
    {
        if (! $ageBandKey || ! $gender) {
            return null;
        }

        $weight = max(0, (int) ($weight ?? 0));

        foreach ($this->weightBandsFor($ageBandKey, $gender) as $band) {
            if ($band['to'] === null && $weight >= $band['from']) {
                return $band;
            }

            if ($weight >= $band['from'] && $weight <= $band['to']) {
                return $band;
            }
        }

        return $this->weightBandsFor($ageBandKey, $gender)[0] ?? null;
    }

    private function formatAgeBandLabel(string $ageBandKey): string
    {
        return $ageBandKey === '18+'
            ? '18+'
            : $ageBandKey.' '.__('exports.years');
    }

    private function resolveGenderAgeLabel(string $gender, string $ageBandKey): string
    {
        return match ($gender) {
            'm' => match ($ageBandKey) {
                '8-9', '10-11' => __('exports.boys'),
                '12-13', '14-15' => __('exports.young_men'),
                '16-17', '18+' => $ageBandKey === '18+' ? __('exports.men') : __('exports.juniors_m'),
                default => __('exports.boys'),
            },
            'f' => match ($ageBandKey) {
                '8-9', '10-11' => __('exports.girls'),
                '12-13', '14-15' => __('exports.young_women'),
                '16-17', '18+' => $ageBandKey === '18+' ? __('exports.women') : __('exports.juniors_f'),
                default => __('exports.girls'),
            },
            default => '',
        };
    }

    private function resolveAge(?string $birthday): ?int
    {
        if (! $birthday) {
            return null;
        }

        try {
            return Carbon::parse($birthday)->age;
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeRankNumber(string $rank): ?int
    {
        $number = (int) filter_var($rank, FILTER_SANITIZE_NUMBER_INT);

        return $number > 0 ? $number : null;
    }

    private function formatCoachLabel(?string $lastName, ?string $firstName, ?string $club): string
    {
        $name = trim(($lastName ?? '').' '.($firstName ?? ''));

        if ($name && $club) {
            return $name.' • '.$club;
        }

        return $name ?: ($club ?: __('exports.no_trainer'));
    }

    private function formatWinsSummary(int $wins): string
    {
        return trans_choice('exports.wins', $wins, ['count' => $wins]);
    }

    private function formatFightersSummary(int $fighters): string
    {
        return trans_choice('exports.fighters', $fighters, ['count' => $fighters]);
    }

    private function accumulateKumiteCategoryScores(array &$scores, Collection $pools): void
    {
        /** @var Pool|null $firstPool */
        $firstPool = $pools->first();

        if (! $firstPool?->tournament?->scale) {
            return;
        }

        $perAthleteTournament = [];

        foreach ($pools as $pool) {
            if ($pool->winner_id && ! $pool->absent_student && ! $pool->absent_opponent) {
                $winner = (int) $pool->winner_id;

                if ($winner > 0) {
                    $entry = &$perAthleteTournament[$winner];
                    $entry['wins_count'] = ($entry['wins_count'] ?? 0) + 1;
                    $entry['wazari_count'] = ($entry['wazari_count'] ?? 0) + $this->resolvePoolWazariForWinner($pool, $winner);
                    $entry['ippon_count'] = ($entry['ippon_count'] ?? 0) + $this->resolvePoolIpponForWinner($pool, $winner);
                }
            }
        }
        unset($entry);

        foreach ($this->resolvePodiumByPools($pools) as $studentId => $place) {
            $entry = &$perAthleteTournament[$studentId];
            $entry['place'] = $place;
        }
        unset($entry);

        foreach ($perAthleteTournament as $studentId => $entry) {
            $student = $this->resolveStudentFromPools($pools, (int) $studentId);

            if (! $student) {
                continue;
            }

            if (! $student->shouldCountCompetitiveResultAt($firstPool->tournament->date)) {
                continue;
            }

            $winsCount = (int) ($entry['wins_count'] ?? 0);
            $wazariCount = (int) ($entry['wazari_count'] ?? 0);
            $ipponCount = (int) ($entry['ippon_count'] ?? 0);
            $place = $entry['place'] ?? null;
            $ageBandKey = $this->resolveAgeBandKey(
                $this->resolveAgeOnDate($student->birthday, $firstPool->tournament->date)
            );
            $scaleSlug = $this->resolveEffectiveScaleSlug($firstPool->tournament->scale);

            $placePoints = 0;

            if ($place && $ageBandKey && $scaleSlug) {
                $placePoints = $this->resolveScaleCoefficient($ageBandKey, $scaleSlug)
                    * (self::PODIUM_MULTIPLIERS[$place] ?? 0);
            }

            $tournamentScore = $placePoints + ($winsCount * 2) + $wazariCount + ($ipponCount * 3);

            if ($tournamentScore <= 0) {
                continue;
            }

            if (! isset($scores[$student->id])) {
                $scores[$student->id] = [
                    'student' => $student,
                    'rating_points' => 0,
                    'wins_count' => 0,
                ];
            }

            $scores[$student->id]['rating_points'] += $tournamentScore;
            $scores[$student->id]['wins_count'] += $winsCount;
        }
    }

    private function resolvePodiumByPools(Collection $pools): array
    {
        $podium = [];

        /** @var Pool|null $final */
        $final = $pools->firstWhere('type', 'final');
        /** @var Pool|null $third */
        $third = $pools->firstWhere('type', '3rd');
        /** @var Pool|null $roundRobin */
        $roundRobin = $pools->firstWhere('type', 'Round Robin');

        if ($final && $final->winner_id) {
            $goldId = (int) $final->winner_id;
            $silverId = $final->student_id == $goldId ? (int) $final->opponent_id : (int) $final->student_id;

            if ($goldId > 0) {
                $podium[$goldId] = 'gold';
            }

            if ($silverId > 0) {
                $podium[$silverId] = 'silver';
            }

            if ($third && $third->winner_id) {
                $bronzeId = (int) $third->winner_id;

                if ($bronzeId > 0) {
                    $podium[$bronzeId] = 'bronze';
                }
            }

            return $podium;
        }

        if ($roundRobin) {
            foreach ([
                'gold' => $roundRobin->winner_id_1rd_robbin,
                'silver' => $roundRobin->winner_id_2rd_robbin,
                'bronze' => $roundRobin->winner_id_3rd_robbin,
            ] as $place => $studentId) {
                $studentId = (int) $studentId;

                if ($studentId > 0) {
                    $podium[$studentId] = $place;
                }
            }
        }

        return $podium;
    }

    private function resolveStudentFromPools(Collection $pools, int $studentId): ?User
    {
        foreach ($pools as $pool) {
            if ((int) ($pool->student?->id ?? 0) === $studentId) {
                return $pool->student;
            }

            if ((int) ($pool->opponent?->id ?? 0) === $studentId) {
                return $pool->opponent;
            }
        }

        return null;
    }

    private function resolvePoolWazariForWinner(Pool $pool, int $winnerId): int
    {
        if ((int) $pool->student_id === $winnerId) {
            return max(0, min(2, (int) ($pool->student_wazari_count ?? 0)));
        }

        if ((int) $pool->opponent_id === $winnerId) {
            return max(0, min(2, (int) ($pool->opponent_wazari_count ?? 0)));
        }

        return 0;
    }

    private function resolvePoolIpponForWinner(Pool $pool, int $winnerId): int
    {
        if ((int) $pool->student_id === $winnerId) {
            return (bool) ($pool->student_ippon ?? false) ? 1 : 0;
        }

        if ((int) $pool->opponent_id === $winnerId) {
            return (bool) ($pool->opponent_ippon ?? false) ? 1 : 0;
        }

        return 0;
    }

    private function resolveAgeOnDate(?string $birthday, mixed $date): ?int
    {
        if (! $birthday || ! $date) {
            return null;
        }

        try {
            return (int) floor(Carbon::parse($birthday)->diffInYears(Carbon::parse($date)));
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveAgeBandKey(?int $age): ?string
    {
        if ($age === null) {
            return null;
        }

        foreach (self::AGE_BANDS as $key => $band) {
            if ($age >= $band['from'] && $age <= $band['to']) {
                return $key;
            }
        }

        return null;
    }

    private function resolveScaleCoefficient(string $ageBandKey, string $scaleSlug): int
    {
        return (int) (self::SCALE_COEFFICIENTS[$ageBandKey][$scaleSlug] ?? 0);
    }

    private function resolveEffectiveScaleSlug(?Scale $scale): ?string
    {
        if (! $scale) {
            return null;
        }

        if (filled($scale->slug)) {
            return (string) $scale->slug;
        }

        $name = mb_strtolower(trim((string) $scale->name));

        return match (true) {
            str_contains($name, 'город') => Scale::CITY,
            str_contains($name, 'област') || str_contains($name, 'краев') || str_contains($name, 'края') => Scale::REGION,
            str_contains($name, 'федераль') || str_contains($name, 'округ') => Scale::FEDERAL_DISTRICT,
            str_contains($name, 'всеросс') => Scale::ALL_RUSSIAN,
            str_contains($name, 'международ') => Scale::INTERNATIONAL,
            str_contains($name, 'первенство росс') || str_contains($name, 'россии') => Scale::RUSSIAN_CHAMPIONSHIP,
            default => null,
        };
    }

    private function normalizeYear(mixed $year): string
    {
        $year = (string) ($year ?? '');

        if (preg_match('/^\d{4}$/', $year)) {
            return $year;
        }

        return (string) now()->year;
    }
}
