<?php

namespace App\Http\Controllers\Panel;

use App\Exports\ChampionshipParticipantsExport;
use App\Http\Controllers\Panel\Tournaments\BaseTournamentController;
use App\Models\Championship;
use App\Models\ExternalForm;
use App\Models\Tournament;
use App\Services\Exports\ExportLabels;
use App\Services\Exports\PanelTasks;
use App\Services\Tournaments\TournamentAssetUpdate;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class TournamentController extends BaseTournamentController
{
    public function index(Request $request): JsonResponse
    {
        $perPage = max(6, min(36, (int) $request->integer('per_page', 6)));
        $query = $this->visibleChampionships($request->user())
            ->withCount([
                'tournaments',
                'tournaments as active_tournaments_count' => fn (Builder $query) => $query->whereDate('date_finish', '>=', now()->toDateString()),
                'tournaments as completed_tournaments_count' => fn (Builder $query) => $query->whereDate('date_finish', '<', now()->toDateString()),
            ])
            ->when($request->filled('region_id'), fn (Builder $query) => $query
                ->whereHas('tournaments', fn (Builder $tournaments) => $tournaments->where('region_id', $request->integer('region_id'))))
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $search = trim($request->string('search')->toString());
                $query->where('name', 'like', "%{$search}%");
            })
            ->when($request->string('scope')->toString() === 'mine', fn (Builder $query) => $query->where('organization_id', $this->organizationId($request->user())))
            ->when($request->string('status')->toString() === 'active', fn (Builder $query) => $query
                ->where(function (Builder $query): void {
                    $query->whereDoesntHave('tournaments')
                        ->orWhereHas('tournaments', fn (Builder $tournaments) => $tournaments->whereDate('date_finish', '>=', now()->toDateString()));
                }))
            ->when($request->string('status')->toString() === 'completed', fn (Builder $query) => $query
                ->whereHas('tournaments')
                ->whereDoesntHave('tournaments', fn (Builder $tournaments) => $tournaments->whereDate('date_finish', '>=', now()->toDateString())))
            ->orderByDesc('created_at')
            ->orderBy('name');

        $paginator = $query->paginate($perPage);

        return response()->json([
            'items' => [
                'data' => $paginator->getCollection()->map(fn (Championship $championship) => $this->formatChampionship($request->user(), $championship))->values(),
                'meta' => $this->meta($paginator),
            ],
            'stats' => $this->championshipStats($request->user()),
            'filters' => [
                'regions' => $this->regions($request->user()),
            ],
        ]);
    }

    public function show(Request $request, Championship $championship): JsonResponse
    {
        $this->authorizeChampionship($request->user(), $championship);
        $perPage = max(6, min(36, (int) $request->integer('per_page', 6)));

        $query = $this->visibleTournaments($request->user())
            ->where('championship_id', $championship->id)
            ->with(['region:id,name', 'scale:id,name'])
            ->with(['treners:id,club'])
            ->withCount(['students', 'treners'])
            ->when($request->filled('region_id'), fn (Builder $query) => $query->where('region_id', $request->integer('region_id')))
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $search = trim($request->string('search')->toString());
                $query->where('name', 'like', "%{$search}%");
            })
            ->when($request->string('status')->toString() === 'active', fn (Builder $query) => $query->whereDate('date_finish', '>=', now()->toDateString()))
            ->when($request->string('status')->toString() === 'completed', fn (Builder $query) => $query->whereDate('date_finish', '<', now()->toDateString()))
            ->orderByDesc('date')
            ->orderBy('name');

        $paginator = $query->paginate($perPage);

        return response()->json([
            'championship' => array_merge(
                $this->formatChampionship($request->user(), $championship->loadCount(['tournaments'])),
                ['export_coaches' => $this->championshipCoachOptions($championship)]
            ),
            'items' => [
                'data' => $paginator->getCollection()->map(fn (Tournament $tournament) => $this->formatTournament($request->user(), $tournament))->values(),
                'meta' => $this->meta($paginator),
            ],
            'forms' => $championship->externalForms()
                ->select(['id', 'championship_id', 'organization_name', 'token', 'status', 'data', 'created_at'])
                ->latest()
                ->get()
                ->map(fn (ExternalForm $form) => $this->formatExternalForm($form))
                ->values(),
            'stats' => $this->tournamentStats($request->user(), $championship),
            'filters' => [
                'regions' => $this->regions($request->user(), $championship),
            ],
            'create_options' => [
                'regions' => $this->allRegions(),
                'scales' => $this->allScales(),
            ],
        ]);
    }

    public function exportParticipants(Request $request, Championship $championship)
    {
        $this->authorizeChampionshipOwner($request->user(), $championship);

        $request->validate(['trainer_ids' => ['sometimes', 'array'], 'trainer_ids.*' => ['integer', 'min:1'], 'trainer_ids_csv' => ['nullable', 'regex:/^\\d+(,\\d+)*$/']]);
        $trainerIds = collect($request->input('trainer_ids', []))
            ->merge(explode(',', (string) $request->query('trainer_ids_csv', '')))
            ->filter(fn ($id) => filled($id))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        $allowedTrainers = collect($this->championshipCoachOptions($championship))->pluck('id');
        abort_if($trainerIds->diff($allowedTrainers)->isNotEmpty(), 422, __('validation.exists', ['attribute' => __('exports.coach')]));

        if (PanelTasks::shouldDefer($request)) {
            return app(PanelTasks::class)->defer($request, 'championship', ['championship' => $championship->id, 'filters' => ['trainer_ids' => $trainerIds->all()]]);
        }

        $registrations = DB::table('student_tournaments as st')
            ->join('tournaments as t', 't.id', '=', 'st.tournament_id')
            ->whereNull('t.deleted_at')
            ->join('users as u', 'u.id', '=', 'st.student_id')->whereNull('u.deleted_at')
            ->leftJoin('users as c', 'c.id', '=', 'u.coach_id')->whereNull('c.deleted_at')
            ->where('t.championship_id', $championship->id)
            ->when($trainerIds->isNotEmpty(), fn ($query) => $query->whereIn('u.coach_id', $trainerIds))
            ->select([
                'u.id as student_id',
                'u.first_name',
                'u.last_name',
                'u.birthday',
                'u.rang',
                'u.coach_id',
                'c.first_name as coach_first_name',
                'c.last_name as coach_last_name',
                't.id as tournament_id',
                't.tournament_type',
                't.tournament_type_kata',
            ])
            ->orderBy('c.last_name')
            ->orderBy('c.first_name')
            ->orderBy('u.last_name')
            ->orderBy('u.first_name')
            ->get();

        $participants = $registrations
            ->groupBy('student_id')
            ->map(function ($rows): array {
                $first = $rows->first();
                $tournamentIds = $rows->pluck('tournament_id')->unique();
                $disciplines = $rows
                    ->map(fn ($row): ?string => $this->disciplineLabel((int) $row->tournament_type, $row->tournament_type_kata ? (int) $row->tournament_type_kata : null))
                    ->filter()
                    ->unique()
                    ->values()
                    ->implode(', ');

                return [
                    'student_id' => (int) $first->student_id,
                    'first_name' => $first->first_name,
                    'last_name' => $first->last_name,
                    'age' => $this->exportAge($first->birthday),
                    'disciplines_count' => $tournamentIds->count(),
                    'disciplines' => $disciplines,
                    'rang' => ExportLabels::rank($first->rang),
                    'coach_id' => $first->coach_id ? (int) $first->coach_id : 0,
                    'coach_name' => trim(($first->coach_first_name ?? '').' '.($first->coach_last_name ?? '')),
                ];
            })
            ->sortBy([
                ['coach_name', 'asc'],
                ['last_name', 'asc'],
                ['first_name', 'asc'],
            ])
            ->values();

        $this->writeTournamentActivity(
            $request->user(),
            'Выгружены участники чемпионата',
            'championship.participants.exported',
            Championship::class,
            $championship->id,
            [
                'championship' => $this->championshipSnapshot($championship),
                'trainer_ids' => $trainerIds->all(),
                'participants_count' => $participants->count(),
            ]
        );

        return Excel::download(
            new ChampionshipParticipantsExport($participants),
            "championship-{$championship->id}-participants.xlsx"
        );
    }

    private function championshipCoachOptions(Championship $championship): array
    {
        return DB::table('student_tournaments as st')
            ->join('tournaments as t', 't.id', '=', 'st.tournament_id')
            ->whereNull('t.deleted_at')
            ->join('users as s', 's.id', '=', 'st.student_id')->whereNull('s.deleted_at')
            ->join('users as u', 'u.id', '=', 's.coach_id')->whereNull('u.deleted_at')
            ->where('t.championship_id', $championship->id)
            ->select(['u.id', 'u.first_name', 'u.last_name', 'u.club'])
            ->distinct()
            ->orderBy('u.last_name')
            ->orderBy('u.first_name')
            ->get()
            ->map(fn ($coach) => [
                'id' => (int) $coach->id,
                'name' => trim(($coach->first_name ?? '').' '.($coach->last_name ?? '')),
                'club' => $coach->club,
            ])
            ->all();
    }

    private function disciplineLabel(int $type, ?int $kataType): ?string
    {
        if ($type === Tournament::KUMITE) {
            return __('exports.kumite');
        }

        if ($type !== Tournament::KATA) {
            return null;
        }

        return $kataType === Tournament::FLAG_SYSTEM ? __('exports.kata_flags') : __('exports.kata_points');
    }

    private function exportAge(?string $birthday): ?int
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

    public function store(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId($request->user());
        abort_unless($organizationId && $this->canManageChampionships($request->user()), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'banner' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ]);

        $championship = Championship::query()->create([
            'name' => $data['name'],
            'banner' => $request->file('banner')->store('championships', 'public'),
            'organization_id' => $organizationId,
        ]);

        $this->writeTournamentActivity(
            $request->user(),
            'Создан чемпионат',
            'championship.created',
            Championship::class,
            $championship->id,
            [
                'new' => $this->championshipSnapshot($championship),
            ]
        );

        $championship->loadCount([
            'tournaments',
            'tournaments as active_tournaments_count' => fn (Builder $query) => $query->whereDate('date_finish', '>=', now()->toDateString()),
            'tournaments as completed_tournaments_count' => fn (Builder $query) => $query->whereDate('date_finish', '<', now()->toDateString()),
        ]);

        return response()->json(['item' => $this->formatChampionship($request->user(), $championship)], 201);
    }

    public function update(Request $request, Championship $championship): JsonResponse
    {
        $this->authorizeChampionshipOwner($request->user(), $championship);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'banner' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192']]);
        app(TournamentAssetUpdate::class)->save($championship, ['name' => $data['name']], $request, ['banner' => 'championships'],
            fn (Championship $item, array $before) => $this->writeTournamentActivity($request->user(), 'Чемпионат изменён', 'championship.updated', Championship::class, $item->id,
                ['old' => $before, 'new' => $this->championshipSnapshot($item)]));

        return response()->json(['item' => $this->formatChampionship($request->user(), $championship->refresh())]);
    }

    public function destroy(Request $request, Championship $championship): JsonResponse
    {
        abort_unless($this->canManageChampionship($request->user(), $championship), 403);

        $before = $this->championshipSnapshot($championship);

        DB::transaction(function () use ($request, $championship, $before): void {
            $championship->delete();

            $this->writeTournamentActivity(
                $request->user(),
                'Чемпионат удален',
                'championship.deleted',
                Championship::class,
                $championship->id,
                [
                    'old' => $before,
                    'new' => ['deleted_at' => $championship->deleted_at?->toDateTimeString()],
                ]
            );
        });

        return response()->json(['deleted' => true]);
    }
}
