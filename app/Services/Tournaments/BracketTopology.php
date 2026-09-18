<?php

namespace App\Services\Tournaments;

use App\Models\Pool;
use App\Models\Tournament;
use Illuminate\Support\Collection;

final class BracketTopology
{
    private array $feeders = [];

    private array $active = [];

    private array $outcomes = [];

    private Collection $ordered;

    public function __construct(private Collection $pools)
    {
        $this->ordered = $pools->whereNotIn('type', ['3rd', 'Round Robin'])->sortBy(fn ($p) => [(int) $p->round, (int) $p->position_in_round, $p->id])->values();
        $coordinates = [];
        foreach ($this->ordered as $pool) {
            foreach (['student_id' => 1, 'opponent_id' => 0] as $side => $offset) {
                $source = $coordinates[((int) $pool->round - 1).':'.(2 * (int) $pool->position_in_round - $offset)] ?? null;
                // Empty earlier-round placeholders do not own a later seeded participant.
                $this->feeders[$pool->id][$side] = $source && ($this->active[$source->id] ?? false) ? $source : null;
            }
            $this->active[$pool->id] = (bool) ($pool->student_id || $pool->opponent_id || array_filter($this->feeders[$pool->id]));
            $coordinates[(int) $pool->round.':'.(int) $pool->position_in_round] = $pool;
        }
    }

    public function seeds(): array
    {
        $seeds = [];
        foreach ($this->ordered as $pool) {
            foreach (['student_id', 'opponent_id'] as $side) {
                if (! $this->feeders[$pool->id][$side] && $pool->$side) {
                    $seeds[] = ['pool' => $pool, 'side' => $side, 'id' => (int) $pool->$side];
                }
            }
        }

        return $seeds;
    }

    public function ready(Pool $pool): bool
    {
        if ($pool->type === 'Round Robin') {
            return true;
        }
        if ($pool->type === '3rd') {
            return $this->ordered->where('type', '1/2')->every(fn ($semi) => $this->outcome($semi)['ready']);
        }
        foreach ($this->feeders[$pool->id] ?? [] as $feeder) {
            if ($feeder && ! $this->outcome($feeder)['ready']) {
                return false;
            }
        }

        return true;
    }

    private function outcome(Pool $pool): array
    {
        if (isset($this->outcomes[$pool->id])) {
            return $this->outcomes[$pool->id];
        }
        if (! $this->ready($pool)) {
            return ['ready' => false, 'id' => null];
        }
        $ids = array_values(array_filter([$pool->student_id, $pool->opponent_id]));
        $present = array_values(array_filter([$pool->absent_student ? null : $pool->student_id, $pool->absent_opponent ? null : $pool->opponent_id]));
        if ($pool->winner_id && in_array($pool->winner_id, $present)) {
            return ['ready' => true, 'id' => (int) $pool->winner_id];
        }
        if (! $ids || ! $present) {
            return ['ready' => true, 'id' => null];
        }
        if (count($present) === 1) {
            return ['ready' => true, 'id' => (int) $present[0]];
        }

        return ['ready' => false, 'id' => null];
    }

    public function project(Tournament $tournament): void
    {
        $this->outcomes = [];
        foreach ($this->ordered as $pool) {
            $changed = false;
            foreach ($this->feeders[$pool->id] as $side => $feeder) {
                if (! $feeder) {
                    continue;
                }
                $expected = $this->outcome($feeder)['id'];
                if ((int) $pool->$side !== (int) $expected) {
                    $pool->$side = $expected;
                    $changed = true;
                }
            }
            if ($changed || ! $this->ready($pool)) {
                BracketState::reset($pool);
            }
            $this->outcomes[$pool->id] = $this->outcome($pool);
        }
        $third = $this->pools->firstWhere('type', '3rd');
        $semis = $this->ordered->where('type', '1/2');
        if (! $tournament->fight_for_third_place || $semis->isEmpty()) {
            return;
        }
        $losers = ['student_id' => null, 'opponent_id' => null];
        foreach ($semis as $semi) {
            if ($semi->winner_id && $semi->student_id && $semi->opponent_id && ! $semi->absent_student && ! $semi->absent_opponent) {
                $side = (int) $semi->position_in_round % 2 === 1 ? 'student_id' : 'opponent_id';
                $losers[$side] = (int) ($semi->winner_id == $semi->student_id ? $semi->opponent_id : $semi->student_id);
            }
        }
        if (! $third && ! array_filter($losers)) {
            return;
        }
        if (! $third) {
            $third = new Pool(['tournament_id' => $tournament->id, 'list_id' => $semis->first()->list_id, 'type' => '3rd', 'round' => (int) $semis->first()->round + 2, 'position_in_round' => 1]);
            $this->pools->push($third);
        }
        if ((int) $third->student_id !== (int) $losers['student_id'] || (int) $third->opponent_id !== (int) $losers['opponent_id']) {
            $third->fill($losers);
            BracketState::reset($third);
        }
        if (! $this->ready($third)) {
            BracketState::reset($third);
        }
    }
}
