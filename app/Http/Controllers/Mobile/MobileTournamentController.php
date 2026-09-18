<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Championship;
use App\Models\Tournament;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class MobileTournamentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $coach = $request->user();
        $perPage = max(5, min(20, (int) $request->integer('per_page', 10)));

        $query = Championship::query()
            ->select(['id', 'name', 'banner', 'organization_id', 'created_at'])
            ->withCount($this->visibleCounts($coach))
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $search = trim($request->string('search')->toString());
                $query->where('name', 'like', "%{$search}%");
            });

        $this->applyChampionshipOwnership($query, $coach, $request->string('ownership', 'my')->toString());
        $this->applyChampionshipStatus($query, $request->string('status', 'active')->toString(), $coach);

        $championships = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'data' => $championships->getCollection()->map(fn (Championship $championship): array => $this->formatChampionship($championship))->values(),
            'meta' => $this->meta($championships),
        ]);
    }

    public function show(Request $request, Championship $championship): JsonResponse
    {
        $coach = $request->user();
        abort_unless($this->championshipVisibleToCoach($championship, $coach), 403);

        $perPage = max(5, min(20, (int) $request->integer('per_page', 10)));
        $tournamentsQuery = Tournament::query()
            ->select([
                'id',
                'name',
                'championship_id',
                'region_id',
                'scale_id',
                'address',
                'date_commission',
                'date',
                'date_finish',
                'organization_id',
                'price',
                'tournament_type',
                'tournament_type_kata',
                'created_at',
            ])
            ->with(['region:id,name', 'scale:id,name', 'treners:id,first_name,last_name,club'])
            ->withCount(['students' => fn (Builder $q) => $q->whereHas('coach')->select(DB::raw('COUNT(DISTINCT users.id)')), 'treners' => fn (Builder $q) => $q->select(DB::raw('COUNT(DISTINCT users.id)'))])
            ->where('championship_id', $championship->id)
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $search = trim($request->string('search')->toString());
                $query->where('name', 'like', "%{$search}%");
            });

        $this->applyTournamentOwnership($tournamentsQuery, $coach, $request->string('ownership', 'my')->toString());
        $this->applyTournamentStatus($tournamentsQuery, $request->string('status', 'active')->toString());

        $tournaments = $tournamentsQuery->orderByDesc('date')->orderByDesc('id')->paginate($perPage);

        $championship->loadCount($this->visibleCounts($coach));

        return response()->json([
            'championship' => $this->formatChampionship($championship),
            'data' => $tournaments->getCollection()->map(fn (Tournament $tournament): array => $this->formatTournament($tournament))->values(),
            'meta' => $this->meta($tournaments),
        ]);
    }

    private function applyChampionshipOwnership(Builder $query, User $coach, string $ownership): void
    {
        if ($coach->projectRoleNames() === ['Student']) {
            $query->whereHas('tournaments', fn (Builder $q) => $this->coachTournament($q, $coach));

            return;
        }
        if ($ownership === 'all') {
            return;
        }

        $method = $ownership === 'not_mine' ? 'whereDoesntHave' : 'whereHas';
        $query->{$method}('tournaments', fn (Builder $tournaments) => $this->coachTournament($tournaments, $coach));
    }

    private function applyTournamentOwnership(Builder $query, User $coach, string $ownership): void
    {
        if ($coach->projectRoleNames() === ['Student']) {
            $this->coachTournament($query, $coach);

            return;
        }
        if ($ownership === 'all') {
            return;
        }

        if ($ownership === 'not_mine') {
            $query->whereNot(fn (Builder $query) => $this->coachTournament($query, $coach));

            return;
        }

        $this->coachTournament($query, $coach);
    }

    private function visibleForMember(Builder $query, User $member): Builder
    {
        return $member->projectRoleNames() === ['Student'] ? $this->coachTournament($query, $member) : $query;
    }

    private function visibleCounts(User $member): array
    {
        return [
            'tournaments' => fn (Builder $q) => $this->visibleForMember($q, $member),
            'tournaments as active_tournaments_count' => fn (Builder $q) => $this->activeTournament($this->visibleForMember($q, $member)),
            'tournaments as completed_tournaments_count' => fn (Builder $q) => $this->completedTournament($this->visibleForMember($q, $member)),
        ];
    }

    private function applyChampionshipStatus(Builder $query, string $status, User $member): void
    {
        $visible = fn (Builder $q) => $this->visibleForMember($q, $member);
        $active = fn (Builder $q) => $this->activeTournament($visible($q));
        if ($status === 'completed') {
            $query->whereHas('tournaments', $visible)->whereDoesntHave('tournaments', $active);

            return;
        }

        if ($status !== 'all') {
            $query->where(fn (Builder $query) => $query
                ->whereDoesntHave('tournaments', $visible)
                ->orWhereHas('tournaments', $active));
        }
    }

    private function applyTournamentStatus(Builder $query, string $status): void
    {
        if ($status === 'completed') {
            $this->completedTournament($query);

            return;
        }

        if ($status !== 'all') {
            $this->activeTournament($query);
        }
    }

    private function coachTournament(Builder $query, User $coach): Builder
    {
        if ($coach->projectRoleNames() === ['Student']) {
            $coach = User::query()->role('Coach')->find($coach->coach_id);
            if (! $coach) {
                return $query->whereRaw('1 = 0');
            }
        }

        return $query->whereExists(function ($subQuery) use ($coach): void {
            $subQuery->selectRaw('1')
                ->from('tournament_treners')
                ->whereColumn('tournament_treners.tournament_id', 'tournaments.id')
                ->where('tournament_treners.trener_id', $coach->id);
        });
    }

    private function activeTournament(Builder $query): Builder
    {
        return $query->whereDate('date_finish', '>=', now()->toDateString());
    }

    private function completedTournament(Builder $query): Builder
    {
        return $query->whereDate('date_finish', '<', now()->toDateString());
    }

    private function championshipVisibleToCoach(Championship $championship, User $coach): bool
    {
        if ($coach->projectRoleNames() === ['Student']) {
            return $this->coachTournament(Tournament::where('championship_id', $championship->id), $coach)->exists();
        }

        return $coach->hasProjectRole('Coach') && $championship->exists;
    }

    private function formatChampionship(Championship $championship): array
    {
        $total = (int) ($championship->tournaments_count ?? 0);
        $active = (int) ($championship->active_tournaments_count ?? 0);

        return [
            'id' => $championship->id,
            'name' => $championship->name,
            'cover' => $this->assetUrl($championship->banner),
            'status' => $total === 0 || $active > 0 ? 'active' : 'completed',
            'tournaments_count' => $total,
            'active_tournaments_count' => $active,
            'completed_tournaments_count' => (int) ($championship->completed_tournaments_count ?? 0),
        ];
    }

    private function formatTournament(Tournament $tournament): array
    {
        $finish = $tournament->date_finish ? Carbon::parse($tournament->date_finish) : null;
        $clubs = $tournament->treners->pluck('club')->filter()->unique()->values();

        return [
            'id' => $tournament->id,
            'championship_id' => $tournament->championship_id,
            'name' => $tournament->name,
            'can_open' => $tournament->treners->contains('id', request()->user()->projectRoleNames() === ['Student'] ? request()->user()->coach_id : request()->user()->id),
            'type' => (int) $tournament->tournament_type === Tournament::KATA ? 'kata' : 'kumite',
            'type_label' => $this->tournamentTypeLabel($tournament),
            'status' => $finish && $finish->lt(now()->startOfDay()) ? 'completed' : 'active',
            'region' => $tournament->region?->name,
            'scale' => $tournament->scale?->name,
            'address' => $tournament->address,
            'date_commission_label' => $tournament->date_commission ? Carbon::parse($tournament->date_commission)->format('d.m.Y H:i') : '',
            'date_label' => $tournament->date ? Carbon::parse($tournament->date)->format('d.m.Y') : '',
            'date_finish_label' => $finish?->format('d.m.Y') ?? '',
            'price_label' => $tournament->price !== null ? number_format((float) $tournament->price, 0, ',', ' ').' ₽' : '',
            'students_count' => (int) ($tournament->students_count ?? 0),
            'trainers_count' => (int) ($tournament->treners_count ?? 0),
            'clubs' => $clubs->take(3)->values(),
            'more_clubs_count' => max(0, $clubs->count() - 3),
        ];
    }

    private function tournamentTypeLabel(Tournament $tournament): string
    {
        if ((int) $tournament->tournament_type === Tournament::KUMITE) {
            return __('mobile.kumite');
        }

        return (int) $tournament->tournament_type_kata === Tournament::POINT_SYSTEM ? __('mobile.kata_points') : __('mobile.kata_flags');
    }

    private function assetUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, '/')) {
            return $path;
        }

        return asset('storage/'.ltrim($path, '/'));
    }

    private function meta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem() ?? 0,
            'to' => $paginator->lastItem() ?? 0,
        ];
    }
}
