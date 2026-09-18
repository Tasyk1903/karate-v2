<?php

namespace App\Services\Tournaments;

use App\Models\Tournament;
use App\Models\User;

final class TournamentLifecycle
{
    public static function active(Tournament $tournament): bool
    {
        return ! $tournament->trashed() && $tournament->date_finish !== null
            && now()->lte($tournament->date_finish->copy()->endOfDay());
    }

    public static function commissionOpen(Tournament $tournament): bool
    {
        return self::active($tournament) && (! $tournament->date_commission || now()->lte($tournament->date_commission));
    }

    public static function organizationId(User $user): ?int
    {
        return $user->hasProjectRole('Organization') ? $user->id : $user->organization_id;
    }

    public static function owns(User $user, Tournament $tournament): bool
    {
        return $user->hasAnyProjectRole(['Organization', 'Secretary'])
            && (int) $tournament->organization_id === (int) self::organizationId($user)
            && $tournament->championship !== null
            && (int) $tournament->championship->organization_id === (int) self::organizationId($user);
    }

    public static function canManage(User $user, Tournament $tournament): bool
    {
        return self::owns($user, $tournament) && self::active($tournament);
    }
}
