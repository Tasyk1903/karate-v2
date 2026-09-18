<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Championship;
use App\Models\ListTournament;
use App\Models\Tournament;
use App\Models\TournamentStudentList;
use App\Services\Exports\MobileTournamentExports;
use App\Services\Exports\PanelTasks;
use App\Services\Team\TeamActivity;
use App\Services\Tournaments\MobileTournamentAccess;
use App\Services\Tournaments\TournamentAge;
use App\Services\Tournaments\TournamentDownloadService;
use Illuminate\Http\Request;

final class MobileTournamentListController extends Controller
{
    public function members(Request $request, Championship $championship, Tournament $tournament, ListTournament $listTournament)
    {
        $this->authorizeView($request, $championship, $tournament);
        abort_unless((int) $listTournament->tournament_id === (int) $tournament->id, 404);
        $groups = TournamentStudentList::query()->where('list_tournament_id', $listTournament->id)->whereNotNull('group_id')->orderBy('group_id')->distinct()->pluck('group_id');
        $search = trim($request->string('search')->toString());
        $rows = TournamentStudentList::query()->where('list_tournament_id', $listTournament->id)
            ->whereHas('student.coach')
            ->when($search !== '', fn ($q) => $q->whereHas('student', fn ($s) => $s->where(fn ($s) => $s->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"))))
            ->with(['student' => fn ($q) => $q->select('id', 'coach_id', 'first_name', 'last_name', 'avatar', 'birthday', 'weight', 'rang'), 'student.coach:id,first_name,last_name,club'])
            ->orderBy('group_id')->orderBy('id')->paginate(30);

        return response()->json([
            'data' => $rows->map(fn ($row) => [
                'id' => $row->id, 'student_id' => $row->student_id, 'group_id' => $row->group_id,
                'group_number' => $row->group_id ? $groups->search($row->group_id) + 1 : null,
                'name' => trim($row->student->last_name.' '.$row->student->first_name),
                'age' => TournamentAge::onCommissionDay($row->student->birthday, $tournament), 'weight' => $row->student->weight, 'rang' => $row->student->rang,
                'club' => $row->student->coach->club,
                'coach_name' => trim($row->student->coach->last_name.' '.$row->student->coach->first_name),
                'can_open_profile' => (int) $row->student->coach_id === (int) $request->user()->id,
            ])->values(),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()],
        ]);
    }

    public function export(Request $request, Championship $championship, Tournament $tournament, string $format, TournamentDownloadService $downloads)
    {
        $this->authorizeView($request, $championship, $tournament);
        abort_unless(in_array($format, ['excel', 'pdf'], true), 404);
        $response = $downloads->download($tournament, 'lists-'.$format);
        TeamActivity::record($request->user(), 'tournament.lists.exported', Tournament::class, $tournament->id,
            ['old' => null, 'new' => ['format' => $format], 'championship_id' => $championship->id, 'channel' => 'mobile']);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function authorizeView(Request $request, Championship $championship, Tournament $tournament): void
    {
        abort_unless((int) $tournament->championship_id === (int) $championship->id, 404);
        abort_unless(app(MobileTournamentAccess::class)->assigned($request->user(), $tournament), 403);
    }

    public function queueExport(Request $request, Championship $championship, Tournament $tournament)
    {
        $this->authorizeView($request, $championship, $tournament);
        $data = $request->validate(['format' => ['required', 'string']]);
        app(MobileTournamentExports::class)->authorize($request->user(), $tournament, $data['format']);

        return app(PanelTasks::class)->defer($request, 'mobile_tournament', ['championship' => $championship->id, 'tournament' => $tournament->id, 'type' => $data['format']]);
    }
}
