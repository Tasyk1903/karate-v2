<?php

namespace App\Services\Offline;

use App\Models\ListTournament;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Team\TeamActivity;
use App\Services\Tournaments\TournamentLifecycle;
use Illuminate\Support\Facades\DB;

final class OfflineSync
{
    public function apply(User $actor, int $listId, array $data): array
    {
        return DB::transaction(function () use ($actor, $listId, $data) {
            $list = ListTournament::findOrFail($listId);
            $tournament = Tournament::lockForUpdate()->findOrFail($list->tournament_id);
            $actor = User::lockForUpdate()->findOrFail($actor->id);
            abort_unless(TournamentLifecycle::owns($actor, $tournament), 403);
            $list = ListTournament::lockForUpdate()->findOrFail($listId);
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $receipt = DB::table('offline_mutations')->where('id', $data['id'])->first();
            if ($receipt) {
                abort_unless((int) $receipt->user_id === $actor->id && (int) $receipt->list_id === $listId && hash_equals($receipt->request_hash, $hash), 409, __('offline.duplicate'));

                return json_decode($receipt->response, true, 512, JSON_THROW_ON_ERROR);
            }
            $grant = app(OfflineGrant::class)->check($actor, $list, $data['grant']);
            abort_unless(hash_equals(OfflineSnapshot::engineVersion(), $data['engine']), 409, __('offline.engine_changed'));
            $before = app(OfflineSnapshot::class)->capture($list, $actor);
            abort_unless(hash_equals($before['revision'], $data['base_revision']), 409, __('offline.conflict'));
            OfflineReplay::run($tournament->id, function () use ($actor, $list, $data): void {
                foreach ($data['commands'] as $command) {
                    app(OfflineCommands::class)->execute($actor, $list->fresh(), $command);
                }
            });
            $after = app(OfflineSnapshot::class)->capture($list->fresh(), $actor);
            $after['grant'] = $data['grant'];
            $after['expires_at'] = $grant['expires'];
            TeamActivity::record($actor, 'offline.synced', Tournament::class, $tournament->id, ['source' => 'offline', 'operation_id' => $data['id'], 'list_id' => $listId, 'command_count' => count($data['commands']), 'old_revision' => $before['revision'], 'new_revision' => $after['revision']], 'offline');
            DB::table('offline_mutations')->insert(['id' => $data['id'], 'user_id' => $actor->id, 'list_id' => $listId, 'request_hash' => $hash, 'response' => json_encode($after, JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return $after;
        }, 3);
    }
}
