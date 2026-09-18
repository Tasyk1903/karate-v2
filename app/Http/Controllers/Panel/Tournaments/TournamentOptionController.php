<?php

namespace App\Http\Controllers\Panel\Tournaments;

use App\Models\Championship;
use App\Models\TemplateStudentList;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Tournaments\ListCompatibility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class TournamentOptionController extends BaseTournamentController
{
    public function index(Request $request, Championship $championship, Tournament $tournament, string $kind)
    {
        $this->authorizeTournamentManage($request->user(), $championship, $tournament);
        abort_unless(in_array($kind, ['coaches', 'lists'], true), 404);
        $org = $this->organizationId($request->user());
        if ($kind === 'coaches') {
            $query = User::role('Coach')->where('organization_id', $org)
                ->whereNotIn('id', DB::table('tournament_treners')->where('tournament_id', $tournament->id)->select('trener_id'))
                ->select(['id', 'first_name', 'last_name', 'club'])->orderBy('last_name')->orderBy('id');
            if ($search = trim($request->string('search')->toString())) {
                $query->where(fn ($q) => $q->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%")->orWhere('club', 'like', "%{$search}%"));
            }
        } else {
            $query = TemplateStudentList::where('user_id', $org)->where(fn ($q) => app(ListCompatibility::class)->constrain($q, $tournament))
                ->whereNotIn('id', DB::table('list_tournaments')->where('tournament_id', $tournament->id)->select('template_student_list_id'))
                ->select(['id', 'name', 'list_type', 'kata_type'])->orderBy('sort_order')->orderBy('id');
            if ($search = trim($request->string('search')->toString())) {
                $query->where('name', 'like', "%{$search}%");
            }
        }
        $page = $query->paginate(30);

        return response()->json(['data' => $page->getCollection()->map(fn ($row) => ['id' => $row->id, 'name' => $kind === 'coaches' ? trim($row->last_name.' '.$row->first_name) : $row->name, 'subtitle' => $kind === 'coaches' ? $row->club : __('exports.'.$row->list_type)]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }
}
