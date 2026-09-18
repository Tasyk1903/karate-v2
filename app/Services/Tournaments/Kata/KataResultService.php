<?php

namespace App\Services\Tournaments\Kata;

use App\Models\Tournament;
use App\Models\User;

final class KataResultService
{
    public function generate(User $actor, Tournament $tournament, int $list, bool $confirmed, string $revision): void
    {
        app(KataMutation::class)->run($actor, $tournament->id, $list, 'tournament.kata.winners_generated', [], function ($pools, $list) use ($confirmed, $revision) {
            KataState::checkRevision($list, $pools, $revision);
            $final = $pools->where('round', 'FINAL');
            KataState::confirm(KataState::results($final), $confirmed);
            abort_unless($final->isNotEmpty() && $final->every(fn ($p) => KataScores::complete($p)), 422, __('kata.incomplete_final'));
            $pre = $pools->where('round', 'PRELIMINARY STAGE')->keyBy(fn ($p) => KataScores::identity($p));
            foreach ($pools as $pool) {
                KataScores::calculate($pool);
            }
            $compare = function ($a, $b) use ($pre) {
                $comparison = KataScores::compare($a, $b);
                if ($comparison) {
                    return $comparison;
                }
                $ap = $pre->get(KataScores::identity($a));
                $bp = $pre->get(KataScores::identity($b));

                return $ap && $bp && KataScores::complete($ap) && KataScores::complete($bp) ? KataScores::compare($ap, $bp) : 0;
            };
            $sorted = $final->sort($compare)->values();
            for ($i = 0; $i < min(3, $sorted->count() - 1); $i++) {
                abort_if($compare($sorted[$i], $sorted[$i + 1]) === 0, 422, __('kata.podium_tie'));
            }
            KataState::clearResults($final);
            foreach ($sorted as $index => $pool) {
                $pool->rank = $index + 1;
                if ($index < 3) {
                    $pool->{'winner_'.($index + 1)} = true;
                }
            }
        });
    }
}
