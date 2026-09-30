<?php

namespace App\Services\Tournaments;

use App\Models\Pool;
use Illuminate\Support\Collection;

final class SpectatorFightPath
{
    public function stage(int $round, int $last, ?string $type): string
    {
        if ($type === 'Round Robin') {
            return 'Round Robin';
        }
        if ($type === '3rd') {
            return __('spectator.third');
        }
        if ($type === 'final' || $round === $last) {
            return __('spectator.final');
        }

        return '1/'.(2 ** max(1, $last - $round));
    }

    public function status(Pool $pool, int $studentId): string
    {
        $present = in_array($studentId, [(int) $pool->student_id, (int) $pool->opponent_id], true);
        if (! $present) {
            return 'possible';
        }
        if (($pool->student_id == $studentId && $pool->absent_student) || ($pool->opponent_id == $studentId && $pool->absent_opponent)) {
            return 'absent';
        }
        if ($pool->winner_id) {
            return $pool->winner_id == $studentId ? 'won' : 'lost';
        }

        return 'upcoming';
    }

    public function build(Collection $pools, int $studentId, ?string $tatami): array
    {
        $regular = $pools->whereNotIn('type', ['3rd', 'Round Robin'])->sortBy(fn ($p) => [$p->round, $p->position_in_round, $p->id])->values();
        $last = (int) $regular->max('round');
        $format = fn ($p, $status = null, $side = null) => [
            'id' => $p->id, 'round' => (int) $p->round, 'stage' => $this->stage((int) $p->round, $last, $p->type),
            'number' => filled($p->tatami_and_fight_number) && $p->tatami_and_fight_number !== '0' ? $p->tatami_and_fight_number : null, 'tatami' => $tatami,
            'side' => $p->student_id == $studentId ? 'white' : ($p->opponent_id == $studentId ? 'red' : $side),
            'status' => $status ?? $this->status($p, $studentId),
        ];
        $own = $pools->filter(fn ($p) => $p->student_id == $studentId || $p->opponent_id == $studentId);
        if ($pools->contains('type', 'Round Robin')) {
            return $own->sortBy('id')->map(fn ($p) => $format($p))->values()->all();
        }
        $start = $regular->first(fn ($p) => $p->student_id == $studentId || $p->opponent_id == $studentId);
        if (! $start) {
            return $own->map(fn ($p) => $format($p))->values()->all();
        }
        $path = [];
        $current = $start;
        $visited = [];
        $third = $pools->firstWhere('type', '3rd');
        $thirdPossible = false;
        $side = $start->student_id == $studentId ? 'white' : 'red';
        $thirdSide = null;
        $lostSemi = false;
        while ($current && ! isset($visited[$current->id])) {
            $visited[$current->id] = true;
            $status = $this->status($current, $studentId);
            $nextRound = (int) $current->round + 1;
            $position = intdiv((int) $current->position_in_round - 1, 2) + 1;
            $next = $regular->first(fn ($p) => (int) $p->round === $nextRound && (int) $p->position_in_round === $position);
            // Legacy byes/absence advances can place the student in the next fight without winner_id.
            if ($status === 'upcoming' && $next && in_array($studentId, [(int) $next->student_id, (int) $next->opponent_id], true)) {
                $status = 'won';
            }
            $path[] = $format($current, $status, $side);
            if ($current->type === '1/2' || (int) $current->round === $last - 1) {
                $thirdPossible = ! in_array($status, ['won', 'absent'], true);
                $thirdSide = (int) $current->position_in_round % 2 === 1 ? 'white' : 'red';
                $lostSemi = $status === 'lost';
            }
            if (in_array($status, ['lost', 'absent'], true)) {
                break;
            }
            $side = (int) $current->position_in_round % 2 === 1 ? 'white' : 'red';
            $current = $next;
        }
        if ($third && ($thirdPossible || $own->contains('id', $third->id))) {
            $thirdStatus = $this->status($third, $studentId);
            $path[] = $format($third, $lostSemi && $thirdStatus === 'possible' ? 'upcoming' : $thirdStatus, $thirdSide);
        }

        return $path;
    }
}
