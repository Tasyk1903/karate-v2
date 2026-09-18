<?php

namespace App\Http\Controllers\Panel\Tournaments;

use App\Http\Controllers\Controller;
use App\Models\Championship;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Exports\PanelTasks;
use App\Services\Tournaments\TournamentDownloadService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TournamentDownloadController extends Controller
{
    private const TYPES = [
        'brackets',
        'kata-tables',
        'kumite-protocols',
        'kata-protocols',
        'results',
        'certificate',
        'lists-excel',
        'lists-pdf',
    ];

    public function __invoke(
        Request $request,
        Championship $championship,
        Tournament $tournament,
        string $type,
        TournamentDownloadService $downloads,
    ) {
        abort_unless(in_array($type, self::TYPES, true), 404);
        $this->authorizeDownload($request->user(), $championship, $tournament);
        if ($request->user()->hasProjectRole('Student')) {
            abort_unless(in_array($type, ['lists-excel', 'lists-pdf'], true), 403);
        }
        if (in_array($type, ['brackets', 'kata-tables'], true)) {
            abort_unless($request->user()->hasAnyProjectRole(['Admin', 'Organization', 'Secretary']), 403);
        }

        if (PanelTasks::shouldDefer($request)) {
            return app(PanelTasks::class)->defer($request, 'tournament', ['championship' => $championship->id, 'tournament' => $tournament->id, 'type' => $type]);
        }

        $response = $downloads->download($tournament, $type);
        $this->writeActivity($request->user(), $tournament, $type);

        return $response;
    }

    private function authorizeDownload(User $user, Championship $championship, Tournament $tournament): void
    {
        abort_unless((int) $tournament->championship_id === (int) $championship->id, 404);

        abort_unless(
            $this->visibleChampionships($user)->whereKey($championship->id)->exists()
            && $this->visibleTournaments($user)
                ->whereKey($tournament->id)
                ->where('championship_id', $championship->id)
                ->exists(),
            403
        );
    }

    private function visibleChampionships(User $user): Builder
    {
        $query = Championship::query();

        if ($this->userHasRole($user, 'Admin')) {
            return $query;
        }

        if ($this->userHasRole($user, 'Organization')) {
            return $query->where('organization_id', $user->id);
        }

        if ($this->userHasRole($user, 'Secretary')) {
            return $query->where('organization_id', $user->organization_id);
        }

        if ($this->userHasRole($user, 'Coach')) {
            return $query->whereHas('tournaments', fn (Builder $tournaments) => $tournaments
                ->whereExists(function ($subQuery) use ($user): void {
                    $subQuery->selectRaw('1')
                        ->from('tournament_treners')
                        ->whereColumn('tournament_treners.tournament_id', 'tournaments.id')
                        ->where('tournament_treners.trener_id', $user->id);
                }));
        }

        if ($this->userHasRole($user, 'Student')) {
            if (! $user->coach_id) {
                return $query->whereRaw('1 = 0');
            }

            return $query->whereHas('tournaments', fn (Builder $tournaments) => $tournaments
                ->whereExists(function ($subQuery) use ($user): void {
                    $subQuery->selectRaw('1')
                        ->from('tournament_treners')
                        ->whereColumn('tournament_treners.tournament_id', 'tournaments.id')
                        ->where('tournament_treners.trener_id', $user->coach_id);
                }));
        }

        if ($this->userHasRole($user, 'Judge')) {
            return $query->whereHas('tournaments', fn (Builder $tournaments) => $tournaments
                ->where('organization_id', $user->organization_id)
                ->where('tournament_type', Tournament::KATA)
                ->where('tournament_type_kata', Tournament::POINT_SYSTEM));
        }

        return $query->whereRaw('1 = 0');
    }

    private function visibleTournaments(User $user): Builder
    {
        $query = Tournament::query();

        if ($this->userHasRole($user, 'Admin')) {
            return $query;
        }

        if ($this->userHasRole($user, 'Organization')) {
            return $query->where('organization_id', $user->id);
        }

        if ($this->userHasRole($user, 'Secretary')) {
            return $query->where('organization_id', $user->organization_id);
        }

        if ($this->userHasRole($user, 'Coach')) {
            return $query->whereExists(function ($subQuery) use ($user): void {
                $subQuery->selectRaw('1')
                    ->from('tournament_treners')
                    ->whereColumn('tournament_treners.tournament_id', 'tournaments.id')
                    ->where('tournament_treners.trener_id', $user->id);
            });
        }

        if ($this->userHasRole($user, 'Student')) {
            if (! $user->coach_id) {
                return $query->whereRaw('1 = 0');
            }

            return $query->whereExists(function ($subQuery) use ($user): void {
                $subQuery->selectRaw('1')
                    ->from('tournament_treners')
                    ->whereColumn('tournament_treners.tournament_id', 'tournaments.id')
                    ->where('tournament_treners.trener_id', $user->coach_id);
            });
        }

        return $query->whereRaw('1 = 0');
    }

    private function writeActivity(User $causer, Tournament $tournament, string $type): void
    {
        DB::table('activity_log')->insert([
            'log_name' => 'panel',
            'description' => 'Скачан файл турнира',
            'subject_type' => Tournament::class,
            'subject_id' => $tournament->id,
            'event' => 'tournament.downloaded',
            'causer_type' => User::class,
            'causer_id' => $causer->id,
            'properties' => json_encode([
                'tournament' => $this->tournamentSnapshot($tournament),
                'type' => $type,
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function tournamentSnapshot(Tournament $tournament): array
    {
        return [
            'id' => $tournament->id,
            'championship_id' => $tournament->championship_id,
            'organization_id' => $tournament->organization_id,
            'name' => $tournament->name,
            'region_id' => $tournament->region_id,
            'scale_id' => $tournament->scale_id,
            'tournament_type' => $tournament->tournament_type,
            'tournament_type_kata' => $tournament->tournament_type_kata,
            'date' => $tournament->date,
            'date_finish' => $tournament->date_finish,
        ];
    }

    private function userHasRole(User $user, string $role): bool
    {
        return User::query()
            ->whereKey($user->id)
            ->role($role)
            ->exists();
    }
}
