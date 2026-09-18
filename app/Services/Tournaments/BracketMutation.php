<?php

namespace App\Services\Tournaments;

use App\Models\ListTournament;
use App\Models\Pool;
use App\Models\Tournament;
use App\Models\User;
use App\Services\FightSoonNotifier;
use App\Services\TatamiCurrentFightBroadcaster;
use App\Services\Team\TeamActivity;
use Illuminate\Support\Facades\DB;

final class BracketMutation
{
    public function run(int $tournamentId, int $listId, User $actor, ?string $version, string $event, array $context, callable $mutate): string
    {
        return DB::transaction(function () use ($tournamentId, $listId, $actor, $version, $event, $context, $mutate) {
            $tournament = Tournament::lockForUpdate()->findOrFail($tournamentId);
            abort_unless(TournamentLifecycle::canManage($actor, $tournament), 403);
            abort_if((int) $tournament->tournament_type === Tournament::KATA && (int) $tournament->tournament_type_kata === Tournament::POINT_SYSTEM, 422, __('fights.not_bracket'));
            ListTournament::where('tournament_id', $tournamentId)->findOrFail($listId);
            $pools = Pool::where('tournament_id', $tournamentId)->where('list_id', $listId)->orderBy('id')->lockForUpdate()->get();
            abort_if($version !== null && ! hash_equals(BracketState::version($pools), $version), 409, __('fights.stale'));
            $before = BracketState::snapshot($pools);
            $mutate($pools, $tournament);
            foreach ($pools as $pool) {
                if ($pool->isDirty()) {
                    $pool->save();
                }
            }
            $after = BracketState::snapshot($pools);
            $changed = array_keys(array_filter($after, fn ($row, $id) => ($before[$id] ?? null) !== $row, ARRAY_FILTER_USE_BOTH));
            if ($changed) {
                TeamActivity::record($actor, $event, Tournament::class, $tournamentId, $context + ['list_id' => $listId,
                    'old' => array_intersect_key($before, array_flip($changed)), 'new' => array_intersect_key($after, array_flip($changed)), 'changed_pool_ids' => $changed]);
                DB::afterCommit(function () use ($changed): void {
                    foreach (Pool::whereIn('id', $changed)->get() as $pool) {
                        try {
                            app(FightSoonNotifier::class)->handlePoolChanged($pool);
                            app(TatamiCurrentFightBroadcaster::class)->broadcastForPool($pool);
                        } catch (\Throwable $error) {
                            report($error);
                        }
                    }
                });
            }

            return BracketState::version($pools);
        }, 3);
    }
}
