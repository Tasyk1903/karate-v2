<?php

namespace App\Services\Exports;

use App\Http\Controllers\Panel\TeamController;
use App\Http\Controllers\Panel\TournamentController;
use App\Http\Controllers\Panel\Tournaments\BracketController;
use App\Http\Controllers\Panel\Tournaments\KataTableController;
use App\Http\Controllers\Panel\Tournaments\TournamentDownloadController;
use App\Models\Championship;
use App\Models\PanelTask;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Tournaments\PoolGenerationService;
use App\Services\Tournaments\TournamentDownloadService;
use Illuminate\Http\Request;

final class PanelTaskRunner
{
    public function run(PanelTask $task, User $actor)
    {
        $c = $task->context;
        $request = Request::create('/api/panel/tasks/'.$task->id, 'GET', ($c['filters'] ?? []) + ['locale' => $task->locale]);
        $request->setUserResolver(fn () => $actor);
        $request->attributes->set('panel_task', true);
        $request->attributes->set('generation_revision', $c['revision'] ?? null);
        app()->instance('request', $request);
        auth()->setUser($actor);
        $championship = isset($c['championship']) ? Championship::findOrFail($c['championship']) : null;
        $tournament = isset($c['tournament']) ? Tournament::findOrFail($c['tournament']) : null;

        return match ($task->kind) {
            'mobile_tournament' => app(TournamentDownloadService::class)->download($tournament, $c['type']),
            'tournament' => app(TournamentDownloadController::class)($request, $championship, $tournament, $c['type'], app(TournamentDownloadService::class)),
            'kata' => app(KataTableController::class)->downloadPdf($request, $championship, $tournament, $c['list']),
            'championship' => app(TournamentController::class)->exportParticipants($request, $championship),
            'team' => app(TeamController::class)->export($request),
            'trainer' => app(TeamController::class)->exportTrainerStudents($request, User::findOrFail($c['trainer'])),
            'generate' => app(BracketController::class)->generate($request, $championship, $tournament, app(PoolGenerationService::class)),
        };
    }
}
