<?php

namespace App\Services\Exports;

use App\Models\KataPool;
use App\Models\ListTournament;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Support\Collection;

final class KataPdf
{
    public function sheets(Tournament $tournament, ?int $listId = null): Collection
    {
        $lists = ListTournament::where('tournament_id', $tournament->id)
            ->when($listId, fn ($q) => $q->whereKey($listId))
            ->whereHas('kataPools')->with('templateStudentList')->orderBy('sort_order')->orderBy('id')->get();
        $pools = KataPool::where('tournament_id', $tournament->id)->whereIn('list_id', $lists->modelKeys())
            ->with('student.coach')->orderBy('participant_number')->orderBy('id')->get();
        $members = User::with('coach')->whereIn('id', $pools->flatMap(fn ($p) => $p->students ?? [])->unique())->get()->keyBy('id');

        return $lists->map(fn ($list) => [
            'listTournament' => $list,
            'kataPools' => $pools->where('list_id', $list->id),
            'groupStudents' => $members,
        ]);
    }
}
