<?php

namespace App\Services\Tournaments\Kata;

use App\Models\KataPool;
use App\Models\Tournament;
use App\Models\User;

final class KataFinalService
{
    public function count(User $actor, Tournament $tournament, int $list, int $count, bool $confirmed, string $revision): void
    {
        abort_unless($count >= 4 && $count <= 8, 422);
        app(KataMutation::class)->run($actor, $tournament->id, $list, 'tournament.kata.finalists_count_updated', [], function ($pools, $list) use ($count, $confirmed, $revision) {
            KataState::checkRevision($list, $pools, $revision);
            if ((int) ($list->finalists_count ?? 4) === $count) {
                return;
            }
            KataState::confirm($pools->where('round', 'FINAL')->isNotEmpty(), $confirmed);
            KataState::removeFinal($pools);
            $list->finalists_count = $count;
        });
    }

    public function generate(User $actor, Tournament $tournament, int $list, bool $confirmed, string $revision): void
    {
        app(KataMutation::class)->run($actor, $tournament->id, $list, 'tournament.kata.final_generated', [], function ($pools, $list) use ($tournament, $confirmed, $revision) {
            KataState::checkRevision($list, $pools, $revision);
            KataState::confirm($pools->where('round', 'FINAL')->isNotEmpty(), $confirmed);
            $pre = $pools->where('round', 'PRELIMINARY STAGE');
            abort_unless($pre->isNotEmpty() && $pre->every(fn ($p) => KataScores::complete($p)), 422, __('kata.incomplete_pre'));
            foreach ($pre as $pool) {
                KataScores::calculate($pool);
            }
            $sorted = $pre->sort(fn ($a, $b) => KataScores::compare($a, $b))->values();
            $count = (int) ($list->finalists_count ?? 4);
            abort_if($sorted->count() > $count && KataScores::compare($sorted[$count - 1], $sorted[$count]) === 0, 422, __('kata.final_tie'));
            KataState::removeFinal($pools);
            foreach ($sorted as $index => $pool) {
                $pool->rank = $index + 1;
            }
            foreach ($sorted->take($count) as $pool) {
                $pools->push(new KataPool(['student_id' => $pool->student_id, 'tournament_id' => $tournament->id, 'list_id' => $list->id, 'round' => 'FINAL', 'group_id' => $pool->group_id, 'students' => $pool->students]));
            }
        });
    }
}
