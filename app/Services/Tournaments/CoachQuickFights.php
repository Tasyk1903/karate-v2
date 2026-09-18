<?php

namespace App\Services\Tournaments;

use App\Models\KataPool;
use App\Models\Pool;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CoachQuickFights
{
    public function data(User $coach, Request $request): array
    {
        $query = DB::table('tournament_student_lists as membership')
            ->join('users as student', 'student.id', '=', 'membership.student_id')
            ->join('list_tournaments as lists', 'lists.id', '=', 'membership.list_tournament_id')
            ->join('template_student_lists as template', 'template.id', '=', 'lists.template_student_list_id')
            ->join('tournaments as tournament', 'tournament.id', '=', 'lists.tournament_id')
            ->join('championships as championship', 'championship.id', '=', 'tournament.championship_id')
            ->where('student.coach_id', $coach->id)->whereNull('student.deleted_at')
            ->whereNull('tournament.deleted_at')->whereNull('championship.deleted_at')
            ->where('tournament.date_finish', '>=', today())
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('tournament_treners')->whereColumn('tournament_id', 'tournament.id')->where('trener_id', $coach->id));

        $options = (clone $query)->select('tournament.id', 'tournament.name')->distinct()->orderBy('tournament.name')->get();
        $request->validate(['tournament_id' => ['nullable', 'integer'], 'search' => ['nullable', 'string', 'max:100']]);
        $rows = $query->when($request->filled('tournament_id'), fn ($q) => $q->where('tournament.id', $request->integer('tournament_id')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = '%'.trim($request->string('search')).'%';
                $q->where(fn ($q) => $q->where('student.first_name', 'like', $search)->orWhere('student.last_name', 'like', $search)->orWhere('template.name', 'like', $search));
            })
            ->select('student.id as student_id', 'student.first_name', 'student.last_name', 'lists.id as list_id', 'lists.tatami', 'template.name as list_name',
                'tournament.id as tournament_id', 'tournament.championship_id', 'tournament.name as tournament_name', 'tournament.tournament_type', 'tournament.tournament_type_kata')
            ->distinct()->orderBy('tournament.id')->orderBy('student.last_name')->orderBy('student.id')->orderBy('lists.id')->paginate(20);
        $ids = $rows->pluck('tournament_id')->unique();
        $pools = Pool::query()->select('id', 'tournament_id', 'list_id', 'student_id', 'opponent_id', 'winner_id', 'absent_student', 'absent_opponent', 'round', 'position_in_round', 'type', 'tatami_and_fight_number')->whereIn('tournament_id', $ids)->orderBy('round')->orderBy('position_in_round')->get();
        $kata = KataPool::query()->select('id', 'tournament_id', 'list_id', 'student_id', 'students', 'round', 'participant_number', 'rank', 'total_score')->whereIn('tournament_id', $ids)->orderBy('id')->get();
        $tatami = DB::table('list_tournaments')->whereIn('tournament_id', $ids)->pluck('tatami', 'id');
        $pathService = app(SpectatorFightPath::class);
        $data = $rows->map(function ($row) use ($pools, $kata, $tatami, $pathService, $coach) {
            $isKata = (int) $row->tournament_type === Tournament::KATA && (int) $row->tournament_type_kata === Tournament::POINT_SYSTEM;
            $listPools = $pools->where('list_id', $row->list_id);
            $path = $isKata
                ? $kata->where('list_id', $row->list_id)->filter(fn ($p) => $p->student_id == $row->student_id || in_array((int) $row->student_id, array_map('intval', $p->students ?: []), true))
                    ->map(fn ($p) => ['id' => $p->id, 'round' => $p->round === 'FINAL' ? 2 : 1, 'stage' => $p->round === 'FINAL' ? __('spectator.final') : __('spectator.preliminary'),
                        'number' => $p->participant_number, 'tatami' => $row->tatami, 'status' => $p->total_score !== null ? 'scored' : 'upcoming'])->values()->all()
                : $pathService->build($listPools, (int) $row->student_id, $row->tatami);
            $queue = app(SpectatorTatamiQueue::class)->pending(
                ($isKata ? $kata : $pools)->where('tournament_id', $row->tournament_id)
                    ->filter(fn ($p) => filled($row->tatami) && (string) $tatami->get($p->list_id) === (string) $row->tatami),
                $isKata,
            );
            $queueRow = fn ($p) => $p ? ['id' => $p->id, 'list_id' => $p->list_id, 'number' => $isKata ? $p->participant_number : $p->tatami_and_fight_number] : null;

            return [
                'student_id' => (int) $row->student_id, 'name' => trim($row->last_name.' '.$row->first_name),
                'club' => $coach->club,
                'list_id' => (int) $row->list_id, 'list_name' => $row->list_name, 'tatami' => $row->tatami,
                'tournament_id' => (int) $row->tournament_id, 'championship_id' => (int) $row->championship_id,
                'tournament_name' => $row->tournament_name, 'kind' => $isKata ? 'kata' : 'kumite',
                'generated' => $isKata ? $kata->contains('list_id', $row->list_id) : $listPools->isNotEmpty(),
                'path' => $path, 'current' => $queueRow($queue->get(0)), 'next' => $queueRow($queue->get(1)),
            ];
        })->values();

        return ['data' => $data, 'tournaments' => $options, 'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()]];
    }
}
