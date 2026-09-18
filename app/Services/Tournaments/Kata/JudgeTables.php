<?php

namespace App\Services\Tournaments\Kata;

use App\Models\KataPool;
use App\Models\ListTournament;
use App\Models\StudentTournament;
use App\Models\User;
use App\Services\Exports\ExportLabels;
use Illuminate\Http\Request;

final class JudgeTables
{
    public function list(User $user, int $id): ListTournament
    {
        return app(JudgeKataAccess::class)->lists($user)->with(['tournament.championship', 'templateStudentList'])->findOrFail($id);
    }

    public function heading(ListTournament $list): array
    {
        return ['id' => $list->id, 'title' => $list->templateStudentList?->name,
            'tournament_id' => $list->tournament_id, 'tournament' => $list->tournament->name,
            'championship' => $list->tournament->championship?->name, 'tatami' => $list->tatami,
            'date' => $list->tournament->date?->toDateString()];
    }

    public function table(Request $request, ListTournament $list): array
    {
        $data = $request->validate(['round' => 'sometimes|in:pre,final', 'page' => 'sometimes|integer|min:1']);
        $round = ($data['round'] ?? 'pre') === 'pre' ? 'PRELIMINARY STAGE' : 'FINAL';
        $query = KataPool::where('tournament_id', $list->tournament_id)->where('list_id', $list->id);
        $state = (clone $query)->get(['id', 'round', 'winner_1', 'winner_2', 'winner_3']);
        $field = KataAccess::fields($request->user(), $list->tournament)[0] ?? null;
        $page = $query->where('round', $round)->orderBy('participant_number')->orderBy('id')
            ->paginate(30, ['id', 'student_id', 'students', 'participant_number', 'round', ...($field ? [$field] : [])]);
        $ids = $page->getCollection()->flatMap(fn ($pool) => $pool->students ?: [$pool->student_id])->filter()->unique();
        $students = User::whereIn('id', $ids)->with('coach:id,first_name,last_name,club')
            ->get(['id', 'first_name', 'last_name', 'coach_id', 'rang'])->keyBy('id');
        $applications = StudentTournament::with(['educationKlassCategory:id,name', 'onlineKataFirstRoundCategory:id,name', 'onlineKataSecondRoundCategory:id,name'])
            ->where('tournament_id', $list->tournament_id)->where('list_tournament_id', $list->id)->whereIn('student_id', $ids)
            ->orderBy('id')->get()->unique('student_id')->keyBy('student_id');
        $page->through(function ($pool) use ($students, $applications, $field, $list) {
            return ['id' => $pool->id, 'number' => $pool->participant_number, 'score' => $field ? $pool->getRawOriginal($field) : null,
                'students' => collect($pool->students ?: [$pool->student_id])->map(function ($id) use ($students, $applications, $pool, $list) {
                    $student = $students->get($id);
                    if (! $student) {
                        return null;
                    }
                    $video = $this->roundVideo($applications->get($id), $pool->round);

                    return ['id' => $student->id, 'name' => $student->full_name, 'club' => $student->coach?->club,
                        'coach' => $student->coach?->full_name, 'rank' => ExportLabels::rank($student->rang),
                        'category' => $video['category'],
                        'video_url' => $list->tournament->is_online_kata && $video['path'] ? url("/api/mobile/files/judge/{$pool->id}/{$id}") : null];
                })->filter()->values()];
        });

        return ['table' => $this->heading($list), 'field' => $field,
            'can_score' => $field !== null && JudgeKataAccess::stageOpen($round, $state),
            'reason' => $field === null ? __('staff.position_required') : (JudgeKataAccess::stageOpen($round, $state) ? null : __('staff.stage_closed')),
            'data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]];
    }

    public function roundVideo(?StudentTournament $application, string $round): array
    {
        if ($round === 'FINAL') {
            return ['path' => $application?->online_kata_second_round_video_path,
                'category' => $application?->onlineKataSecondRoundCategory?->name];
        }

        return ['path' => $application?->online_kata_first_round_video_path ?: $application?->online_kata_video_path,
            'category' => $application?->onlineKataFirstRoundCategory?->name ?: $application?->educationKlassCategory?->name];
    }
}
