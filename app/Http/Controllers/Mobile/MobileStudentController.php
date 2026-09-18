<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Tournament;
use App\Models\User;
use App\Services\ProtectedMedia;
use App\Services\RatingService;
use App\Services\StudentMedalService;
use App\Services\Students\StudentCompetitionHistory;
use App\Services\Students\StudentDocumentStatus;
use App\Services\Students\StudentProfileAccess;
use App\Services\Students\UpdateStudentProfile;
use App\Services\Team\TeamActivity;
use App\Services\Tournaments\PanelTournamentVisibility;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MobileStudentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $coach = $request->user();
        $perPage = max(5, min(30, (int) $request->integer('per_page', 10)));

        $query = $this->baseStudentQuery($coach)

            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                foreach (preg_split('/\s+/u', trim($request->string('search')->toString()), -1, PREG_SPLIT_NO_EMPTY) as $word) {
                    $query->where(fn (Builder $q) => $q->where('first_name', 'like', "%{$word}%")->orWhere('last_name', 'like', "%{$word}%"));
                }
            })
            ->when($request->filled('gender'), fn (Builder $query) => $this->filterByGender($query, $request->string('gender')->toString()))
            ->when($request->filled('rang'), fn (Builder $query) => $query->where('rang', 'like', '%'.$request->string('rang')->toString().'%'))
            ->when($request->filled('age_group'), fn (Builder $query) => $this->filterByAgeGroup($query, $request->string('age_group')->toString()))
            ->when($request->filled('tournament_status'), fn (Builder $query) => $this->filterByTournamentStatus($query, $request->string('tournament_status')->toString()))
            ->orderBy('created_at')
            ->orderBy('id');

        $students = $query->paginate($perPage);
        $activeTournamentCounts = $this->activeTournamentCounts($students->getCollection()->pluck('id')->all());

        return response()->json([
            'data' => $students
                ->getCollection()
                ->map(fn (User $student): array => $this->formatListStudent($student, (int) ($activeTournamentCounts[$student->id] ?? 0)))
                ->values(),
            'meta' => [
                'current_page' => $students->currentPage(),
                'last_page' => $students->lastPage(),
                'per_page' => $students->perPage(),
                'total' => $students->total(),
            ],
        ]);
    }

    public function show(Request $request, User $student, RatingService $rating): JsonResponse
    {
        abort_unless($student->hasProjectRole('Student'), 404);

        $student->load('coach:id,first_name,last_name,club,organization_id');
        abort_unless($this->canViewStudent($request->user(), $student), 403);

        $medals = app(StudentMedalService::class)->forStudent($student);
        $history = app(StudentCompetitionHistory::class);
        $pages = [];
        foreach (['tournaments', 'wins', 'losses'] as $kind) {
            $pages[$kind] = $history->page($student, $request->user(), $kind);
        }

        return response()->json([
            'student' => $this->formatProfileStudent($student) + ['capabilities' => app(StudentProfileAccess::class)->capabilities($request->user(), $student)],
            'rating' => [
                'kumite' => $this->formatRatingCard($rating->resolveUserTopPosition($student, 'kumite'), $medals['kumite']),
                'kata' => $this->formatRatingCard($rating->resolveUserTopPosition($student, 'kata'), $medals['kata']),
                'record' => $history->record($student),
            ],
            'documents' => $this->documents($student),
            'tournaments' => $pages['tournaments']['data'],
            'history_meta' => array_map(fn ($page) => $page['meta'], $pages),
            'fight_records' => [
                'wins' => $pages['wins']['data'],
                'losses' => $pages['losses']['data'],
            ],
        ]);
    }

    public function update(Request $request, User $student, RatingService $rating): JsonResponse
    {
        $updated = app(UpdateStudentProfile::class)->update($request->user(), $student, $request);

        return $this->show($request, $updated, $rating);
    }

    public function detach(Request $request, User $student): JsonResponse
    {
        $request->validate(['confirmed' => ['required', 'accepted']]);
        DB::transaction(function () use ($request, $student) {
            $student = User::query()->lockForUpdate()->findOrFail($student->id);
            abort_unless(app(StudentProfileAccess::class)->owns($request->user(), $student), 403);
            $old = $student->coach_id;
            $student->forceFill(['coach_id' => null])->save();
            TeamActivity::record($request->user(), 'mobile.student.detached', User::class, $student->id,
                ['old' => ['coach_id' => $old], 'new' => ['coach_id' => null]]);
        });

        return response()->json(['detached' => true]);
    }

    public function history(Request $request, User $student): JsonResponse
    {
        abort_unless($this->canViewStudent($request->user(), $student), 403);
        $data = $request->validate(['kind' => ['required', 'in:tournaments,wins,losses'], 'page' => ['nullable', 'integer', 'min:1']]);

        return response()->json(app(StudentCompetitionHistory::class)->page($student, $request->user(), $data['kind'], $data['page'] ?? 1));
    }

    public function publicSummary(Request $request, User $student): JsonResponse
    {
        $data = $request->validate(['tournament_id' => ['required', 'integer'], 'championship_id' => ['required', 'integer']]);
        abort_unless($student->hasProjectRole('Student'), 404);
        $id = (int) $data['tournament_id'];
        abort_unless(in_array($id, app(PanelTournamentVisibility::class)->linkableIds($request->user(), [$id]), true), 403);
        abort_unless(Tournament::query()->whereKey($id)->where('championship_id', $data['championship_id'])
            ->whereHas('students', fn ($q) => $q->where('users.id', $student->id))->exists(), 404);
        $student->load('coach:id,first_name,last_name,club');
        $public = array_intersect_key($this->formatProfileStudent($student), array_flip([
            'id', 'full_name', 'avatar_url', 'club', 'coach_name', 'age', 'age_label', 'gender', 'gender_label', 'weight', 'rang', 'belt',
        ]));

        return response()->json(['student' => $public + ['capabilities' => ['edit' => false, 'documents' => false, 'detach' => false]], 'public_only' => true]);
    }

    private function baseStudentQuery(User $coach): Builder
    {
        $columns = [
            'id',
            'first_name',
            'last_name',
            'avatar',
            'birthday',
            'gender',
            'club',
            'coach_id',
            'weight',
            'rang',
            'created_at',
            'insurance_close_date',
            'is_iko_card_included_check',
            'is_certificate_included_check',
            'is_success_insurance',
            'is_success_iko_card',
            'is_success_certificate',
            'is_success_passport',
            'is_success_brand',
        ];

        if (Schema::hasColumn('users', 'height')) {
            $columns[] = 'height';
        }

        return User::query()
            ->role('Student')
            ->where('coach_id', $coach->id)
            ->with('coach:id,club')
            ->select($columns);
    }

    private function canViewStudent(User $coach, User $student): bool
    {
        $access = app(StudentProfileAccess::class);

        return $access->owns($coach, $student) || $access->isSelf($coach, $student);
    }

    private function filterByAgeGroup(Builder $query, string $group): void
    {
        $range = match ($group) {
            '7_9' => [7, 9],
            '10_11' => [10, 11],
            '12_13' => [12, 13],
            default => null,
        };

        if ($group === '14_plus') {
            $query->whereDate('birthday', '<=', Carbon::today()->subYears(14)->toDateString());

            return;
        }

        if ($range === null) {
            return;
        }

        [$min, $max] = $range;
        $from = Carbon::today()->subYears($max + 1)->addDay()->toDateString();
        $to = Carbon::today()->subYears($min)->toDateString();

        $query->whereBetween('birthday', [$from, $to]);
    }

    private function filterByTournamentStatus(Builder $query, string $status): void
    {
        if (! in_array($status, ['active', 'without_active'], true)) {
            return;
        }

        $method = $status === 'active' ? 'whereExists' : 'whereNotExists';

        $query->{$method}(function ($query): void {
            $query
                ->selectRaw('1')
                ->from('student_tournaments')
                ->join('tournaments', 'tournaments.id', '=', 'student_tournaments.tournament_id')
                ->join('championships', 'championships.id', '=', 'tournaments.championship_id')
                ->whereColumn('student_tournaments.student_id', 'users.id')
                ->whereNull('tournaments.deleted_at')
                ->whereNull('championships.deleted_at')
                ->whereDate('tournaments.date_finish', '>=', now()->toDateString());
        });
    }

    private function filterByGender(Builder $query, string $gender): void
    {
        $values = match ($gender) {
            'male' => ['male', 'm', 'Мужской', 'М'],
            'female' => ['female', 'f', 'Женский', 'Ж'],
            default => [$gender],
        };

        $query->whereIn('gender', $values);
    }

    private function activeTournamentCounts(array $studentIds): array
    {
        if ($studentIds === []) {
            return [];
        }

        return Tournament::query()
            ->join('student_tournaments', 'student_tournaments.tournament_id', '=', 'tournaments.id')
            ->whereIn('student_tournaments.student_id', $studentIds)
            ->whereNull('tournaments.deleted_at')
            ->whereDate('tournaments.date_finish', '>=', now()->toDateString())
            ->selectRaw('student_tournaments.student_id, COUNT(DISTINCT tournaments.id) as total')
            ->groupBy('student_tournaments.student_id')
            ->pluck('total', 'student_tournaments.student_id')
            ->all();
    }

    private function formatListStudent(User $student, int $activeTournaments): array
    {
        $belt = $this->beltFor((string) $student->rang);
        $status = app(StudentDocumentStatus::class)->evaluate($student);

        return [
            'id' => $student->id,
            'full_name' => trim($student->last_name.' '.$student->first_name),
            'avatar_url' => $student->avatar ? asset('storage/'.$student->avatar) : null,
            'age' => $this->ageNumber($student->birthday),
            'age_label' => $student->age,
            'gender' => $student->gender,
            'gender_label' => $this->genderLabel($student->gender),
            'club' => $student->coach?->club,
            'rang' => $student->rang,
            'belt' => $belt,
            'active_tournaments' => $activeTournaments,
            'documents_ok' => $status['ok'],
            'document_issues' => $status['issues'],
            'weight' => $student->weight,
            'insurance_close_date' => $this->dateLabel($student->insurance_close_date),
        ];
    }

    private function formatProfileStudent(User $student): array
    {
        $belt = $this->beltFor((string) $student->rang);

        return [
            'id' => $student->id,
            'first_name' => $student->first_name,
            'last_name' => $student->last_name,
            'patronymic' => $student->patronymic,
            'full_name' => trim($student->last_name.' '.$student->first_name),
            'avatar_url' => $student->avatar ? asset('storage/'.$student->avatar) : null,
            'club' => $student->coach?->club,
            'coach_name' => $student->coach ? trim($student->coach->last_name.' '.$student->coach->first_name) : null,
            'age' => $this->ageNumber($student->birthday),
            'age_label' => $student->age,
            'birthday' => $this->dateLabel($student->birthday),
            'gender' => $student->gender,
            'gender_label' => $this->genderLabel($student->gender),
            'weight' => $student->weight,
            'height' => Schema::hasColumn('users', 'height') ? $student->height : null,
            'rang' => $student->rang,
            'belt' => $belt,
            'email' => $student->email,
            'city_training' => $student->city_training,
            'number_brand' => $student->number_brand,
            'number_iko' => $student->number_iko,
            'number_certificate' => $student->number_certificate,
            'last_examination_date' => $this->dateLabel($student->last_examination_date),
            'last_examination_city' => $student->last_examination_city,
            'last_receiving' => $student->last_receiving,
        ];
    }

    private function formatRatingCard(?array $rating, array $medals): array
    {
        return [
            'label' => $rating['label'] ?? '—',
            'points' => (int) ($rating['rating_points'] ?? 0),
            'year' => (string) now()->year,
            'group' => $rating['group'] ?? null,
            'subtitle' => $rating['subtitle'] ?? null,
            'medals' => $medals,
        ];
    }

    private function documents(User $student): array
    {
        $status = app(StudentDocumentStatus::class)->evaluate($student);
        $media = app(ProtectedMedia::class);

        return [
            'ok' => $status['ok'], 'issues' => $status['issues'],
            'items' => array_values(array_map(fn ($item) => $item + [
                'file_url' => $media->documentUrl($student, $item['field'], true),
                'file' => $media->documentUrl($student, $item['field'], true),
            ], $status['items'])),
            'fields' => array_values(array_filter([
                $this->documentField('brandNumber', $student->number_brand), $this->documentField('ikoNumber', $student->number_iko),
                $this->documentField('certificateNumber', $student->number_certificate),
                $this->documentField('lastExamDate', $this->dateLabel($student->last_examination_date)),
                $this->documentField('lastExamCity', $student->last_examination_city), $this->documentField('lastReceiving', $student->last_receiving),
                $this->documentField('insuranceCloseDate', $this->dateLabel($student->insurance_close_date)),
            ])),
        ];
    }

    private function documentField(string $labelKey, mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        return ['label' => $labelKey, 'value' => $value];
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
            'male', 'm', 'Мужской', 'М' => __('mobile.boy'),
            'female', 'f', 'Женский', 'Ж' => __('mobile.girl'),
            default => $gender,
        };
    }
}
