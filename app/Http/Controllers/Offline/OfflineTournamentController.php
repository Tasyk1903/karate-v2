<?php

namespace App\Http\Controllers\Offline;

use App\Http\Controllers\Controller;
use App\Models\ListTournament;
use App\Models\Tournament;
use App\Services\Offline\OfflineGrant;
use App\Services\Offline\OfflineSnapshot;
use App\Services\Offline\OfflineSync;
use App\Services\Tournaments\TournamentLifecycle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class OfflineTournamentController extends Controller
{
    public function index(Request $request)
    {
        $org = TournamentLifecycle::organizationId($request->user());
        $page = Tournament::where('organization_id', $org)->whereHas('championship', fn ($q) => $q->where('organization_id', $org))
            ->whereDate('date_finish', '>=', today())->with('championship:id,name')->with(['listTournaments' => fn ($q) => $q->with('templateStudentList:id,name')->withCount(['pools', 'kataPools'])])
            ->orderBy('date')->orderBy('id')->paginate(20);
        $rows = $page->getCollection()->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'championship' => $t->championship->name, 'date' => $t->date?->toDateString(), 'date_finish' => $t->date_finish?->toDateString(), 'tatami_count' => (int) $t->tatami, 'lists' => $t->listTournaments->map(fn ($l) => ['id' => $l->id, 'name' => $l->templateStudentList?->name, 'tatami' => $l->tatami, 'prepared' => $l->pools_count + $l->kata_pools_count > 0])->values()]);

        return response()->json(['data' => $rows, 'last_page' => $page->lastPage(), 'engine' => OfflineSnapshot::engineVersion(), 'protocol' => OfflineSnapshot::PROTOCOL]);
    }

    public function show(Request $request, int $list)
    {
        return DB::transaction(function () use ($request, $list) {
            $model = ListTournament::findOrFail($list);
            $tournament = Tournament::lockForUpdate()->findOrFail($model->tournament_id);
            abort_unless(TournamentLifecycle::owns($request->user(), $tournament), 403);
            $snapshot = app(OfflineSnapshot::class)->capture($model, $request->user());
            if (TournamentLifecycle::active($tournament)) {
                $snapshot = app(OfflineGrant::class)->issue($request->user(), $model, $snapshot);
            }

            return response()->json($snapshot);
        });
    }

    public function sync(Request $request, int $list)
    {
        $data = $request->validate(['id' => ['required', 'uuid'], 'engine' => ['required', 'string', 'size:64'], 'base_revision' => ['required', 'string', 'size:64'], 'grant' => ['required', 'string', 'max:4096'], 'commands' => ['required', 'array', 'min:1', 'max:250'], 'commands.*' => ['required', 'array']]);

        return response()->json(app(OfflineSync::class)->apply($request->user(), $list, $data));
    }
}
