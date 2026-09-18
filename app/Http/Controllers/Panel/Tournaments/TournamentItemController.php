<?php

namespace App\Http\Controllers\Panel\Tournaments;

use App\Models\Championship;
use App\Models\Tournament;
use App\Services\Tournaments\ListCompatibility;
use App\Services\Tournaments\TournamentAssetUpdate;
use App\Services\Tournaments\TournamentInput;
use App\Services\Tournaments\TournamentLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TournamentItemController extends BaseTournamentController
{
    public function showTournament(Request $request, Championship $championship, Tournament $tournament): JsonResponse
    {
        return parent::showTournament($request, $championship, $tournament);
    }

    public function storeTournament(Request $request, Championship $championship): JsonResponse
    {
        abort_unless($this->canManageChampionship($request->user(), $championship), 403);
        $data = TournamentInput::validated($request);
        $attributes = TournamentInput::attributes($data) + ['championship_id' => $championship->id, 'organization_id' => $this->organizationId($request->user())];
        $tournament = app(TournamentAssetUpdate::class)->save(new Tournament, $attributes, $request,
            ['regulation_document' => 'regulation_document', 'application_document' => 'application_document', 'logo_report' => 'logo_report'],
            fn (Tournament $tournament) => $this->writeTournamentActivity($request->user(), 'Создан турнир', 'tournament.created', Tournament::class, $tournament->id,
                ['championship' => $this->championshipSnapshot($championship), 'new' => $this->tournamentSnapshot($tournament)]));
        $tournament->load(['region:id,name', 'scale:id,name', 'treners:id,club'])->loadCount(['students', 'treners']);

        return response()->json(['item' => $this->formatTournament($request->user(), $tournament)], 201);
    }

    public function updateTournament(Request $request, Championship $championship, Tournament $tournament): JsonResponse
    {
        $this->authorizeTournamentManage($request->user(), $championship, $tournament);
        $data = TournamentInput::validated($request);
        $attributes = TournamentInput::attributes($data);
        $candidate = new Tournament($attributes);
        if ((int) $candidate->tournament_type !== (int) $tournament->tournament_type || (int) $candidate->tournament_type_kata !== (int) $tournament->tournament_type_kata) {
            foreach ($tournament->lists()->with('templateStudentList')->get() as $list) {
                app(ListCompatibility::class)->assert($list->templateStudentList, $candidate);
            }
        }
        app(TournamentAssetUpdate::class)->save($tournament, $attributes, $request,
            ['regulation_document' => 'regulation_document', 'application_document' => 'application_document', 'logo_report' => 'logo_report'],
            fn (Tournament $tournament, array $before) => $this->writeTournamentActivity($request->user(), 'Турнир изменен', 'tournament.updated', Tournament::class, $tournament->id,
                ['championship' => $this->championshipSnapshot($championship), 'old' => $before, 'new' => $this->tournamentSnapshot($tournament)]));

        return $this->showTournament($request, $championship, $tournament->refresh());
    }

    public function destroyTournament(Request $request, Championship $championship, Tournament $tournament): JsonResponse
    {
        $this->authorizeChampionshipOwner($request->user(), $championship);
        abort_unless((int) $tournament->championship_id === (int) $championship->id && TournamentLifecycle::owns($request->user(), $tournament), 403);
        DB::transaction(function () use ($request, $tournament): void {
            $locked = Tournament::lockForUpdate()->findOrFail($tournament->id);
            $old = $this->tournamentSnapshot($locked);
            $locked->delete();
            $this->writeTournamentActivity($request->user(), 'Турнир удалён', 'tournament.deleted', Tournament::class, $locked->id,
                ['old' => $old, 'new' => ['deleted_at' => $locked->deleted_at?->toISOString()], 'dependencies_preserved' => true]);
        });

        return response()->json(['deleted' => true]);
    }
}
