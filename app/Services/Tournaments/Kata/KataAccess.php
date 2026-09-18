<?php

namespace App\Services\Tournaments\Kata;

use App\Models\Tournament;
use App\Models\User;
use App\Services\Tournaments\TournamentLifecycle;

final class KataAccess
{
    public static function fields(User $user, Tournament $tournament): array
    {
        if (! TournamentLifecycle::active($tournament)) {
            return [];
        }
        if (TournamentLifecycle::canManage($user, $tournament)) {
            return KataScores::FIELDS;
        }

        return JudgeKataAccess::views($user, $tournament) && in_array($user->judge_position, KataScores::FIELDS, true) ? [$user->judge_position] : [];
    }
}
