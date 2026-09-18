<?php

namespace App\Services\Tournaments\Kata;

use App\Models\KataPool;
use App\Models\ListTournament;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Team\TeamActivity;
use App\Services\Tournaments\TournamentLifecycle;
use Illuminate\Support\Facades\DB;

final class KataMutation
{
    public function run(User $actor, int $tournamentId, int $listId, string $event, array $context, callable $action, ?string $scoreField = null): mixed
    {
        return DB::transaction(function () use ($actor, $tournamentId, $listId, $event, $context, $action, $scoreField) {
            $tournament = Tournament::lockForUpdate()->findOrFail($tournamentId);
            $actor = User::lockForUpdate()->findOrFail($actor->id);
            abort_unless((int) $tournament->tournament_type === Tournament::KATA && (int) $tournament->tournament_type_kata === Tournament::POINT_SYSTEM, 404);
            abort_unless($scoreField ? in_array($scoreField, KataAccess::fields($actor, $tournament), true) : TournamentLifecycle::canManage($actor, $tournament), 403);
            $list = ListTournament::where('tournament_id', $tournamentId)->lockForUpdate()->findOrFail($listId);
            $pools = KataPool::where('tournament_id', $tournamentId)->where('list_id', $listId)->orderBy('id')->lockForUpdate()->get();
            if ($scoreField && $actor->hasProjectRole('Judge')) {
                $target = $pools->firstWhere('id', $context['kata_pool_id'] ?? null);
                abort_unless($target && JudgeKataAccess::stageOpen($target->round, $pools), 403, __('staff.stage_closed'));
            }
            $before = KataState::snapshot($list, $pools);
            $result = $action($pools, $list, $tournament);
            foreach ($pools as $pool) {
                if ($pool->isDirty()) {
                    $pool->save();
                }
            }
            if ($list->isDirty()) {
                $list->save();
            }
            $after = KataState::snapshot($list, $pools);
            if ($before !== $after) {
                TeamActivity::record($actor, $event, Tournament::class, $tournamentId, $context + ['list_id' => $listId, 'old' => $before, 'new' => $after]);
            }

            return $result;
        }, 3);
    }
}
