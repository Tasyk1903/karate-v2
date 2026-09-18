<?php

namespace App\Services\Tournaments;

use App\Models\Pool;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class FightResultService
{
    public function save(Pool $pool, User $actor, ?string $version, ?int $winner, array $scores, ?array $absences): string
    {
        return app(BracketMutation::class)->run($pool->tournament_id, $pool->list_id, $actor, $version,
            $absences === null ? 'tournament.pool.winner_updated' : 'tournament.pool.absences_updated', ['pool_id' => $pool->id],
            function ($pools, $tournament) use ($pool, $winner, $scores, $absences) {
                $target = $pools->firstWhere('id', $pool->id);
                abort_unless($target, 404);
                $topology = new BracketTopology($pools);
                $ids = array_map('intval', array_filter([$target->student_id, $target->opponent_id]));
                if ($absences === null) {
                    if (! $winner || ! in_array($winner, $ids, true)) {
                        throw ValidationException::withMessages(['winner_id' => __('fights.participant')]);
                    }
                    if (! $topology->ready($target)) {
                        throw ValidationException::withMessages(['winner_id' => __('fights.pending')]);
                    }
                    $validated = Validator::make($scores, ['student_wazari_count' => ['nullable', 'integer', 'between:0,2'], 'opponent_wazari_count' => ['nullable', 'integer', 'between:0,2'], 'student_ippon' => ['nullable', 'boolean'], 'opponent_ippon' => ['nullable', 'boolean']])->validate();
                    foreach (['student', 'opponent'] as $side) {
                        if ((int) $target->{$side.'_id'} !== $winner && (! empty($validated[$side.'_wazari_count']) || ! empty($validated[$side.'_ippon']))) {
                            throw ValidationException::withMessages(['scores' => __('fights.winner_scores')]);
                        }
                    }
                    BracketState::reset($target);
                    $target->winner_id = $winner;
                    foreach (BracketState::SCORES as $field) {
                        $target->$field = $validated[$field] ?? 0;
                    }
                } else {
                    Validator::make(['absent_ids' => $absences], ['absent_ids' => ['array', 'max:2'], 'absent_ids.*' => ['integer', 'distinct']])->validate();
                    if (array_diff($absences, $ids)) {
                        throw ValidationException::withMessages(['absent_ids' => __('fights.participant')]);
                    }
                    if ($absences && ! $topology->ready($target)) {
                        throw ValidationException::withMessages(['absent_ids' => __('fights.pending')]);
                    }
                    BracketState::reset($target);
                    $target->absent_student = in_array((int) $target->student_id, $absences);
                    $target->absent_opponent = in_array((int) $target->opponent_id, $absences);
                    if (in_array($target->type, ['final', '3rd'], true) && $topology->ready($target) && ($target->absent_student xor $target->absent_opponent)) {
                        $target->winner_id = $target->absent_student ? $target->opponent_id : $target->student_id;
                    }
                }
                if ($target->type === 'Round Robin') {
                    foreach ($pools as $item) {
                        foreach (BracketState::PLACES as $field) {
                            $item->$field = null;
                        }
                    }
                } else {
                    $topology->project($tournament);
                }
            });
    }
}
