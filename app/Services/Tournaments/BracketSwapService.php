<?php

namespace App\Services\Tournaments;

use App\Models\Tournament;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class BracketSwapService
{
    public function available(Collection $pools): bool
    {
        return $pools->isNotEmpty() && ! $pools->contains('type', 'Round Robin') && ! BracketState::hasResults($pools);
    }

    public function swap(Tournament $tournament, int $list, User $actor, ?string $version, int $first, int $second, array $poolIds): string
    {
        Validator::make(['pool_ids' => $poolIds], ['pool_ids' => ['required', 'array', 'min:1'], 'pool_ids.*' => ['required', 'integer', 'distinct']])->validate();

        return app(BracketMutation::class)->run($tournament->id, $list, $actor, $version, 'tournament.bracket.participants_swapped', ['participant_1' => $first, 'participant_2' => $second],
            function ($pools, $locked) use ($first, $second, $poolIds) {
                if (! $this->available($pools)) {
                    throw ValidationException::withMessages(['participants' => __('fights.swap_closed')]);
                }
                if ($first === $second) {
                    throw ValidationException::withMessages(['participants' => __('fights.different')]);
                }
                if (array_diff($poolIds, $pools->pluck('id')->all())) {
                    throw ValidationException::withMessages(['pool_ids' => __('fights.scope')]);
                }
                $topology = new BracketTopology($pools);
                $seeds = collect($topology->seeds());
                $a = $seeds->where('id', $first);
                $b = $seeds->where('id', $second);
                if ($a->count() !== 1 || $b->count() !== 1) {
                    throw ValidationException::withMessages(['participants' => __('fights.participant')]);
                }
                $a = $a->first();
                $b = $b->first();
                if (! in_array($a['pool']->id, $poolIds) || ! in_array($b['pool']->id, $poolIds)) {
                    throw ValidationException::withMessages(['pool_ids' => __('fights.scope')]);
                }
                $a['pool']->{$a['side']} = $second;
                $b['pool']->{$b['side']} = $first;
                BracketState::reset($a['pool']);
                BracketState::reset($b['pool']);
                $topology->project($locked);
            });
    }
}
