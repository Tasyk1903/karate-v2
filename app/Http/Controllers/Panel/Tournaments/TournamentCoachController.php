<?php

namespace App\Http\Controllers\Panel\Tournaments;

use App\Models\Championship;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TournamentCoachController extends BaseTournamentController
{
    public function attachCoaches(Request $request, Championship $championship, Tournament $tournament): JsonResponse
    {
        $this->authorizeTournamentManage($request->user(), $championship, $tournament);

        $data = $request->validate([
            'coach_ids' => ['required', 'array', 'min:1'],
            'coach_ids.*' => ['integer'],
        ]);

        $allowedIds = User::query()
            ->role('Coach')
            ->where('organization_id', $this->organizationId($request->user()))
            ->whereIn('id', $data['coach_ids'])
            ->pluck('id');

        $attachedIds = [];
        foreach ($allowedIds as $coachId) {
            DB::table('tournament_treners')->updateOrInsert(
                ['tournament_id' => $tournament->id, 'trener_id' => $coachId],
                ['created_at' => now(), 'updated_at' => now()]
            );
            $attachedIds[] = (int) $coachId;
        }

        if ($attachedIds !== []) {
            $this->writeTournamentActivity(
                $request->user(),
                'Тренеры прикреплены к турниру',
                'tournament.coaches.attached',
                Tournament::class,
                $tournament->id,
                [
                    'tournament' => $this->tournamentSnapshot($tournament),
                    'coach_ids' => $attachedIds,
                ]
            );
        }

        return $this->showTournament($request, $championship, $tournament);
    }

    public function detachCoach(Request $request, Championship $championship, Tournament $tournament, User $coach): JsonResponse
    {
        $this->authorizeTournamentManage($request->user(), $championship, $tournament);
        app(\App\Services\Tournaments\TournamentBulkActions::class)->detach($request->user(), $tournament, 'coaches', [$coach->id]);
        return $this->showTournament($request, $championship, $tournament);
    }

}
