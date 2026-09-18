<?php

namespace App\Http\Controllers\Panel\Tournaments;

use App\Models\Championship;
use App\Models\Tournament;
use App\Services\Tournaments\TournamentBulkActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TournamentBulkController extends BaseTournamentController
{
    public function coaches(Request $request, Championship $championship, Tournament $tournament): JsonResponse
    {
        return $this->detach($request, $championship, $tournament, 'coaches');
    }

    public function lists(Request $request, Championship $championship, Tournament $tournament): JsonResponse
    {
        return $this->detach($request, $championship, $tournament, 'lists');
    }

    private function detach(Request $request, Championship $championship, Tournament $tournament, string $kind): JsonResponse
    {
        $this->authorizeTournamentManage($request->user(), $championship, $tournament);
        app(TournamentBulkActions::class)->detach($request->user(), $tournament, $kind, $this->ids($request));

        return response()->json(['ok' => true]);
    }

    public function forms(Request $request, Championship $championship): JsonResponse
    {
        $this->authorizeChampionshipOwner($request->user(), $championship);
        app(TournamentBulkActions::class)->deleteForms($request->user(), $championship, $this->ids($request));

        return response()->json(['ok' => true]);
    }

    private function ids(Request $request): array
    {
        app()->setLocale($request->input('locale', app()->getLocale()) === 'en' ? 'en' : 'ru');

        return $request->validate(['ids' => ['required', 'array', 'min:1', 'max:200'], 'ids.*' => ['required', 'integer', 'min:1', 'distinct']])['ids'];
    }
}
