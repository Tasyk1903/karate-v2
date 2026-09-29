<?php

namespace App\Services\Offline;

use App\Models\ListTournament;
use App\Models\User;
use App\Services\Tournaments\TournamentLifecycle;
use Illuminate\Support\Facades\Crypt;

final class OfflineGrant
{
    public function issue(User $user, ListTournament $list, array $snapshot): array
    {
        abort_unless(TournamentLifecycle::canManage($user, $list->tournament), 403);
        $expires = now()->addDays(7)->getTimestamp();
        $snapshot['grant'] = Crypt::encryptString(json_encode(['user' => $user->id, 'list' => $list->id, 'tournament' => $list->tournament_id, 'expires' => $expires], JSON_THROW_ON_ERROR));
        $snapshot['expires_at'] = $expires;

        return $snapshot;
    }

    public function check(User $user, ListTournament $list, string $token): array
    {
        try {
            $grant = json_decode(Crypt::decryptString($token), true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            abort(403, __('offline.grant_invalid'));
        }
        abort_unless(($grant['user'] ?? null) === $user->id && ($grant['list'] ?? null) === $list->id && ($grant['tournament'] ?? null) === $list->tournament_id && ($grant['expires'] ?? 0) > now()->getTimestamp(), 403, __('offline.grant_invalid'));

        return $grant;
    }
}
